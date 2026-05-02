# Fledge Changelog

All Fledge-specific changes on top of Laravel upstream. For Laravel's own changelog, see [CHANGELOG.md](CHANGELOG.md).

## v13.7.0.4 - 2026-05-02

### Fixed
- `schedule:work` Fledge subclass override now actually takes effect. The previous binding swap in `ScheduleServiceProvider::register()` ran during eager bootstrap, but `Illuminate\Foundation\Providers\ArtisanServiceProvider` is a deferred provider whose lazy registration re-bound `Illuminate\Console\Scheduling\ScheduleWorkCommand` to its own auto-resolution after the Fledge provider's `register()`. The override now happens in `boot()` by registering the Fledge subclass via `$this->commands([...])`, which queues a `Console\Application::starting` callback that runs after the framework's. Symfony Console's `Application::add()` overwrites by command name, so the Fledge subclass wins for `schedule:work`. Smoke-tested end-to-end against the `webpatser/fledge` skeleton.

## v13.7.0.3 - 2026-05-02

### Added
- `schedule:terminate` command: graceful reload analogue of `horizon:terminate` for the `schedule:work` daemon. Writes a timestamp to the `illuminate:schedule:work:terminate` cache key; the daemon compares the value against its boot timestamp on each loop iteration and exits cleanly after draining any in-flight `schedule:run` subprocesses. Closes the gap left by `schedule:interrupt`, which only short-circuits the sub-minute `repeatEvents()` loop and never the outer daemon.
- New `Fledge\` PSR-4 namespace (`src/Fledge/`) for Fledge-specific additions that should not touch upstream files. Auto-discovered via `extra.laravel.providers`, so the new `Fledge\Console\Scheduling\ScheduleServiceProvider` boots without modifications to upstream service providers.
- `php artisan reload` now also runs `schedule:terminate` via `Illuminate\Support\ServiceProvider::$reloadCommands`, no edits to `ReloadCommand`.

## v13.7.0.2 - 2026-04-30

### Added
- `laravel/pao` (^1.0.6) in `require-dev` for agent-friendly test output. Detects Claude Code, Cursor, Devin, Gemini CLI, and others, and replaces verbose PHPUnit/Pest/Paratest/PHPStan output with compact JSON. Zero impact on humans running tools directly. Upstream `laravel/framework` does not include this; the upstream `laravel/laravel` skeleton does.

## v13.7.0.1 - 2026-04-30

### Synced
- Merged upstream Laravel v13.7.0 (squashed 59 files, 902 insertions, 154 deletions)
  - New `Interruptible` queue contract and `WorkerInterrupted` event
  - `$pausable` flag on Worker for managed queues (Laravel Cloud)
  - `runningCommand` tracking in `CallQueuedHandler`
  - `ReadsClassAttributes::getAttributeInstance()` helper (used by `DebounceLock` and `PendingDispatch`)
  - SQS connector now memoizes credential providers (`CredentialProvider::memoize`)
  - `Factory::hasAttached()` adds `array_is_list` guard
  - 13,623 tests passing (up from 13,535)

### Added
- `symfony/polyfill-php86` (^1.36) - required for the new `SortDirection` enum (PHP 8.6 feature, Fledge runs on 8.5+)

### Preserved
- `Worker::listenForSignals()` - kept the Revolt fiber-safe signal branch and extended both branches to dispatch `WorkerInterrupted` and call `notifyJobOfSignal()`
- `Application::VERSION` stays a typed class constant (`const string VERSION`, PHP 8.3 typed constants)
- `FormRequest::isKnownField()` keeps `array_any()` (PHP 8.5 native)
- `PrefersJsonResponses::acceptHeaderIsBroad()` keeps `array_all()` (PHP 8.5 native)
- `FailoverStore` keeps `Fledge\Async\async` and `awaitFirst` imports

## v13.5.0.2 - 2026-04-17

### Optimized
- `Container::build()`, `Container::getScopedTyped()`, `Container::getConcreteBindingFromAttributes()`: cache `ReflectionClass` instances in a static array keyed by class name. Avoids re-reflecting the same class on every resolve. Expected 5-15% speedup on hot-path container resolves.
- `Collection::mapToDictionary()`: replaced `reset()` with `array_first()` (PHP 8.5 native, no internal pointer side effect).
- `EnumeratesValues::whereBetween()`, `EnumeratesValues::whereNotBetween()`: replaced `reset()`/`end()` with `array_first()`/`array_last()` (PHP 8.5 native).

## v13.5.0.1 - 2026-04-16

### Synced
- Merged upstream Laravel v13.5.0
  - Full Redis Cluster support (`isCluster()`, `hasHashTag()`, normalized SSL context)
  - Enum support for Manager drivers (`AuthManager`, `CacheManager`, `MailManager`, `RedisManager`, etc.)
  - `#[Delay]` attribute on queued mailables
  - `RebindsCallbacksToSelf` trait for safer closure binding in managers
  - FormRequest strict mode with `flushState()` for test isolation
  - Controller middleware attribute inheritance
  - Various bug fixes (validation null handling, Str::markdown null input, queue job attributes)
  - 13,429 tests passing

