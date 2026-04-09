# Fledge

**Laravel 13, optimized for PHP 8.5**

Named after Fledge from C.S. Lewis's Narnia — a horse transformed by Aslan into something faster and more capable. Laravel's name also comes from Narnia (Cair Paravel). Fledge transforms Laravel for PHP 8.5.

## What is Fledge?

Fledge is a drop-in replacement for Laravel's `illuminate/framework` that requires PHP 8.5 and uses its native features for better performance. Same `Illuminate\` namespace, same API, full ecosystem compatibility.

Laravel 13 supports PHP 8.3+ and ships polyfills so it can run on older versions. Fledge removes those polyfills and version checks, and replaces `league/uri` with PHP 8.5's native URI extension — the single biggest performance win.

**53 files changed** on top of Laravel 13.4.0. All 13,382 framework tests pass.

## Why?

Laravel supports PHP 8.3+ because that's the right call for the ecosystem. But if you're already on PHP 8.5, you're paying for compatibility you don't need:

- **11,000 lines of `league/uri` PHP code** replaced by compiled C in the PHP runtime
- **Symfony polyfills** for `array_first()`, `array_last()`, `array_all()`, `array_any()` — functions that ship natively in PHP 8.5
- **`version_compare` guards** that branch on every request to check if you're on 8.4+

Fledge strips all of that.

## Performance

### Real-World

| Metric | Laravel 13 | Fledge | Difference |
|--------|-----------|--------|------------|
| Homepage render | 30ms | 25ms | **17% faster** |

### Where the Speed Comes From

The main improvement is URI handling. Every HTTP request parses URIs — `$request->uri()`, the `url()` helper, redirects, route generation. Laravel uses `league/uri` for this. Fledge uses PHP 8.5's native `Uri\Rfc3986\Uri`, which is compiled C:

| Operation | league/uri | PHP 8.5 native |
|-----------|-----------|----------------|
| Parse URI | 0.047 ms | 0.0005 ms |
| Modify URI | 0.24 ms | 0.0004 ms |

~100x faster for URI operations. Since URIs are touched on every request, it compounds into the 17% real-world improvement.

### Other Gains

| Operation | Improvement |
|-----------|-------------|
| `Arr::hasAll` / `Arr::hasAny` | ~6% faster (native `array_all`/`array_any`) |
| Collection chains | ~5% faster |
| String pipelines | ~9% faster (pipe operator) |
| No polyfill autoloading | Faster bootstrap |

### Fiber-Based Concurrency

