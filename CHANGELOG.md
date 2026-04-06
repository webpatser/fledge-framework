# Fledge Changelog

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
