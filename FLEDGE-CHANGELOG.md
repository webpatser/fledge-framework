# Fledge Changelog

All Fledge-specific changes on top of Laravel upstream. For Laravel's own changelog, see [CHANGELOG.md](CHANGELOG.md).

## v13.30.1.2 - 2026-09-02

### Optimized
- `Foundation/Cloud/Queue.php`: the three new `totalPendingSize()`/`totalDelayedSize()`/`totalReservedSize()` aggregates dropped the `new Collection(...)->sum(closure)` wrapper for `array_sum(array_map($this->pendingSize(...), ...))`, using first-class callables and removing a Collection allocation per call on what Cloud polls on a loop. The `Illuminate\Support\Collection` import went with it.
- `Filesystem/FilesystemAdapter.php`: upstream's new `Storage::path()` root confinement allocated a `WhitespacePathNormalizer` on every call; the normalizer is stateless, so it now lives in a shared `static $pathNormalizer` populated with `??=`. `path()` is a hot local-disk call.
- Nothing else qualified: upstream added no untyped class constants this cycle (`Application::VERSION` stays the typed Fledge constant), `WorkerStopReason::description()` is already a `match`, `SqlServerConnector::escapeSqlSrvDsnValue(...)` and `Cloud\Events::connect()` already use first-class callables, and the three `foreach` loops in the new `ParsesSqlServerConfigurationUrls` trait all build transformed maps rather than search for a single element, so `array_find`/`array_any` do not apply.

## v13.30.1.1 - 2026-09-02

### Synced
- Merge upstream Laravel v13.29.0 -> v13.30.1 (42 commits, 73 files; 1,425 insertions, 67 deletions). Headline upstream changes: the queue worker stop reason now surfaces in `queue:work` output (`Worker::stop()` and the `WorkerStopping` event both gain `$connectionName`/`$queue`, plus `WorkerStopReason::description()`); `DevCommands::withoutVendorCommands()`/`withoutDefaultCommands()` opt-outs; `Storage::path()` confined to the configured disk root via Flysystem's `WhitespacePathNormalizer`; Microsoft SQL Server DSN connection strings (new `Support/Traits/ParsesSqlServerConfigurationUrls.php` trait wired into `ConfigurationUrlParser`, plus connection-string escaping in `SqlServerConnector`); `route:cache` restoring the facade application after booting a fresh one; `Collection::chunkBy()`; `dropVectorIndex()` on the Blueprint and the Postgres/MariaDB grammars; `spl_object_id` replacing `spl_object_hash` in the container, global scopes and `Onceable`; `RedisTaggedCache` only writing tag entries after the underlying store write succeeds; `Kernel::findCommand()`; `QueueManager::createPayloadUsing()`; `Handler::contextForException()`; a bool return and injectable socket factory on Cloud `Events::emit()`; a quadratic wildcard-rule expansion fix in validation; and a recaller hash check in `SessionGuard`.
- Conflicts: 44 files conflicted off the ancient squash base; 23 outside the upstream delta kept from Fledge, 16 inside the delta but untouched by Fledge taken from upstream wholesale, 5 hand-merged:
  - `Foundation/Application.php`: keep the typed VERSION constant, bumped to `13.30.1`.
  - `Cache/RedisTaggedCache.php`: upstream's write-then-tag reordering taken across `add`/`put`/`increment`/`decrement`/`forever`; the `SuspendsFibers` trait, the polymorphic `$connection->getPrefix()` in `flush()`, and the fiber-concurrent `flushValues()`/`deleteChunk()` kept. The auto-merge left the old pre-call `addEntry()` in `decrement()` next to upstream's new post-call one, which would have double-counted every decrement; the stale copy was removed.
  - `Foundation/DevCommands.php`: upstream's vendor/default command opt-outs taken; Fledge's `array_find()` in `resolveSource()` kept.
  - `Foundation/Exceptions/Handler.php`: upstream's new `contextForException()` auto-merged; the first-class-callable conversions and `array_any` in `shouldStopRetries()`/`shouldntReport()` kept.
  - `Queue/Worker.php`: upstream's wider `stop()` signature and its two `daemon()` call sites taken, along with the `int|float $startTime` docblock; the typed `EXIT_*` constants, `$signalWatchers`, the Revolt `listenForSignals()`/`sleep()` implementations and the pcntl SIGALRM rationale comment kept.
- Squash-merge artifacts (the usual suspects): `install-nightly.yml` and `databases-nightly.yml` both resurrected (re-deleted), the `permissions:` block in `tests.yml` duplicated again (ninth sync in a row), the typed `MAX_MESSAGES_PER_BATCH` in `SqsQueue.php` duplicated by an untyped upstream copy, and the stale `@phpstan-ignore` in `MySqlSchemaState.php` resurrected again. All four were caught by a systematic sweep comparing the merged tree against `fledge-13` for files outside the upstream delta, which is a faster check than waiting for phpstan or the test suite to trip over them.
- A rename/rename conflict on the test enum fixtures (`tests/Integration/Database/Enums.php` renamed on both sides) cross-wired the namespaces of `tests/Database/Fixtures/Enums/Enums.php` and `tests/Integration/Database/Fixtures/Enums.php`; both were restored from upstream, where the two layouts are already identical.
- Tests: 14,834 passing (15,377 total, 539 skipped); only the four known-environmental `RedisConnectionTest::*scansForKeys` cursor errors remain. Both phpstan configs clean. All 7 GitHub workflows green on the sync commit before tagging.

### Preserved
- Native URI (`Uri\Rfc3986\Uri`), persistent cURL, `Arr` `array_all`/`array_any`, and the pipe-operator `Pipeline` all carried through. No `league/uri` or `symfony/polyfill-php8[45]` reintroduced.

## v13.29.0.2 - 2026-08-27

### Optimized
- `Queue/AwsCredentialCache.php`: typed the three new class constants (`const int REFRESH_WINDOW`/`LOCK_SECONDS`/`LOCK_WAIT_SECONDS`), matching the typed-constant pattern used across Fledge.
- Nothing else qualified: `AsVector` already uses first-class callables and named arguments, `NotificationSkipped` uses promoted constructor properties, `CloudManager` is thin macro plumbing, and the new `FormRequest::dotInputKeys()` / `QueueFake::totalPendingSize()` additions carry no replaceable loop shapes.

## v13.29.0.1 - 2026-08-27