Fledge adds a `FiberDriver` to the Concurrency facade, powered by the [Revolt](https://revolt.run) event loop and [amphp](https://amphp.org). Unlike the `ProcessDriver` (which spawns child processes) or the `SyncDriver` (sequential), the `FiberDriver` provides real cooperative async I/O within a single process:

```php
use Illuminate\Support\Facades\Concurrency;

// 3 HTTP requests run concurrently — total time ≈ slowest request
$results = Concurrency::driver('fiber')->run([
    fn () => $httpClient->request(new Request('https://api1.example.com'))->getBody()->buffer(),
    fn () => $httpClient->request(new Request('https://api2.example.com'))->getBody()->buffer(),
    fn () => $httpClient->request(new Request('https://api3.example.com'))->getBody()->buffer(),
]);
```

No background process needed — the Revolt event loop runs inline within the `run()` call. Tasks using amphp async drivers (HTTP, MySQL, Redis) genuinely interleave on I/O suspension. Shared memory, no serialization overhead, works in both web requests and CLI.

Also available as a standalone package for Laravel 11/12/13: [`webpatser/laravel-fiber`](https://github.com/webpatser/laravel-fiber)

### Non-Blocking Redis (amphp driver)

Fledge ships with `amphp/redis` as the **default Redis driver**. Every Redis call — cache reads, locks, queue operations, rate limiting — uses Fiber-based non-blocking I/O via the Revolt event loop.

```php
// Every Cache::get() and Redis::get() is non-blocking by default.
// No code changes needed — the amphp driver is transparent.
Cache::get('key');        // non-blocking I/O under the hood
Redis::set('key', 'val'); // same

// Inside Concurrency::run(), multiple Redis calls parallelize automatically:
Concurrency::driver('fiber')->run([
    fn () => Cache::get('user:1'),
    fn () => Cache::get('user:2'),
    fn () => Cache::get('user:3'),
]); // all 3 reads happen concurrently
```

The `amphp` driver works from any context. From the main thread, each command still appears synchronous but uses non-blocking socket I/O. Inside a Fiber, multiple commands in separate Fibers execute in parallel.

To fall back to the synchronous phpredis C extension (e.g., for Redis Cluster, which amphp doesn't support):

```env
REDIS_CLIENT=phpredis
```

The cache layer also includes Fiber-aware internals:
- **Lock blocking** suspends the Fiber instead of `usleep()`, letting other Fibers run
- **Failover reads** try all stores concurrently, returning the first success
- **Cluster operations** (`many()`/`putMany()`) run concurrent reads/writes via Fibers
- **Tag operations** flush chunks and write entries concurrently

## What Changed

| Change | Files | Impact |
|--------|-------|--------|
| Native `Uri\Rfc3986\Uri` replacing `league/uri` | 3 | ~100x faster URI ops |
| RFC 3986 normalization layer (IDN, unicode, brackets) | 1 | Compatibility bridge |
| Remove `symfony/polyfill-php84` and `polyfill-php85` | 7 | Cleaner autoloading |
| Bump PHP to `^8.5` | 37 | Drop compatibility code |
| Remove `version_compare` PHP 8.4 guards | 3 | No runtime branching |
| `array_all`/`array_any` in `Arr::hasAll`/`hasAny` | 1 | Faster array checks |
| `array_any` in `Handler::shouldntReport` | 1 | Replace `Arr::first` null check |
| `array_any` in `FormRequest::isKnownField` | 1 | Replace foreach early-return |
| Pipe operator in `Pipeline::then()` | 1 | Cleaner code |
| `#[\NoDiscard]` on Pipeline, Cache, Container, Validation | 4 | Developer safety |
| Persistent cURL share manager | 3 | Connection pooling |
| `json_validate()` fast path | 1 | Skip decode on invalid JSON |
| Fiber-based concurrency driver (Revolt + amphp) | 2 | Real async I/O in `Concurrency` facade |
| amphp/redis as default Redis driver | 5 | Non-blocking Redis I/O for all operations |
| Fiber-aware cache layer (locks, failover, tags) | 8 | Concurrent cache ops inside Fibers |
| Fiber-safe queue worker signal handling (Revolt) | 1 | Horizon/queue workers work with fiber drivers |
| Redis required dependency for cache package | 1 | Redis is a first-class citizen |

### The RFC 3986 Problem (and How Fledge Solves It)

PHP 8.5's native URI parser is strictly RFC 3986 compliant — stricter than `league/uri`. It rejects:

- Internationalized domain names like `bébé.be`
- Unicode in paths like `/日本語/page`
- Unencoded brackets in query strings like `?filter[status]=active`

[An attempt to add native URI support to Laravel](https://github.com/laravel/framework/pull/58132) stalled because of this strictness gap.

Fledge solves it with a normalization layer (`Uri::normalizeForRfc3986()`) that transparently converts these inputs before passing them to the native parser:

| You write | Fledge normalizes to |
|-----------|---------------------|
| `https://bébé.be` | `https://xn--bb-bjab.be` (punycode) |
| `https://example.com/日本語` | `https://example.com/%E6%97%A5...` (percent-encoded) |
| `?filter[status]=active` | `?filter%5Bstatus%5D=active` (encoded brackets) |

This makes Fledge a true drop-in replacement — your existing URLs keep working.

## Installation

### In an existing Laravel 13 project

```bash
# Add Fledge as a path repository (if local)
composer config repositories.fledge path /path/to/fledge/packages/framework

# Or as a VCS repository (from GitHub)
composer config repositories.fledge vcs https://github.com/webpatser/fledge

# Replace Laravel's framework with Fledge
composer require "laravel/framework:^13.3" -W
```

### From scratch

```bash
# Clone the full project (includes test app + framework)
git clone https://github.com/webpatser/fledge
cd fledge
composer install
```

## Compatibility

- Same `Illuminate\` namespace — all Laravel packages work unchanged
- Same API — no code changes needed in your application
- 13,382 tests passing (4 known Predis failures exist on vanilla Laravel 13 + PHP 8.5 too)

## Requirements

- **PHP 8.5+**
- **intl extension** (for IDN domain support)
- Composer 2.x

## How This Project Works

Fledge tracks Laravel's `13.x` branch. When Laravel releases a new version:

1. Fetch the latest upstream tag
2. Merge into the `fledge-13` branch
3. Resolve any conflicts in the ~50 modified files
4. Run the full test suite
5. Tag a matching Fledge release

The goal is automated sync for clean merges (~70% of releases), with manual intervention only when upstream touches the same files Fledge modifies.

### Versioning

Fledge uses a **fourth version segment** to track its own releases on top of Laravel's version:

| Laravel | Fledge | Meaning |
|---------|--------|---------|
| `v13.3.0` | `v13.3.0.1` | First Fledge release based on Laravel 13.3.0 |
| `v13.3.0` | `v13.3.0.2` | Fledge-only fix on top of 13.3.0 |
| `v13.4.0` | `v13.4.0.1` | Fledge synced to Laravel 13.4.0 |
| `v13.4.0` | `v13.4.0.2` | PHP 8.5 optimizations on top of 13.4.0 |

The first three segments always match the upstream Laravel version. The fourth is Fledge's own patch counter, starting at `.1` for each new Laravel release.

In your `composer.json`, `"laravel/framework": "^13.3"` will pull in the latest Fledge release.

### Project Structure

```
packages/framework/     # The Fledge framework (forked illuminate/framework)
  ├── src/Illuminate/   # Modified Laravel source with PHP 8.5 optimizations
  └── tests/            # Unmodified Laravel test suite (tests are the contract)
```

**Rule: tests are never modified.** If a test fails after a Fledge change, the change is wrong, not the test.

## Known PHP 8.5 Test Failures

These 4 test failures exist on **vanilla Laravel 13 running on PHP 8.5** — they are not caused by Fledge:

| Test | Root Cause |
|------|------------|
| `RedisConnectionTest::testItScansForKeys` | Predis cursor format incompatibility |
| `RedisConnectionTest::testItHscansForKeys` | Predis cursor format incompatibility |
| `RedisConnectionTest::testItZscansForKeys` | Predis cursor format incompatibility |
| `RedisConnectionTest::testItSscansForKeys` | Predis cursor format incompatibility |

## Credits

**All credit goes to [Taylor Otwell](https://github.com/taylorotwell) and the [Laravel](https://laravel.com) team.** This project is built entirely on their work. Fledge is not a fork intended to compete with Laravel — it's an optimization layer for teams already running PHP 8.5.

## License

MIT — same as Laravel.
