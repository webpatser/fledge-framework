# Fledge Changelog

All Fledge-specific changes on top of Laravel upstream. For Laravel's own changelog, see [CHANGELOG.md](CHANGELOG.md).

## v13.14.0.1 - 2026-06-09

### Synced
- Merged upstream Laravel v13.13.0 -> v13.14.0 (20 source files changed, 634 insertions, 47 deletions). Three Fledge-modified files were also touched upstream; only `Foundation/Application.php` (VERSION constant) plus the `Http/Client/Factory.php` and `PendingRequest.php` header-normalization match arms produced real conflicts, all small:
  - `Http/Client/Factory.php` + `PendingRequest.php`: upstream added `null => ''` arms to the header / multipart / fake-response normalization `match` expressions (null header values now coerce to an empty string instead of throwing). Fledge's persistent-cURL share manager and global-handler fallback merged alongside untouched.
  - New upstream file accepted as-is: `JsonSchema/Deserializer.php` (524 lines); `JsonSchema/JsonSchema.php` gained the matching deserialize entry point.
  - `Support/Traits/ReadsClassAttributes.php`, `Queue/RedisQueue.php`, `Queue/Jobs/InspectedJob.php`, `Support/Testing/Fakes/QueueFake.php`: upstream's child-property-overrides-attribute logic and `InspectedJob::fromPayload(queue:)` threading merged disjoint from Fledge's optimizations.
  - Upstream renamed `Foundation/LaravelCloudJsonFormatter` -> `Foundation/Cloud/JsonFormatter` (and its test); rename adopted.

### Preserved
- `Application::VERSION` stays a typed class constant (`const string VERSION`), bumped to `13.14.0`.
- Native URI (`Uri\Rfc3986\Uri`), persistent cURL, `Arr` `array_all`/`array_any`, and the pipe-operator `Pipeline` all carried through the merge untouched. No `league/uri` or `symfony/polyfill-php8[45]` reintroduced.

### Optimized
- None. The merged upstream code is already idiomatic for PHP 8.5: the new `null => ''` arms slot into existing `match` expressions, and the new `JsonSchema/Deserializer` / `RedisQueue` / `ReadsClassAttributes` loops are stateful accumulation/transformation (carry-over enum inference, union-branch collapsing, reflection walks) that do not map to `array_any`/`array_all`/`array_find`. `in_array()` uses are membership checks without callbacks.

## v13.13.0.2 - 2026-06-04

### Fixed
- `Database/Schema/MySqlSchemaState.php`: removed the `@phpstan-ignore classConstant.notFound` comment that upstream v13.13.0 added above the `Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT` check. Upstream's PHPStan CI runs without the `pdo_mysql` extension loaded, so the constant is unresolved there and the ignore is needed. Fledge requires PHP 8.5 with `pdo_mysql`, where `Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT` resolves cleanly, making the ignore unmatched and tripping `ignore.unmatchedIdentifier` under our stricter static-analysis config (the "Source Code" job). Removing the dead ignore is the correct resolution; both `phpstan.src` and `phpstan.types` are green.

## v13.13.0.1 - 2026-06-04

### Synced
- Merged upstream Laravel v13.12.0 -> v13.13.0 (28 source files changed, 407 insertions, 44 deletions). Eight Fledge-modified files were also touched upstream; only `Foundation/Application.php` (VERSION constant) and `Http/Response.php` (constructor) produced real conflicts, the other six auto-merged cleanly:
  - `Http/Client/PendingRequest.php` + `Factory.php`: upstream added header/multipart normalization helpers (`normalizeHeaderValues()`, `normalizeMultipartOption()`, `normalizeResponseHeaders()`); Fledge's persistent-cURL share manager and global-handler fallback merged alongside untouched
  - `Bus/Dispatcher.php`: new `bulk()` method grouping queued jobs by connection/queue
  - `Notifications/Messages/MailMessage.php`: new `attachFromStorage()` / `attachFromStorageDisk()` helpers
  - `Console/Scheduling/Schedule.php`: new `$pausable` / `$interruptible` static toggles + `withoutInterruptionPolling()`; Fledge's typed day constants (`const int SUNDAY`) preserved
  - `Container/Attributes/Cache.php`: new `memo` flag on the contextual attribute
  - `Mail/Mailable.php`, `Foundation/Exceptions/Handler.php`, `Validation/Concerns/ValidatesAttributes.php`: upstream logic merged disjoint from Fledge's `array_is_list` / first-class-callable / dynamic class-const optimizations