### Removed
- `spatie/fork` suggestion (Fledge uses native Fiber concurrency)
- `league/uri` suggestion (Fledge uses native PHP 8.5 URI)

### Fixed
- Replaced remaining `amphp` references with `webpatser/fledge-fiber`

## v13.4.0.2 - 2026-04-08

### Optimized
- `FormRequest::isKnownField()` — replaced `foreach` early-return with native `array_any()` (PHP 8.4+)
- `Handler::shouldntReport()` — replaced `!is_null(Arr::first(...))` with native `array_any()` (PHP 8.4+)

## v13.4.0.1 - 2026-04-08

### Synced
- Merged upstream Laravel v13.4.0
  - New `FailOnUnknownFields` attribute for FormRequest
  - New `Delay` attribute for queue jobs
  - Queue job inspection (`pendingJobs`, `delayedJobs`, `reservedJobs`)
  - Safer Closure binding in `RedisManager::extend()`, `LogManager::extend()`
  - `is_string()` guards on 9 validation methods
  - Refactored `Handler::unauthenticated()` — returns 401 when no redirect configured
  - 13,382 tests passing (4 known Predis failures)

## v13.3.0.8 - 2026-04-07

### Added
- Updated docs with `ConcurrentMiddlewareGroup`

## v13.3.0.7 - 2026-04-06

### Added
- Full fiber ecosystem: fledge-fiber-based database, Redis, HTTP, and DNS drivers

## v13.3.0.3 - 2026-04-06

### Added
- **fledge-fiber as default Redis driver** — all Redis I/O non-blocking by default
- **Fiber-aware cache layer** — locks, failover, tags use Fiber suspension
- Redis as required cache dependency

## v13.3.0.2 - 2026-04-06

### Added
- `FiberDriver` for Concurrency facade (Revolt event loop + fledge-fiber)
- `ConcurrentMiddlewareGroup` for parallel middleware execution
- `SuspendsFibers` trait for cache internals

## v13.3.0.1 - 2026-01-30

### Initial Release
- Native `Uri\Rfc3986\Uri` replacing `league/uri` (~100x faster URI ops)
- RFC 3986 normalization layer (IDN, unicode paths, bracket encoding)
- `array_all()`/`array_any()` in `Arr::hasAll`/`hasAny`
- Pipe operator `|>` in `Pipeline::then()`
- `#[\NoDiscard]` on Pipeline, Cache, Container, Validation
- Persistent cURL share manager for HTTP connection pooling
- `json_validate()` fast path
- Removed `symfony/polyfill-php84`, `polyfill-php85`, and `version_compare` guards
- Bumped all `composer.json` to `"php": "^8.5"`
