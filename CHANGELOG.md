# Fledge Changelog

## v13.3.0.3 - 2026-04-06

### Added
- **amphp/redis as default Redis driver** — all Redis I/O is now non-blocking by default
  - New `AmphpRedisConnection` wrapping `Amp\Redis\RedisClient` with full phpredis API compatibility
  - New `AmphpRedisConnector` building connections from standard Laravel Redis config
  - New `AmphpRedisPipeline` for concurrent command dispatch
  - Registered as `amphp` driver in `RedisManager`, set as default (`REDIS_CLIENT=amphp`)
  - Every Redis command uses Fiber-based suspension via the Revolt event loop
  - EVALSHA caching with automatic NOSCRIPT fallback (faster Lua script execution)
  - Falls back to phpredis/predis for Redis Cluster (`REDIS_CLIENT=phpredis`)
- **Fiber-aware cache layer** — cache internals use Fibers for concurrent I/O
  - `Lock::block()` suspends the Fiber instead of `Sleep::usleep()`, allowing other Fibers to run
  - `FailoverStore` tries all stores concurrently for read operations, returns first success
  - `RedisStore::many()`/`putMany()` run concurrent reads/writes on cluster connections
  - `RedisTaggedCache::flushValues()` processes chunks concurrently
  - `RedisTagSet::addEntry()` issues concurrent `ZADD` calls across multiple tags
  - New `SuspendsFibers` trait with `Fiber::getCurrent()` detection for safe fallback
- **Redis as required cache dependency** — `illuminate/redis` moved from `suggest` to `require` in the cache package
- Added `amphp/redis` as a framework dependency (moved from `require-dev`)

### Changed
- Default `REDIS_CLIENT` changed from `phpredis` to `amphp` in `config/database.php`
- Cache package now requires `amphp/amp`, `revolt/event-loop`, and `illuminate/redis`
- `RedisStore` and `RedisTaggedCache` updated with `AmphpRedisConnection` support in `instanceof` checks

## v13.3.0.2 - 2026-04-06

### Added
- **Fiber-based concurrency driver** using Revolt event loop and amphp — real cooperative async I/O within the `Concurrency` facade
  - New `FiberDriver` implementing `Illuminate\Contracts\Concurrency\Driver`
  - Registered as `fiber` driver in `ConcurrencyManager`
  - Works with `amphp/http-client`, `amphp/mysql`, and `amphp/redis` for genuine concurrent I/O
  - No background process needed — event loop runs inline within `run()`
  - Shared memory between tasks (no serialization), works in web and CLI
- Added `revolt/event-loop` and `amphp/amp` as framework dependencies

### Benchmarks
- 3x HTTP requests (1s server delay each): **1.96s** concurrent vs ~3s sequential
- 3x MySQL `SLEEP(0.1)` queries: **185ms** concurrent vs ~300ms sequential
- 4x25 Redis key reads: **13.5ms** concurrent

## v13.3.0.1 - 2026-04-05

### Changed
- Use versioned tag for framework dependency

## v13.3.0 - 2026-04-05

### Added
- Initial Fledge release based on Laravel 13.3.0
- Native `Uri\Rfc3986\Uri` replacing `league/uri` (~100x faster URI operations)
- RFC 3986 normalization layer for IDN domains, unicode paths, and bracket encoding
- `array_first()` / `array_last()` native PHP 8.5 functions in Collections and Arr
- Pipe operator `|>` in Pipeline, Collections, and helpers
- Persistent cURL share manager for HTTP connection pooling
- `#[\NoDiscard]` on Pipeline, Cache, Container, and Validation
- `json_validate()` fast path for invalid JSON
- Removed `symfony/polyfill-php84`, `polyfill-php85`, and `version_compare` guards