### Changed
- `Http/Response.php`: **adopted upstream's official fix** for the symfony 8.1 `ResponseHeaderBag` property-hook deprecation, retiring the bespoke Fledge fix from v13.12.0.2. The constructor now guards on `method_exists($this, 'setHeaders')`, passing a `ResponseHeaderBag` through `parent::__construct()` on symfony 8.1+ and falling back to direct assignment on older releases. Restores the `ResponseHeaderBag` import. Functionally equivalent to the prior Fledge fix but tracks upstream going forward, removing a divergence.

### Preserved
- `Application::VERSION` stays a typed class constant (`const string VERSION`), bumped to `13.13.0`
- Native URI (`Uri\Rfc3986\Uri`), persistent cURL, `Arr` `array_all`/`array_any`, and the pipe-operator `Pipeline` all carried through the merge untouched

### Optimized
- None. The merged upstream code is already idiomatic for PHP 8.5: the new code is grouping/transformation `foreach` loops (header normalization, bulk job grouping) that do not map to `array_any`/`array_all`/`array_find`, `Cache.php` already uses typed constructor promotion, and the new `Schedule::$pausable`/`$interruptible` statics match the existing untyped static-property style of that file

### Known failures
- Pre-existing environment-gated skips only (DB/Redis-requiring integration tests). Full suite green under `--fail-on-deprecation`; the symfony 8.1 deprecation failure resolved in v13.12.0.2 stays resolved under upstream's fix

## v13.12.0.2 - 2026-05-30

### Fixed
- `Http/Response.php`: `symfony/http-foundation 8.1` added a PHP 8.4 property hook that deprecates direct writes to the `Response::$headers` property. Laravel's constructor assigned `$this->headers = new ResponseHeaderBag(...)` directly, tripping the deprecation (a hard failure under CI's `--fail-on-deprecation`). The constructor now passes the headers **array** through `parent::__construct()`; Symfony builds the `ResponseHeaderBag` via its internal `setHeaders()`, avoiding the deprecation. The array is passed as-is (not pre-wrapped in a `ResponseHeaderBag`) so older symfony releases, whose constructor types the third argument as `array`, still accept it (`prefer-lowest` CI stays green). Resolves the `ExceptionsFacadeTest::testWithoutDeprecationHandler` failure noted under v13.12.0.1. Full suite green under `--fail-on-deprecation`.

## v13.12.0.1 - 2026-05-30

### Synced
- Merged upstream Laravel v13.11.2 -> v13.12.0 (58 files, 396 insertions, 175 deletions)
  - `Http/Client/PendingRequest.php`: retry handling reworked to support array-based per-attempt delays via new `getMaximumAttempts()` and `retryDelayInMilliseconds()` helpers; Fledge's persistent-cURL share manager merged cleanly alongside
  - `Queue/Worker.php`: new `$stopOnLostConnection` static toggle gating `stopWorkerIfLostConnection()`
  - `Console/Scheduling/ManagesAttributes.php` + `PendingEventAttributes.php`: scheduled events gained arbitrary `withAttributes()` storage, merged into the event in `Schedule::mergePendingAttributes()` (reordered to apply pending attributes before group attributes)
  - `Redis/Connectors/PhpRedisConnector.php` + `PredisConnector.php`: `formatHost()` now validates the host scheme and throws `InvalidArgumentException` on an empty host or a scheme/host mismatch
  - New files: `Contracts/Events/ShouldBeDiscovered.php`, `Foundation/Cloud/ManagedQueueNotFoundException.php`
  - Widespread `compact()` -> array-literal cleanups (Mail, FormRequest, `Database/Connection::logQuery()`), already matching Fledge style

### Preserved
- `Application::VERSION` stays a typed class constant (`const string VERSION`), bumped to `13.12.0`
- Native URI (`Uri\Rfc3986\Uri`), persistent cURL, `Arr` `array_all`/`array_any`, and the pipe-operator `Pipeline` all carried through the merge untouched

### Optimized
- None. The merged upstream code is already idiomatic for PHP 8.5; the new `Worker::$stopOnLostConnection` matches the existing untyped static-bool style of its siblings (`$reportJobExceptions`, `$restartable`, `$pausable`), and the new `PendingRequest` retry helpers are already clean

### Known failures
- `ExceptionsFacadeTest::testWithoutDeprecationHandler`: `symfony/http-foundation v8.1.0` added a deprecation for directly setting the `headers` property of `Illuminate\Http\Response`. **Fixed in v13.12.0.2** by routing the header bag through `parent::__construct()`
- Pre-existing dependency-drift failures remain (symfony/mime address wording, Predis connection tests)

## v13.11.2.1 - 2026-05-21