### Synced
- Merge upstream Laravel v13.26.1 -> v13.29.0 (63 commits, 329 files; 326 files changed in the squash, 4,186 insertions, 622 deletions). Note: upstream tagged v13.28.0 directly on a merge commit with no release notes, and v13.29.0 adds only the version-bump commit on top of it, so the v13.29.0 GitHub release looks empty; everything landed in v13.27.0/v13.28.0. Headline upstream changes: an AWS credential cache for SQS queues (`AwsCredentialCache`, cross-process refresh locking); `Model::refreshForUpdate()`; a `NotificationSkipped` event; the `AsVector` Eloquent cast plus a MariaDB vector-distance SQL fix; a macroable `CloudManager` with `Cloud` facade docblocks; opt-in query-binding masking in `QueryException` (`mask_bindings_in_exception_messages`); Postgres keepalive DSN options; PhpRedis `mget`/`hmget` false-guards; `FormRequest` unknown-field validation now escaping literal dots in keys; scoped singleton dedup in the container; and strict `in_array` in `validateDoesntContain`.
- Conflicts: 76 files conflicted off the ancient squash base; 17 outside the upstream delta kept from Fledge, 46 inside the delta but untouched by Fledge taken from upstream wholesale, 7 hand-merged:
  - `Foundation/Application.php`: keep the typed VERSION constant, bumped to `13.29.0`.
  - `Queue/SqsQueue.php`: upstream's new `totalPendingSize()`/`totalDelayedSize()`/`totalReservedSize()` taken; the auto-merge duplicated `MAX_MESSAGES_PER_BATCH` again (typed Fledge constant + upstream's untyped addition), caught by the test suite and de-duplicated.
  - `Redis/Connections/PhpRedisConnection.php`: kept the typed `RETRYABLE_COMMANDS` constant; upstream's `mget`/`hmget` guards auto-merged.
  - `Validation/Concerns/ValidatesAttributes.php`: upstream's strict `in_array` taken into Fledge's `array_all` form of `validateDoesntContain`.
  - `composer.json`: rector pinned to `2.6.3` and the test fixture path move taken; PHP 8.5 pins, polyfill removals, no `league/uri`, fledge-fiber and `laravel/pao` kept.
  - `.github/workflows/databases.yml` / `redis.yml`: upstream's timeout bumps and the retry-wrapped Redis cluster setup taken onto the Fledge matrices.
- Squash-merge artifacts (the usual suspects): `install-nightly.yml` resurrected (re-deleted), the `permissions:` block in `tests.yml` duplicated again (eighth sync in a row, this time caught locally), the stale `@phpstan-ignore` in `MySqlSchemaState.php` resurrected again (re-deleted, caught by phpstan).
- Tests: 14,778 passing (15,318 total, 536 skipped); only the four known-environmental `RedisConnectionTest::*scansForKeys` cursor errors remain. Both phpstan configs clean. All 7 GitHub workflows green on the sync commit before tagging.

### Preserved
- Native URI (`Uri\Rfc3986\Uri`, including upstream's `data_set` -> `Arr::set` swap in `withQuery()` carried into the Fledge implementation), persistent cURL, `Arr` `array_all`/`array_any`, and the pipe-operator `Pipeline` all carried through. No `league/uri` or `symfony/polyfill-php8[45]` reintroduced.

## v13.26.1.2 - 2026-08-19

### Optimized
- `Redis/Connections/PhpRedisConnection.php`: typed the new `RETRYABLE_COMMANDS` constant (`const array`), matching the typed-constant pattern used across Fledge.
- `Queue/QueueRoutes.php`: `getRoute()` foreach-with-early-return -> `array_find()` over the class/parent/interface/trait list, matching the `array_find` usage in `DevCommands` and `NodePackageManager`.
- Nothing else qualified: `Worker::raisePausedQueueEvents()` is already minimal `array_diff` work, the Guzzle 7/8 `responseFromException()` shim in `PendingRequest` deliberately uses runtime capability checks, and the new `ReadThroughFilesystem`/`FluentPromise`/`LazyPromise` classes already use promoted constructor properties and first-class callables.

## v13.26.1.1 - 2026-08-19

### Synced
- Merge upstream Laravel v13.25.0 -> v13.26.1 (62 commits, 113 files; 112 files changed in the squash, 3,634 insertions, 367 deletions). Headline upstream changes: Guzzle 8 support (constraints widened to `guzzlehttp/guzzle ^7.8.2 || ^8.0` with `promises ^3`, `psr7 ^3`, `uri-template ^2`, and the `PendingRequest` exception marshaling rewritten around `responseFromException()` / `marshalTransportException*()` to cover Guzzle 8's `ResponseException`); a PhpRedis command-retry mechanism (`RETRYABLE_COMMANDS` allow-list, `command_retries` config, retry loop on dropped connections, `discard()` cleanup when `pipeline()`/`transaction()` callbacks throw); `WorkerQueuePaused`/`WorkerQueueResumed`/`JobReleased` queue events; a read-through filesystem driver (`ReadThroughFilesystem` + adapter); process idle timeouts (`ProcessIdleTimedOutException`); lazy/fluent HTTP client promises; `throwUnless()` accepting callables; an `expandUrlParameters()` fast path; and `artisan dev` refinements (unpinned `@laravel/multiplex`, Pail/`package.json` presence checks).
- Conflicts: 67 files conflicted off the ancient squash base; 15 outside the upstream delta kept from Fledge, 43 inside the delta but untouched by Fledge taken from upstream wholesale, 9 hand-merged:
  - `Foundation/Application.php`: keep the typed VERSION constant, bumped to `13.26.1`.
  - `Http/Client/PendingRequest.php`: upstream's Guzzle 8 marshaling rewrite taken (only the `ResponseInterface` import conflicted); persistent-cURL wiring lives in `Factory`/`PersistentCurlShareManager` and carried through untouched.
  - `Queue/SqsQueue.php`: upstream's `resolveQueue()` calls in `getQueueableOptions()`/`getQueue()` taken; Fledge's typed constants kept (the auto-merge duplicated `MAX_MESSAGES_PER_BATCH`, caught by the test suite and de-duplicated).
  - `Cache/RedisTagSet.php`: kept the fiber-concurrent `addEntry()`; upstream's stringified cursor comparison in `entries()` auto-merged.
  - `Cache/RedisStore.php`: upstream's new `flushStaleTags` cursor test mocks `_prefix('')` directly, so `currentTags()` returns to upstream's per-client prefix `match` with Fledge's polymorphic `$connection->getPrefix()` as the default arm for fiber connections.
  - `Foundation/Console/DevCommand.php` / `Foundation/DevCommands.php`: upstream's multiplex unpinning and Pail/`package.json` guards taken; Fledge's `array_find()` in `resolveSource()` kept.
  - `composer.json` (root + Http package): Guzzle 8 constraint widening and `psr/http-message` promotion to `require` taken; PHP 8.5 pins, polyfill removals, no `league/uri`, fledge-fiber and `laravel/pao` kept.
  - `.github/workflows/databases.yml` / `tests.yml`: upstream's MySQL 8.4 + 9.7 matrix and 9.7 image bump taken onto the PHP 8.5-only Fledge matrices.
- Squash-merge artifacts (the usual suspects): `install-nightly.yml` resurrected (re-deleted), the `permissions:` block in `tests.yml` duplicated again and caught by CI (restored, seventh sync in a row), the stale `@phpstan-ignore` in `MySqlSchemaState.php` resurrected again (re-deleted).
- Tests: 14,550 passing (15,085 total, 526 skipped). Besides the four known-environmental `RedisConnectionTest::*scansForKeys` cursor errors, four failures from dev-dependency drift appeared (`DatabaseEloquentBelongsToManyCreateOrFirstTest` partial-mock constructor with current Mockery, `DatabaseMigrationRefreshCommandTest` x3 on symfony/console's uninitialized `$defaultCommand`); all four reproduce identically on a pristine v13.25.0.2 worktree with fresh dependencies and on upstream laravel/framework's own 13.x CI (red since 2026-08-19). Root cause: Mockery 1.6.13/1.6.14 (released 2026-08-15/18) broke partial-mock constructors; `mockery/mockery` is capped at `^1.6.10 <1.6.13` in require-dev until a fixed release lands. With the cap, the suite is fully green except the four known cursor errors. Both phpstan configs clean.

### Preserved
- Native URI (`Uri\Rfc3986\Uri`), persistent cURL, `Arr` `array_all`/`array_any`, and the pipe-operator `Pipeline` all carried through. No `league/uri` or `symfony/polyfill-php8[45]` reintroduced.

## v13.25.0.2 - 2026-08-17

### Optimized
- `Foundation/DevCommands.php`: `resolveSource()` foreach-with-early-return -> `array_find()` with a `?? []` fallback, matching the `array_find` usage in `NodePackageManager` and `InterventionDriver`.
- `Foundation/Console/DevCommand.php`: typed the new `MULTIPLEX_VERSION` constant (`const string`).
- Nothing else qualified: the `artisan dev` machinery is cold-path console code that already leans modern (enums, first-class callables), `Str::substrReplace()`'s new array handling is closure-driven, and the `DatabaseQueue`/`CallQueuedHandler` after-commit changes carry no replaceable loop shapes.

## v13.25.0.1 - 2026-08-17

### Synced
- Merge upstream Laravel v13.24.0 -> v13.25.0 (449 files, 10,576 insertions, 9,096 deletions; 54 of them under `src/`). Headline upstream changes: the new `artisan dev` command suite (`DevCommands` registry with priorities and backtrace-resolved sources, `DevCommandMode` enum for tabs/stream/inline output, `Console/DevCommand` orchestrating via `@laravel/multiplex`); queue pause/resume controls with `QueuesPaused`/`QueuesResumed`/`UniqueJobSkipped` events; `Http::withoutGlobalConfiguration()` for callback-scoped middleware/option suppression; `\UnitEnum` queue names across `SqsQueue` via `enum_value()` (and `partitionJobsByAfterCommit()` hoisted out); after-commit-aware bulk inserts in `DatabaseQueue`; `Image` driver `dimensions()`; array-aware `Str::substrReplace()`; `Response::withoutCookies()`; the HTTP retry callback now receiving the request method; a stricter stream-resource check in `Factory::psr7Response()`; and `brick/math ^0.19`.
- Conflicts: 121 files conflicted off the ancient squash base; 97 non-Fledge files taken from upstream wholesale, 24 Fledge-modified files hand-merged:
  - `Foundation/Application.php`: keep the typed VERSION constant, bumped to `13.25.0`.
  - `Http/Client/Factory.php`: upstream's `withoutGlobalConfiguration()` and the stream-resource `psr7Response()` check taken; persistent-cURL wiring (`persistentConnections()`, `newPendingRequest()` hook) and the fiber `globalHandler` API kept. `Facades/Http.php` docblock merged for both sides.
  - `Queue/SqsQueue.php`: upstream's `enum_value()` refactor taken wholesale, Fledge's three typed constants re-applied.
  - `Image/Drivers/InterventionDriver.php`: upstream's `dimensions()` taken, Fledge's `array_find()` in `transformationHandlerFor()` re-applied.
  - `Collections/Arr.php`: upstream's `prependKeysWith()` docblock generics taken, the merged `hasAny()` guard kept.
  - `composer.json` (root + Database/Image/Log/Pipeline/Validation packages): `brick/math ^0.19` taken; PHP 8.5 pins, polyfill removals, no `league/uri`, fledge-fiber and `laravel/pao` kept.
  - `RedisTagSet`, `Exceptions/Handler`, `Queue/Console/ClearCommand`, `NodePackageManager(s)`, `Facades/Request`: outside the upstream delta, Fledge's versions kept.
- Squash-merge artifacts (tree-level drift audit clean afterwards): `install-nightly.yml` resurrected (re-deleted), the `permissions:` block in `tests.yml` duplicated again and caught by CI (restored, sixth sync in a row), the stale `@phpstan-ignore` in `MySqlSchemaState.php` resurrected again (re-deleted), and `CacheFailoverStoreTest` broke because upstream renamed `use Mockery as m;` to `use Mockery;` while the auto-merge kept Fledge's fiber-concurrency tests calling `m::mock()` (aligned to `Mockery::`).
- Tests: 14,455 passing (14,985 total, 523 skipped). Only the four known-environmental `RedisConnectionTest::*scansForKeys` "ERR invalid cursor" errors remain. Both phpstan configs clean.

### Preserved
- Native URI (`Uri\Rfc3986\Uri`), persistent cURL, `Arr` `array_all`/`array_any`, and the pipe-operator `Pipeline` all carried through. No `league/uri` or `symfony/polyfill-php8[45]` reintroduced.

## v13.24.0.1 - 2026-08-04

### Synced
- Merge upstream Laravel v13.23.0 -> v13.24.0 (381 files, 7,398 insertions, 1,674 deletions; 192 of them under `src/`). Headline upstream changes: a new `array_keys` validation rule (`Rules\ArrayKeys` with enum support, `validateArrayKeys`, and an `:unexpected` message placeholder); owner-aware unique job locks (`UniqueLock::acquire()` stamps `uniqueLockOwner` on Queueable jobs so `release()` can `restoreLock()->release()` instead of `forceRelease()`, with `CallQueuedHandler::uniqueJobLockShouldBeReleased()` guarding retries); a big console sweep migrating commands from `$name` + `getArguments()`/`getOptions()` to `$signature`; memoized route-name caches in `CompiledRouteCollection` (`routeNamesByMethod`/`routeNameByAction`) so `get()` and `getByAction()` no longer materialize every route; Unicode-safe case-insensitive `Str::replace()`/`Str::remove()` via `replaceWhileIgnoringCase()`; HEIC encoding, avif/heic/heif input support, and dominant-color sampling in the image drivers; circular container alias detection (`getAlias()` now throws `LogicException` instead of recursing forever); `Relation::noConstraintsForRelation()` for nested relation attributes; and `laravel/prompts ^0.3.11`.
- Conflicts in Fledge-modified files, all resolved small:
  - `Foundation/Application.php`: keep the typed VERSION constant, bumped to `13.24.0`.
  - `Collections/Arr.php`: dropped the redundant `$keys === []` re-check upstream still carries in `hasAny()` (Fledge's merged guard covers it); upstream's `forget()` reset-ordering fix taken. Upstream now ships `array_any()` in `hasAny()` itself, matching Fledge's earlier optimization.
  - `Foundation/Exceptions/Handler.php`: kept the first-class-callable and `array_any()` forms in `dontRetryWhen()`/`shouldStopRetries()`; upstream's `Collection::diff()` rewrite of `stopIgnoring()` taken.
  - `Queue/Console/ClearCommand.php`: kept the pipe-operator queue-name parsing from v13.22.0.2 while adopting upstream's `$signature` migration and dropped `getArguments()`/`getOptions()`; the now-unused `Stringable` import removed.
  - `Cache/RedisTagSet.php`: kept the fiber-concurrent `addEntry()` (`async`/`await` over `zaddEntry()`); upstream's hoisted `$connection`/`$prefix` locals in `entries()` and `flushStaleEntries()` taken.
  - `Container/Container.php`: upstream's `BindWhen` re-check in `getConcreteBindingFromAttributes()` and the new loop-based `getAlias()` taken; Fledge's `ReflectionClass` cache carried through.
  - `Image/*`: upstream's HEIC/dominant-color rewrite taken wholesale, Fledge's `array_find()` in `transformationHandlerFor()` and the typed `DEFAULT_QUALITY` constant re-applied.
  - `composer.json` (root + Image/Log/Pipeline packages): `laravel/prompts ^0.3.11` and the new `illuminate/conditionable`/`macroable` package deps taken; PHP 8.5 pins, no `league/uri`, fledge-fiber refs kept.
  - `Queue/SqsQueue.php`: dropped the untyped `MAX_MESSAGES_PER_BATCH` duplicate the auto-merge re-added next to Fledge's `const int` (fifth sync in a row).
  - `Database/Schema/MySqlSchemaState.php`: re-deleted the stale `@phpstan-ignore classConstant.notFound` the squash merge resurrected (same artifact as the last four syncs).
- Squash-merge artifacts caught by a tree-level drift audit (every file the upstream delta did not touch must match `fledge-13`, every upstream-only file must match `v13.24.0`): `install-nightly.yml` resurrected (re-deleted), the `permissions:` block in `tests.yml` duplicated again (restored), and the auto-merge silently dropped upstream's new `$job->shouldReceive('attempts')` expectation from `QueuedEventsTest` (restored, it backs the new unique-lock release guard).
- Tests: 14,387 passing (14,914 total, 523 skipped). Only the four known-environmental `RedisConnectionTest::*scansForKeys` "ERR invalid cursor" errors remain. Both phpstan configs clean.

### Preserved
- Native URI (`Uri\Rfc3986\Uri`), persistent cURL, `Arr` `array_all`/`array_any`, and the pipe-operator `Pipeline` all carried through untouched (none were in the upstream delta). No `league/uri` or `symfony/polyfill-php8[45]` reintroduced.
- No `.2` optimization tag this round: upstream's new code already leans modern (`array_all()` in `Str::replaceWhileIgnoringCase()`, first-class callables, memoized caches in `CompiledRouteCollection`), and the remaining candidates are cold-path console commands.

## v13.23.0.2 - 2026-07-30

### Optimized
- `Console/Scheduling/CronExpressionTimezoneConverter.php`: `end($values)` -> `array_last($values)` in `collapse()`, dropping the array-pointer mutation and matching the `array_last` usage in `Arr`.
- Nothing else qualified: upstream's rewritten timezone converter already leans modern (first-class callables via `intval(...)`, `str_contains`, named arguments), and the `CookieJar`, `LogManager`, `SesV2Transport`, and `CallQueuedHandler` deltas are too thin to benefit.

## v13.23.0.1 - 2026-07-30

### Synced
- Merge upstream Laravel v13.22.0 -> v13.23.0 (37 files, 847 insertions, 168 deletions; 24 of them under `src/`). Headline upstream changes: `CronExpressionTimezoneConverter` rewritten from scratch (full cron field expansion with ranges and steps, DST-aware offsets probed at the next two run dates, day-of-month shifting across month boundaries, bail-out to the original expression when a shift cannot be represented); a `monthly` log driver (`RotatingFileHandler::FILE_PER_MONTH` with `max_files`, and `daily` now also honoring `max_files`); SES v2 tenant support via the `X-SES-TENANT-NAME` header; unique job locks now released on every attempt, not just the first (`CallQueuedHandler` dropped its `attempts() <= 1` guards); path-aware `CookieJar::queued()` lookups; a Postgres `using()` clause for column type changes; monolog bumped to `^3.10`.
- Conflicts in Fledge-modified files, all resolved small:
  - `Foundation/Application.php`: keep the typed VERSION constant, bumped to `13.23.0`.
  - `Collections/Arr.php`: upstream's `last()` null guard was already present (carried early from PR #60887 in the v13.22.0.1 sync), so it merged to no net change; dropped the redundant `$keys === []` re-check upstream added to `hasAny()`, which Fledge's guard already covers.
  - `composer.json`: monolog `^3.10` taken; no `league/uri`, fledge-fiber and `laravel/pao` kept.
  - `Queue/SqsQueue.php`: dropped the untyped `MAX_MESSAGES_PER_BATCH` duplicate the auto-merge re-added next to Fledge's `const int` (fourth sync in a row).
  - `Database/Schema/MySqlSchemaState.php`: re-deleted the stale `@phpstan-ignore classConstant.notFound` the squash merge resurrected (same artifact as the last three syncs).
- CI workflows: upstream's only workflow change (the `actions/checkout` v7.0.1 pin) was already on `fledge-13` via dependabot, so the trees matched, but the squash merge still resurrected `install-nightly.yml` (re-deleted, no-nightly-CI policy, `databases-nightly.yml` also kept deleted) and duplicated the top-level `permissions:` block in `tests.yml`. GitHub rejects the duplicate YAML key at workflow startup with zero jobs, which local phpunit and phpstan cannot catch; the CI gate caught it and the fix landed as a follow-up commit before tagging, so the tag points at a fully green run set.
- Tests: 14,174 passing (14,698 total, 520 skipped). Only the four known-environmental `RedisConnectionTest::*scansForKeys` "ERR invalid cursor" errors remain. Both phpstan configs clean, all seven CI runs green.

### Preserved
- Native URI (`Uri\Rfc3986\Uri`), persistent cURL, `Arr` `array_all`/`array_any`, and the pipe-operator `Pipeline` all carried through untouched. No `league/uri` or `symfony/polyfill-php8[45]` reintroduced.

## v13.22.0.2 - 2026-07-25

### Optimized
- `Queue/Console/ClearCommand.php`: rewrite the new multi-queue name parsing from a `collect()` chain to the pipe operator (`explode` |> `array_map(trim(...))` |> `array_filter` |> `array_unique` |> `array_values`), same family as the `Pipeline` rewrite, and replace the `(new Stringable('queue'))->plural(...)` allocation with the already-imported `Str::plural('queue', $queues)`.
- `Validation/FakeDnsGetRecordWrapper.php`: add `#[\Override]` on `getRecords()` (overrides egulias' `DNSGetRecordWrapper`), matching the existing usage in `Eloquent\Collection`, `MorphTo`, and `SQLiteGrammar`.
- Nothing else qualified: upstream's own delta already modernized in Fledge's direction (`Str::of()` -> `new Stringable(...)` across 12 files, `pow()` -> `**` in `Number`, and `Str::ucfirst`/`lcfirst` now delegate to PHP 8.4's `mb_ucfirst`/`mb_lcfirst`). The new `BindWhen` attribute keeps the unpromoted style of its `Bind` sibling.

## v13.22.0.1 - 2026-07-25

### Synced
- Merge upstream Laravel v13.21.1 -> v13.22.0 (45 source files, 323 insertions, 108 deletions). Headline upstream changes: the `#[BindWhen]` container attribute (closure-conditional bindings, with `Container::resolveConcreteFromAttributes()` rewritten to honor declaration order); `Validator::fakeDnsLookups()` plus the `FakeDnsGetRecordWrapper` for DNS-free `active_url`/`email:dns` validation in tests; queue `bulk()` methods now honoring the `#[Delay]` job attribute (SQS, Redis, database, Beanstalkd); `queue:clear` accepting comma-separated queue names; `QueueFake` tracking job creation timestamps for `creationTimeOfOldestPendingJob()`; `JobReleasedAfterException` carrying the exception; `RateLimiter` becoming macroable; and `Http::fake()` accepting `StreamInterface`/resource bodies.
- Carried the fix for upstream's `Arr::last()` regression: #60881 (merged the day before the tag) routes every input through `Arr::from()`, which throws on `null`, fataling five Cookie/Auth tests via `CookieJar::queued()` on a missing key. Applied the null-early-return from the still-open upstream PR #60887, so the next sync merges clean.
- Conflicts in Fledge-modified files, all resolved small:
  - `Foundation/Application.php`: keep the typed VERSION constant, bumped to `13.22.0`.
  - `Collections/Arr.php`: keep Fledge's merged `hasAny()` guard; upstream's iterable restorations in `every()`/`some()`/`last()` auto-merged (they wrap Fledge's `array_all`/`array_any` with an `is_array` fast path).
  - `Foundation/Exceptions/Handler.php`: keep Fledge's first-class-callable `dontRetryWhen()` and `array_any` `shouldStopRetries()`; upstream's `Stringable` swap in `renderForConsole()` auto-merged.
  - `Http/Client/Factory.php`: keep Fledge's persistent-cURL and global-handler additions, take upstream's widened `psr7Response()` body types (flagged for fledge-fiber review).
  - `Queue/SqsQueue.php`: take upstream's `#[Delay]` attribute support in `prepareBatchMessages()`; drop the untyped `MAX_MESSAGES_PER_BATCH` duplicate the auto-merge re-added next to Fledge's `const int` (third sync in a row; it fataled the queue tests until removed).
  - `Database/Schema/MySqlSchemaState.php`: re-deleted the stale `@phpstan-ignore classConstant.notFound` the squash merge resurrected (caught by the phpstan gate, as in the last two syncs).
- CI workflows: adopted upstream's new `setup-php-project` composite action (deduplicates PHP setup across all workflows) with Fledge's defaults baked in (PHP 8.5, newer `setup-php` pin), re-applied the `fledge-*` branch triggers, kept the trimmed PHP 8.5-only test matrix, and kept `databases-nightly.yml` deleted (no-nightly-CI policy).
- Squash merge-base drift conflicts resolved by ownership: kept Fledge's `composer.json`, `Support/Facades/Request.php`, `Foundation/DevCommand.php`, the `Image` files, and the NodePackageManager files; took upstream's versions of files Fledge does not modify (`Foundation/Cloud/Queue.php`, `Foundation/Console/DevListCommand.php`, `Support/Number.php`, `Support/Testing/Fakes/QueueFake.php`, `rector.php`, `CHANGELOG.md`, and six test files).
- Tests: 14,133 passing (14,658 total, 521 skipped). Only the four known-environmental `RedisConnectionTest::*scansForKeys` "ERR invalid cursor" errors remain. Both phpstan configs clean.

### Preserved
- Native URI (`Uri\Rfc3986\Uri`), persistent cURL, `Arr` `array_all`/`array_any`, and the pipe-operator `Pipeline` all carried through untouched. No `league/uri` or `symfony/polyfill-php8[45]` reintroduced.

## v13.21.1.1 - 2026-07-22

### Synced
- Merged upstream Laravel v13.20.0 -> v13.21.1, two releases in one window (55 source files changed, 289 insertions, 88 deletions). Headline upstream changes: the `Image` component gained PNG/GIF/AVIF/BMP output (`toPng()`/`toGif()`/`toAvif()`/`toBmp()`, new Intervention encoders, wider `ImageOutputOptions::$format`); the `#[RouteKey]` Eloquent class attribute and `#[RequestAttribute]` container attribute; `validateBase64()` validation rule; `DatabaseTransactionsManager` now runs rollback callbacks for committed child transactions in level order; a swappable `Application::$applicationBuilder`; and `RedisTaggedCache` enum-key support via `enum_value()`.
- Fledge-modified files that conflicted, all resolved small:
  - `Foundation/Application.php`: keep the typed VERSION constant (bumped to `13.21.1`) and take upstream's `$applicationBuilder` property and `new static::$applicationBuilder(...)` in `configure()`.
  - `Image/ImageOutputOptions.php`: keep Fledge's `const int DEFAULT_QUALITY`, take upstream's widened `$format` PHPDoc.
  - `Image/Drivers/InterventionDriver.php`: keep Fledge's `array_find()` in `transformationHandlerFor()`, take upstream's four new encoders in the `match`.
  - `Image/composer.json`: keep `php ^8.5`, take upstream's `ext-fileinfo` suggest.
  - `Queue/SqsQueue.php`: drop the untyped `MAX_MESSAGES_PER_BATCH` duplicate the auto-merge re-added next to Fledge's `const int` (same artifact as the v13.20.0 sync; it fataled the queue tests until removed).
  - `Database/Schema/MySqlSchemaState.php`: re-deleted the stale `@phpstan-ignore classConstant.notFound` the squash merge resurrected (caught by the phpstan gate, as in v13.20.0).
- Squash merge-base drift conflicts resolved by ownership: kept Fledge's `composer.json`, `Collections/Arr.php`, `Foundation/Exceptions/Handler.php`, `Support/Facades/Request.php`, `Foundation/DevCommand.php`, and the NodePackageManager files; took upstream's versions of files Fledge does not modify (`Database/DatabaseTransactionsManager.php`, `Http/Resources/JsonApi/Concerns/ResolvesJsonApiElements.php`, `Image/Image.php`, `Image/ImageManager.php`, `Support/Facades/Image.php`, `CHANGELOG.md`, `bin/release.sh`, `rector.php`, and eight test files). Removed the re-added `databases-nightly.yml` (no-nightly-CI policy); merged upstream's `Image` facade entry and action SHA-pin bump into Fledge's `facades.yml`/`releases.yml`.
- Tests: 14,098 passing (14,623 total, 521 skipped). Only the four known-environmental `RedisConnectionTest::*scansForKeys` "ERR invalid cursor" errors remain (phpredis cursor typing under PHP 8.5). Both phpstan configs clean.
- Optimization scan over the window: nothing qualified. The two new attribute classes match the untyped promoted-property style of their 16 `Container/Attributes` siblings, upstream's new Image and transaction code already uses `match`/collections idioms, and `validateBase64()` has no array-function equivalent.

### Preserved
- `Application::VERSION` stays a typed class constant (`const string VERSION`), bumped to `13.21.1`.
- Native URI (`Uri\Rfc3986\Uri`), persistent cURL, `Arr` `array_all`/`array_any`, and the pipe-operator `Pipeline` all carried through untouched. No `league/uri` or `symfony/polyfill-php8[45]` reintroduced.

## v13.20.0.2 - 2026-07-15

### Optimized
- Type the new `DEFAULT_QUALITY` constant in `Image/ImageOutputOptions.php` (`const int`), matching `Application::VERSION`, `Worker::EXIT_*`, and the `SqsQueue` constants.
- Replace the find-first `foreach` in `InterventionDriver::transformationHandlerFor()` with PHP 8.4's `array_find()`, the same family as the `array_any` rewrites in `Handler` and `FormRequest`. All 228 Image tests pass.
- Nothing else qualified: the new `Illuminate/Image` component already uses readonly promoted properties, `match`, first-class callables, and `once()`; its remaining loops are side-effecting iterations.

## v13.20.0.1 - 2026-07-15

### Synced
- Merge upstream Laravel v13.19.0 -> v13.20.0 (80 source files, 1,677 insertions, 186 deletions). Headline: the new `Illuminate/Image` component (26 files, optional `intervention/image ^4.0`). Also: `Model::incrementEachQuietly()`/`decrementEachQuietly()`, worker memory usage on the `WorkerStopping` event, `#[\SensitiveParameter]` on the HTTP client auth methods, the `WithoutMiddleware` controller attribute, and the `SqsQueue` size-method precedence fix.
- Upstream again adopted Fledge idioms: `Model::isIgnoringTouch()`, `Handler::shouldStopRetries()`, `PendingRequest::isAllowedRequestUrl()`, and `ValidatesAttributes::validateRequiredArrayKeys()` moved to `array_any`/`array_all`.
- Conflicts in Fledge-modified files, all resolved small:
  - `Foundation/Application.php`: keep the typed VERSION constant (bumped) and take upstream's `image` container alias.
  - `Foundation/Exceptions/Handler.php`: keep Fledge's first-class-callable `dontRetryWhen()` and `array_any` `shouldStopRetries()`.
  - `Queue/Worker.php`: take upstream's `currentMemoryUsage()` argument on both `WorkerStopping` dispatches; disjoint from Fledge's Revolt signal watchers.
  - `Queue/SqsQueue.php`: drop the untyped `MAX_MESSAGES_PER_BATCH` duplicate the auto-merge re-added next to Fledge's `const int`.
- Resolve squash merge-base drift conflicts by ownership: keep Fledge's `composer.json` (php ^8.5, fledge-fiber, no polyfills, plus upstream's new `intervention/image` and `illuminate/image` entries), `Collections/Arr.php`, `Foundation/DevCommand.php`, and the NodePackageManager files; take upstream's `rector.php`, `Foundation/DevCommands.php`, `Queue/Events/WorkerStopping.php`, `Support/Facades/Queue.php`, `Support/Testing/Fakes/QueueFake.php`, `CHANGELOG.md`, and eight test files it alone changed. Remove the re-added `databases-nightly.yml` (no-nightly-CI policy).
- Bump `Image/composer.json` from upstream's `php ^8.3` to `^8.5`, aligning the new package with the other 37 sub-packages.
- Tests: 14,047 passing (14,572 total, 521 skipped). Only the four known-environmental `RedisConnectionTest::*scansForKeys` "ERR invalid cursor" errors remain (phpredis cursor typing under PHP 8.5).

### Preserved
- `Application::VERSION` stays a typed class constant (`const string VERSION`), bumped to `13.20.0`.
- Native URI (`Uri\Rfc3986\Uri`), persistent cURL, `Arr` `array_all`/`array_any`, and the pipe-operator `Pipeline` all carried through untouched. No `league/uri` or `symfony/polyfill-php8[45]` reintroduced.

## v13.19.0.2 - 2026-07-07

### Optimized
- `Queue/SqsQueue.php`: typed the class constants (`const int MAX_SQS_PAYLOAD_SIZE`, `const int MAX_MESSAGES_PER_BATCH`, `const string EXTENDED_PAYLOAD_CACHE_PREFIX`), matching the convention already used on `Application::VERSION` and `DevCommand::PRIORITY_*`. `MAX_MESSAGES_PER_BATCH` is new in v13.19.0; the two siblings were typed alongside it for consistency. Nothing else in the window qualified: upstream's new code already uses `array_any`/`array_all` natively, and the remaining new loops (`SqsQueue::chunkBatchEntries()`, `CommandInput::all()`, `Collection::reduceInto()`) are accumulators with no clean array-function equivalent.

## v13.19.0.1 - 2026-07-07

### Synced
- Merged upstream Laravel v13.18.0 -> v13.19.0, two releases in one window (50 source files changed, 1,100 insertions, 332 deletions). Headline upstream changes: `SqsQueue::bulk()` rewritten onto the SQS `SendMessageBatch` API with count/byte-aware chunking and after-commit partitioning; HTTP QUERY method support (`Http::query()`, `PendingRequest::query()`, `MakesHttpRequests::query()`/`queryJson()`); `Collection::reduceInto()`; a new `Console/CommandInput` container backing `input()` on commands; Cloud queue agent-aware lost-connection handling (`AgentAwareLostConnectionDetector`, `AgentUnreachableException`, `CloudJob`); the `Release` queue middleware; and scalar Predis retry config for `config:cache`.
- Notably, upstream adopted Fledge's own idioms this window: `Arr::hasAll()`/`hasAny()`, `Router::has()`/`uses()`, `ValidatesAttributes::anyFailingRequired()`/`allFailingRequired()`, `Bun::matches()`, and `whereInstanceOf()` all moved to `array_all`/`array_any` upstream, converging with rewrites Fledge shipped months ago.
- Fledge-modified files that conflicted, all resolved small:
  - `Foundation/Application.php`: VERSION constant (typed-constant conflict, see Preserved).
  - `Collections/Arr.php`: dropped upstream's now-redundant standalone `$keys === []` guard in `hasAny()`; Fledge's combined `! $array || $keys === []` guard already covers it.
  - `Http/Client/PendingRequest.php`: upstream's new `query()` method auto-merged next to Fledge's persistent-cURL hunks.
- Squash merge-base drift again produced conflicts in files upstream did NOT change this window; kept Fledge's `composer.json` (php ^8.5, `webpatser/fledge-fiber`, no polyfills), `Foundation/Exceptions/Handler.php`, `Foundation/DevCommand.php`, `Support/NodePackageManager.php`, and `Support/NodePackageManagers/Bun.php`; took upstream's versions of files Fledge does not modify (`Foundation/Cloud/QueueConnector.php`, `Log/LogManager.php`, `Redis/Connectors/PredisConnector.php`, `Support/Testing/Fakes/QueueFake.php`, `CHANGELOG.md`, and four test files) and verified them byte-identical to v13.19.0.
- `.github/workflows/*`: kept Fledge's CI customizations; the squash re-added upstream's `databases-nightly.yml`, removed again to keep Fledge's no-nightly-CI policy.
- Tests: 13,783 passing (14,307 total, 520 skipped). The only failures are the four known-environmental `RedisConnectionTest::*scansForKeys` "ERR invalid cursor" errors (phpredis cursor typing under PHP 8.5).

### Preserved
- `Application::VERSION` stays a typed class constant (`const string VERSION`), bumped to `13.19.0`.
- Native URI (`Uri\Rfc3986\Uri`), persistent cURL, `Arr` `array_all`/`array_any`, and the pipe-operator `Pipeline` all carried through untouched. No `league/uri` or `symfony/polyfill-php8[45]` reintroduced.

## v13.18.0.2 - 2026-07-01

### Optimized
- `Foundation/DevCommand.php`: typed the new `PRIORITY_DEFAULT`/`PRIORITY_VENDOR`/`PRIORITY_USERLAND` class constants (`const int`), matching the convention already used on `Application::VERSION` (`const string`) and `Worker::EXIT_*` (`const int`).

## v13.18.0.1 - 2026-07-01

### Synced
- Merged upstream Laravel v13.17.0 -> v13.18.0 (35 source files changed, 243 insertions, 101 deletions). Headline upstream changes: the queue `Worker` now tracks `jobsProcessed`/`lastJobProcessedAt` as instance properties and forwards them to the `WorkerStopping` event; a new command-registration priority system (`DevCommand::PRIORITY_*`, `DevCommands::resolvePriority()`); `Number::fileSize()`/`summarize()` guard against non-finite input; and the `schedule:work` command gained graceful-shutdown signal handling.
- Four Fledge-modified files conflicted, all resolved small:
  - `Foundation/Application.php`: VERSION constant (typed-constant conflict, see Preserved).
  - `Routing/Route.php`: accepted upstream's conditional `@return` PHPDoc on `getMetadata()`; disjoint from Fledge's `concurrentMiddleware` additions.
  - `Collections/Arr.php` and `Foundation/Http/Kernel.php`: upstream touched only `@return` PHPDoc (`random()`, `getGlobalMiddleware()`), disjoint from Fledge's `array_all`/`array_any` and `concurrentMiddleware` hunks; auto-merged.
  - `Queue/Worker.php`: auto-merged cleanly. Upstream's `jobsProcessed`/`lastJobProcessedAt` instance-property refactor and new `WorkerStopping` arguments live in disjoint methods from Fledge's Revolt signal watchers, `sleep()`, and `const int` typing.
- Squash merge-base drift produced add/add conflicts in files upstream did NOT change this window; resolved by keeping whichever side actually owns them: kept Fledge's `composer.json` (php ^8.5, `webpatser/fledge-fiber`, no polyfills), `Foundation/Exceptions/Handler.php` (array_any/first-class-callable rewrites), `Support/NodePackageManager.php`, and `Support/NodePackageManagers/Bun.php`; took upstream's `CHANGELOG.md` (Fledge maintains its own release notes here). The `DevCommand` suite (`Foundation/Console/DevCommand.php`, `Foundation/Console/DevListCommand.php`, `Foundation/DevCommand.php`, `Foundation/DevCommands.php`) and their tests are unmodified by Fledge, so upstream's versions were taken wholesale.
- `.github/workflows/*`: kept Fledge's CI customizations. Upstream's only real delta this window was `releases.yml` (two action SHA-pin bumps targeting Laravel's own splitter/release infra, which Fledge does not run). The squash re-added upstream's `databases-nightly.yml`; removed again to keep Fledge's no-nightly-CI policy.
- Tests: 13,676 passing. The only failures are the four known-environmental `RedisConnectionTest::*scansForKeys` "ERR invalid cursor" errors (phpredis cursor typing under PHP 8.5); upstream made no Redis changes this window.

### Preserved
- `Application::VERSION` stays a typed class constant (`const string VERSION`), bumped to `13.18.0`.
- Native URI (`Uri\Rfc3986\Uri`), persistent cURL, `Arr` `array_all`/`array_any`, and the pipe-operator `Pipeline` all carried through untouched. No `league/uri` or `symfony/polyfill-php8[45]` reintroduced.

## v13.17.0.2 - 2026-06-24

### Optimized
- `Foundation/Exceptions/Handler.php`: the new v13.17.0 job-retry methods now match the idioms already used elsewhere in this file. `dontRetryWhen()` uses first-class callable syntax (`$dontRetryWhen(...)`) instead of `Closure::fromCallable()`, mirroring `reportable()`/`renderable()`/`dontReportWhen()`. `shouldStopRetries()` collapses `! is_null(Arr::first(...))` plus a manual `foreach` into two PHP 8.5 `array_any()` calls, the same rewrite already applied to `shouldntReport()`.

## v13.17.0.1 - 2026-06-24

### Synced
- Merged upstream Laravel v13.16.1 -> v13.17.0 (80 source files changed, 2,912 insertions, 266 deletions). The headline upstream feature is direct/pooled PDO connections; four Fledge-modified files conflicted, all resolved small:
  - `Foundation/Application.php`: VERSION constant (typed-constant conflict, see Preserved).
  - `Foundation/Providers/ArtisanServiceProvider.php`: kept Fledge's `BenchCommand` registration alongside upstream's new `DevListCommand` (`dev:list`).
  - `Http/Client/Factory.php` + `Http/Client/PendingRequest.php`: upstream added `@throws \InvalidArgumentException` docblocks to header/multipart/body normalizers. Accepted on top of Fledge's persistent-cURL hunks, which live in disjoint methods.
  - `composer.json`: kept Fledge's `webpatser/fledge-fiber` requirement and `php: ^8.5`; adopted upstream's `brick/math ^0.18` range bump (also applied to the `Database` and `Validation` sub-package composer files).
- Auto-merged cleanly because upstream's edits landed in regions Fledge never touched: `Queue/Worker.php` (new `markJobAsFailedIfItShouldntBeRetried()` next to Fledge's Revolt signal watchers), `Foundation/Exceptions/Handler.php` (new `dontRetry`/`dontRetryWhen`/`shouldStopRetries` alongside Fledge's `array_any` and first-class-callable rewrites), `Support/Facades/Route.php` (new `metadata()` next to Fledge's `concurrentMiddleware` docblocks), `Validation/Concerns/ValidatesAttributes.php` (loosened `array{0?:}` param docblocks next to Fledge's dynamic `DateTimeZone::{...}` const fetch), and `Reflection/helpers.php`.
- New upstream features accepted as-is: direct/pooled PDO connections (`Database/Connectors/Concerns/ConfiguresPooledConnections.php`, `Database/Console/Concerns/InteractsWithPooledConnections.php`, the `directPdo` routing on `Database/Connection.php`, and `PostgresConnection::prepareBindings()`/`usesEmulatedPrepares()`), the `bench` command, and the `dev:list` command (`Foundation/Console/DevListCommand.php`).
- `.github/workflows/*`: kept Fledge's CI customizations (PHP 8.5, `fledge-*` triggers, custom matrix). Upstream's only delta this window was an `actions/checkout` v6 -> v7 SHA pin bump, which does not affect the published package. Upstream's nightly workflows were re-pruned: the squash merge re-added `databases-nightly.yml` and pulled in the new `install-nightly.yml`; both were removed to keep Fledge's no-nightly-CI policy.
- `CHANGELOG.md`: took upstream's Laravel changelog. Add/add conflicts from the older squash merge-base (`NodePackageManager.php`, `NodePackageManagers/Bun.php`, the `DevCommand` suite, several test and type files) were resolved by keeping whichever side actually changed in this window.

### Preserved
- `Application::VERSION` stays a typed class constant (`const string VERSION`), bumped to `13.17.0`.
- Native URI (`Uri\Rfc3986\Uri`), persistent cURL, `Arr` `array_all`/`array_any`, and the pipe-operator `Pipeline` all carried through untouched. No `league/uri` or `symfony/polyfill-php8[45]` reintroduced.
- Tests: 13,657 passing. The only failures are the four `RedisConnectionTest` SCAN cases, which fail identically on stock Laravel here: the local server rejects PhpRedis's prefixed non-integer SCAN cursors with `ERR invalid cursor`. Not a framework or Fledge issue.

## v13.16.1.2 - 2026-06-18

### Optimized
- `Support/NodePackageManagers/Bun.php`: `matches()` lock-file scan rewritten from a `foreach` early-return loop to PHP 8.5 `array_any()`, matching the idiom already used across `Arr`.
- `Support/NodePackageManager.php`: `detect()` rewritten from a `foreach` early-return loop to PHP 8.4 `array_find()`, expressing the "first matching manager, else npm" intent in one expression. The new `DevCommands::preventVendorRegistration()` stack-walk and `commands()` filter were left as loops; their per-frame reflection fallback and dual `in_array` membership checks do not map cleanly to the new array callbacks.

## v13.16.1.1 - 2026-06-18

### Synced
- Merged upstream Laravel v13.15.0 -> v13.16.1, two releases in one window (108 source files changed, 3,674 insertions, 1,016 deletions). Three Fledge-modified source files conflicted, all resolved small:
  - `Foundation/Application.php`: VERSION constant (typed-constant conflict, see Preserved).
  - `Http/Client/Factory.php`: upstream added `psr7Response()` body validation, `JSON_THROW_ON_ERROR` encoding, and a `normalizeScalarString()` helper that guards PHP 8.5 non-finite-float `(string)` coercion. Adopted alongside Fledge's persistent-cURL `persistentConnections()` and `globalHandler()` additions, which live in disjoint methods.
  - `Http/Client/PendingRequest.php`: upstream reworked request body/query/multipart normalization (`normalizeNonFiniteFloatValues()`, `normalizeScalarString()`, `ensureValidRequestBody()`, `withBody()` validation). Rebuilt on upstream's version with Fledge's three persistent-cURL hunks (`persistentCurlOptions` property, `withPersistentConnections()`, the `Factory::getGlobalHandler()` fallback in `buildHandlerStack()`) re-applied on top. Verified byte-for-byte against upstream plus exactly those hunks.
- New upstream files accepted as-is: the Node package-manager system (`Support/NodePackageManager.php`, `Support/Contracts/NodePackageManager.php`, `Support/NodePackageManagers/{Bun,Npm,Pnpm,Yarn}.php`), the `dev` command suite (`Foundation/DevCommand.php`, `Foundation/DevCommands.php`, `Foundation/DevCommandColor.php`, `Foundation/Console/DevCommand.php`), `Foundation/ArrayMaintenanceMode.php`, and `JsonSchema/Types/AnyOfType.php`.
- `JsonSchema/{Deserializer,JsonSchemaTypeFactory}.php`, `Contracts/JsonSchema/JsonSchema.php`, and their tests surfaced as add/add conflicts purely because the squash merge-base predates them; Fledge never modified them, so upstream was taken verbatim.
- `.github/workflows/*`: kept Fledge's CI customizations (PHP 8.5, `fledge-*` branch triggers, `:php-psr`, the extra MySQL-9 matrix, deleted `databases-nightly.yml`). Upstream's only delta was a `shivammathur/setup-php` action SHA pin bump, which does not affect the published package.
- `CHANGELOG.md`: took upstream's Laravel changelog.

### Preserved
- `Application::VERSION` stays a typed class constant (`const string VERSION`), bumped to `13.16.1`.
- Native URI (`Uri\Rfc3986\Uri`), persistent cURL, `Arr` `array_all`/`array_any`, and the pipe-operator `Pipeline` all carried through untouched. No `league/uri` or `symfony/polyfill-php8[45]` reintroduced.
- Tests: 13,573 passing. The only failures are the four `RedisConnectionTest` SCAN cases, which fail identically on stock Laravel here: the local server is Valkey 9.1.0, which rejects PhpRedis's prefixed non-integer SCAN cursors with `ERR invalid cursor`. Not a framework or Fledge issue.

## v13.15.0.2 - 2026-06-12

### Fixed
- `.github/workflows/tests.yml`: removed a duplicate top-level `permissions:` block introduced by the v13.15.0 squash merge. GitHub Actions rejects workflows with duplicate YAML keys, which failed the `tests` workflow at parse time (0s runs).
- `Database/Schema/MySqlSchemaState.php`: re-removed the unmatched `@phpstan-ignore classConstant.notFound` comment above the `Mysql::ATTR_SSL_VERIFY_SERVER_CERT` check. This is the same fix as v13.13.0.2; the v13.14.0 sync silently re-introduced upstream's comment, turning the `static analysis` workflow red from v13.14.0 onward. Fledge runs PHP 8.5 with `pdo_mysql`, where the constant resolves cleanly, so the ignore is unmatched under our strict config (`phpstan.src` and `phpstan.types` both green).

## v13.15.0.1 - 2026-06-12

### Synced
- Merged upstream Laravel v13.14.0 -> v13.15.0 (48 source files changed, 349 insertions, 64 deletions). Seven Fledge-modified files were also touched upstream; only `Foundation/Application.php` (VERSION constant) produced a real conflict. The other six auto-merged cleanly because upstream's edits landed in regions Fledge never touched:
  - `Container/Container.php` + `Support/Facades/App.php`: `resolveFromAttribute()` gained a `ReflectionParameter $parameter` argument threaded through to the contextual-attribute handler.
  - `Queue/Worker.php`: `listenForSignals()` parameter typed `?WorkerOptions $options`.
  - `Support/Facades/Lang.php`: new `string()` / `array()` docblock methods.
  - `Database/Eloquent/Concerns/HasAttributes.php`: `castAttributeAsEncryptedString()` return type widened to `string|null`.
  - `Validation/Concerns/ValidatesAttributes.php`: `compare()` `=` arm hardened against loose null equality.
- New upstream files accepted as-is: `JsonSchema/Types/UnionType.php` and the multi-type union support added to `JsonSchema/Deserializer.php` (add/add, took upstream); `Translation/Translator.php` gained typed `string()` / `array()` accessors.
- `Foundation/LaravelCloudJsonFormatter.php`: git's rename detection mis-paired this new top-level formatter against the pre-existing `Foundation/Cloud/JsonFormatter`, so the squash merge silently dropped both the new file and the `Cloud.php` switch to it. Restored both from v13.15.0 by hand; cloud logging now references `LaravelCloudJsonFormatter::class` as upstream intends (the old `Cloud\JsonFormatter` stays for back-compat).
- `.github/workflows/databases-nightly.yml`: kept Fledge's deletion (upstream left it unchanged in this window).
- `CHANGELOG.md`: took upstream's Laravel changelog.

### Preserved
- `Application::VERSION` stays a typed class constant (`const string VERSION`), bumped to `13.15.0`.
- Native URI (`Uri\Rfc3986\Uri`), persistent cURL, `Arr` `array_all`/`array_any`, and the pipe-operator `Pipeline` all carried through untouched. No `league/uri` or `symfony/polyfill-php8[45]` reintroduced (`polyfill-php86` retained, it backfills PHP 8.6 features for the 8.5 runtime).

### Optimized
- None. The v13.15.0 delta is small bugfix guards (`Number::fileSize`/`pairs`/`trim` infinity and zero checks, `BladeCompiler` mtime touch) and new typed accessors that are already idiomatic for PHP 8.5. The new `JsonSchema` union loops are membership validation with precise per-element error messages that `array_all`/`array_any` would degrade; `in_array()` uses are callback-free membership checks. Nothing maps cleanly to `array_find` or the pipe operator.

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