### Synced
- Merged upstream Laravel v13.11.1 -> v13.11.2 (4 files, 12 insertions, 8 deletions)
  - `Console/Scheduling/Schedule.php`: `__call()` now also defers methods listed in `PendingEventAttributes::DEFERRED_EVENT_METHODS`
  - `Console/Scheduling/PendingEventAttributes.php`: `DEFERRED_EVENT_METHODS` visibility raised `protected` -> `public`
  - `Foundation/Cloud.php`: `bootManagedQueues()` moved to `bootstrapperBootstrapping()` and now keys off the `queue.connections.cloud.driver` config instead of the `LARAVEL_CLOUD_MANAGED_QUEUES` env var
  - 13,105 framework tests passing (685 skipped, 1 known dependency failure, see below)

### Optimized
- `PendingEventAttributes::DEFERRED_EVENT_METHODS` is now a typed class constant (`public const array`), matching Fledge's existing typed-constant style in the same scheduling area (`Schedule::SUNDAY` etc.)

### Preserved
- `Application::VERSION` stays a typed class constant (`const string VERSION`), bumped to `13.11.2`
- `Schedule` weekday constants stay typed (`const int SUNDAY` ... `SATURDAY`); upstream's new `__call()` deferral merged cleanly alongside them

### Known failures
- `MailMailerTest::testMailerRejectsSymfonyAddressesContainingLineBreaks` asserts on Symfony's internal exception text. `symfony/mime v8.0.12` reworded it from "Email addresses may not contain line break characters." to "Email address contains control characters." This is a Symfony dependency change, not a Fledge or Laravel regression; the upstream test will need updating.

## v13.11.1.1 - 2026-05-20

### Synced
- Merged upstream Laravel v13.9.0 -> v13.11.1 (80 files, 2,531 insertions, 298 deletions; covers 13.10.0, 13.11.0, 13.11.1)
  - New `Cache/StorageStore.php` cache store
  - New `Queue/Events/WorkerIdle.php` event; `Worker` gained the `stopWhenEmptyFor` option, a batched `getPausedQueues()` lookup (replaces per-queue `queuePaused()`), and a `currentTime()` helper
  - New `Foundation/LaravelCloudJsonFormatter.php`
  - `ValidatesAttributes`: `validateEmail()` now rejects CR/LF; `validateStartsWith()` / `validateEndsWith()` (and their `Doesnt*` variants) accept numeric values
  - `Mail/Message.php` address-safety hardening
  - 13,788 framework tests passing

### Changed
- `Queue\Worker::listenForSignals()` updated for the new upstream `$options` parameter. Fledge's Revolt/pcntl signal branching is kept; the `WorkerOptions` instance is now threaded through every signal closure and into every `WorkerInterrupted` / `WorkerPausing` / `WorkerResuming` dispatch.

### Preserved
- `Application::VERSION` stays a typed class constant (`const string VERSION`), bumped to `13.11.1`

## v13.9.0.1 - 2026-05-14

### Synced
- Merged upstream Laravel v13.9.0 (57 files, 1,350 insertions, 118 deletions)
  - New `Foundation/Cloud/` queue integration (`Events`, `FailedJobProvider`, `Queue`, `QueueConnector`) and `Contracts/Queue/PreparesForDispatch` contract
  - Concurrency `Driver::run()` contract gained a `CarbonInterval|int|null $timeout` parameter
  - `Worker::$timedOutExitCode` static property, used by the timeout handler
  - `Http\Client\PendingRequest::parseMultipartBodyFormat()` refactored (`flatMap` to `map`); `guzzlehttp/psr7` bumped to `^2.9`
  - `ValidatesAttributes` dropped the unused `$parameters` argument from `validateLowercase()` / `validateUppercase()`
  - 13,727 framework tests passing

### Changed
- `Concurrency\FiberDriver::run()` updated to match the new `Driver` contract signature. The `$timeout` parameter is honored via `Fledge\Async\TimeoutCancellation` (`CarbonInterval` converted through `->totalSeconds`, `int` taken as seconds).

### Preserved
- `Application::VERSION` stays a typed class constant (`const string VERSION`, PHP 8.3 typed constants)
- `PendingRequest` persistent cURL share handle support kept intact through the upstream multipart refactor
- `Worker::registerTimeoutHandler()` keeps the Fledge SIGALRM / Revolt fiber-safe signal handling; upstream's `$timedOutExitCode` lookup was integrated into the Fledge closure
- `composer.json` keeps `laravel/pao` in `require-dev`; accepted the upstream `guzzlehttp/psr7 ^2.9` bump

## v13.8.0.1 - 2026-05-08

### Synced
- Merged upstream Laravel v13.8.0 (62 files, 854 insertions, 161 deletions)
  - `SortDirection` enum support across query builder classes
  - `all*` queue inspection methods and enum support for `QueueFake::assertPushedOn()`
  - Environment filter for the `schedule:list` command
  - Mail default driver accepts enums; custom on delete/update strings for foreign keys

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
