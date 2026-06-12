# Fledge

**Laravel 13, optimized for PHP 8.5**

Named after Fledge from C.S. Lewis's Narnia, a horse transformed by Aslan into something faster and more capable. Laravel's name also comes from Narnia (Cair Paravel). Fledge transforms Laravel for PHP 8.5.

## What is Fledge?

Fledge is a drop-in replacement for Laravel's `illuminate/framework` that requires PHP 8.5 and uses its native features for better performance. Same `Illuminate\` namespace, same API, full ecosystem compatibility.

Laravel 13 supports PHP 8.3+ and ships polyfills so it can run on older versions. Fledge removes those polyfills and version checks, and replaces `league/uri` with PHP 8.5's native URI extension. That swap is the single biggest performance win.

**124 files changed** on top of Laravel 13.15.0. The full framework test suite passes under CI's `--fail-on-deprecation`, including against symfony 8.1.

## Why?

Laravel supports PHP 8.3+ because that's the right call for the ecosystem. But if you're already on PHP 8.5, you're paying for compatibility you don't need:

- **11,000 lines of `league/uri` PHP code** replaced by compiled C in the PHP runtime
- **Symfony polyfills** for `array_first()`, `array_last()`, `array_all()`, `array_any()`, functions that ship natively in PHP 8.5
- **`version_compare` guards** that branch on every request to check if you're on 8.4+

Fledge strips all of that.

## Performance

A default Laravel skeleton (single homepage route, PHP-FPM with persistent connections) renders ~17% faster on Fledge than on stock Laravel 13.7.0 with the same PHP 8.5 build and the same Redis backend:

| Metric | Laravel 13 | Fledge | Difference |
|--------|-----------|--------|------------|
| Homepage render (median) | 30 ms | 25 ms | **17% faster** |

This is one workload on one machine. Your numbers will differ depending on where your application's time actually goes: DB calls, external HTTP, template compile, queue dispatch. To reproduce the micro-benchmarks (URI, polyfills, cache throughput) on your own stack:

```bash
php artisan fledge:bench --scenario=uri --iterations=10000
php artisan fledge:bench --scenario=polyfills
php artisan fledge:bench --scenario=redis     # uses your configured cache driver
```

JSON output is available with `--format=json` for CI integration.

### Where the gain comes from

Two compounding wins drive most of the speedup:

1. **Native `Uri\Rfc3986\Uri` replacing `league/uri`.** PHP 8.5 ships URI parsing as a compiled C extension. `Request::uri()`, the `url()` helper, redirects, and route generation all touch URIs on every request, so the savings stack across the request lifecycle:

   | Operation | league/uri | PHP 8.5 native |
   |-----------|-----------|----------------|
   | Parse URI | 0.047 ms | 0.0005 ms |
   | Modify URI | 0.24 ms | 0.0004 ms |

   Roughly 100x faster for URI operations alone.

2. **Non-blocking Redis I/O via `fledge-fiber`.** This one is more nuanced and worth being precise about. On a single sequential `Cache::get()` against a local Redis, fledge-fiber is actually **slower** than Predis (measured at ~32 µs vs ~21 µs per call on this machine). That's the cost of setting up a Fiber, registering a Revolt wakeup, and suspending: pure overhead when you have nothing else to do.

   Where fledge-fiber pays off is when something else *can* run in the meantime:

   - **Concurrent Redis calls inside `Concurrency::driver('fiber')->run()`** overlap on socket I/O. Three parallel `Cache::get()` calls finish in ~32 µs total instead of ~63 µs sequential.
   - **Network RTT to a remote Redis** (1-5 ms typical) makes all clients I/O-bound. Predis blocks the whole worker on that RTT. fledge-fiber suspends and lets other Fibers progress.
   - **Mixed I/O paths** (Redis + DB + HTTP in one request) can overlap when each driver supports Fiber suspension. Sequential blocking clients can't.

   So the honest framing: fledge-fiber **doesn't make a single Redis call faster, it stops a Redis call from blocking everything else**. On low-latency local Redis with no concurrency, you're paying overhead for a feature you're not using. On real-world request paths with multiple I/O touches or remote backends, the suspension wins out.

Smaller wins (removed `symfony/polyfill-php84` and `polyfill-php85`, dropped `version_compare` guards, native `array_all`/`array_any`, pipe operator in `Pipeline::then()`, persistent cURL share) trim a few additional milliseconds off bootstrap and hot paths.

### About the polyfill removal

Fledge drops `symfony/polyfill-php84` and `polyfill-php85`, plus the `version_compare` guards that branch on every request to decide whether to call native or polyfilled functions. On a single HTTP request the per-call difference between native `array_any()` and a handwritten `foreach` is in the noise (run `php artisan fledge:bench --scenario=polyfills` and you'll see the variants land within measurement jitter). It's not a "Y% faster" headline.

The real win is structural:

- Fewer `function_exists()` and `version_compare()` branches scattered through hot paths
- Less code on disk, less to autoload, smaller opcache footprint
- No PHP 8.0/8.1/8.2/8.3/8.4 compatibility branches at all, the version is fixed at 8.5+

That matters most on long-running processes (queue workers, octane, long-lived schedulers) where bootstrap cost amortizes and tighter hot paths add up over millions of calls. On a typical web request it is a small win that disappears into other variability.

### Fiber-Based Concurrency

Fledge adds a `FiberDriver` to the Concurrency facade, powered by the [Revolt](https://revolt.run) event loop and [fledge-fiber](https://github.com/webpatser/fledge-fiber). Unlike the `ProcessDriver` (which spawns child processes) or the `SyncDriver` (sequential), the `FiberDriver` provides real cooperative async I/O within a single process:

```php
use Illuminate\Support\Facades\Concurrency;

// 3 HTTP requests run concurrently, total time ≈ slowest request
$results = Concurrency::driver('fiber')->run([
    fn () => $httpClient->request(new Request('https://api1.example.com'))->getBody()->buffer(),
    fn () => $httpClient->request(new Request('https://api2.example.com'))->getBody()->buffer(),
    fn () => $httpClient->request(new Request('https://api3.example.com'))->getBody()->buffer(),
]);
```

No background process needed; the Revolt event loop runs inline within the `run()` call. Tasks using fledge-fiber async drivers (HTTP, MySQL, Redis) genuinely interleave on I/O suspension. Shared memory, no serialization overhead, works in both web requests and CLI.

Also available as a standalone package for Laravel 11/12/13: [`webpatser/laravel-fiber`](https://github.com/webpatser/laravel-fiber)

### Non-Blocking Redis (fledge-fiber driver)

Fledge ships with `fledge-fiber` as the **default Redis driver**. Every Redis call (cache reads, locks, queue operations, rate limiting) goes through a Fiber-suspending socket layer instead of a blocking one.

**This is not "faster Redis", it is "non-blocking Redis".** Those are different things, and the difference matters:

| Workload | fledge-fiber | Predis (blocking) | Winner |
|---|---|---|---|
| Single sequential `Cache::get()`, local Redis | ~32 µs | ~21 µs | Predis (less overhead per call) |
| 3 concurrent `Cache::get()` via `Concurrency::run()` | ~32 µs total | ~63 µs sequential | fledge-fiber |
| Single call to remote Redis (1-5 ms RTT) | RTT bound | RTT bound, **blocks worker** | fledge-fiber (worker stays free) |
| Mixed Redis + DB + HTTP in one request | overlapped via Fibers | strictly serial | fledge-fiber |

Numbers from `php artisan fledge:bench --scenario=redis` against a local Valkey, 10k iterations, 1k warmup. Reproduce on your own stack with `REDIS_CLIENT=fledge` and `REDIS_CLIENT=predis`.

```php
// Every Cache::get() and Redis::get() routes through fledge-fiber by default.
// No code changes needed; the driver is transparent.
Cache::get('key');        // suspends the current Fiber on socket I/O
Redis::set('key', 'val'); // same

// Inside Concurrency::run(), multiple Redis calls parallelize automatically:
Concurrency::driver('fiber')->run([
    fn () => Cache::get('user:1'),
    fn () => Cache::get('user:2'),
    fn () => Cache::get('user:3'),
]); // all 3 reads overlap on socket I/O
```

Pick fledge-fiber when your request paths touch Redis multiple times, when Redis is on another host, or when you mix Redis with other I/O. Pick `phpredis` (or stay on Predis) when you only ever do single sequential calls against a local Redis and the per-call overhead matters more than the suspension benefit.

To fall back to the synchronous phpredis C extension:

```env
REDIS_CLIENT=phpredis
```

The cache layer also includes Fiber-aware internals:
- **Lock blocking** suspends the Fiber instead of `usleep()`, letting other Fibers run
- **Failover reads** try all stores concurrently, returning the first success
- **Cluster operations** (`many()`/`putMany()`) run concurrent reads/writes via Fibers
- **Tag operations** flush chunks and write entries concurrently

## Ecosystem

Fledge is one piece of a small set of related packages:

```
webpatser/fledge                  Laravel app skeleton (composer create-project target)
  └─ webpatser/fledge-framework   This repo: Laravel 13 fork, PHP 8.5 optimized
      └─ webpatser/fledge-fiber   Single async runtime: Redis, MySQL/MariaDB/PostgreSQL,
                                  HTTP client/server, DNS, Fiber primitives, Revolt loop

Optional companions:
  webpatser/torque                Fiber-based queue worker, Horizon alternative
  webpatser/laravel-fiber         Same FiberDriver as a standalone package for
                                  Laravel 11/12/13 (no PHP 8.5 requirement)
```

The `FiberDriver` shipped in `Illuminate\Concurrency\FiberDriver` is a thin wrapper around `fledge-fiber`'s `async()` and `await()` primitives. Earlier preview builds split the async runtime across `fledge-fiber-database`, `fledge-fiber-redis`, and `fledge-fiber-http`; those are now consolidated into a single `fledge-fiber` package.

## Caveats

Worth knowing before going to production:

- **Redis Cluster** is supported by `fledge-fiber` from `v13.7.0.1` onward via Laravel's standard `clusters.*` config, with full API parity against `PhpRedisClusterConnection` / `PredisClusterConnection` (`isCluster()`, `scan()` with `node` option, `keys()` fan-out, `flushdb` fan-out). Multi-key commands must share a hash tag (`{tag}.key`), `SELECT` to a non-zero database is rejected, and MULTI/EXEC is pinned to a single slot. Sentinel and standalone Redis work too.
- **PHP 8.5 hosting** was released in November 2025. Managed-host availability is still rolling out across Forge, Vapor, Ploi, and Laravel Cloud, check your provider before committing.
- **`fledge-fiber` is a hardened fork**, not raw amphp. The async runtime started from amphp/revolt but has been consolidated, namespaced under `Fledge\Async\` and `Fledge\Fiber\`, and tuned for the Fledge use case. If you're auditing dependencies, treat it as first-party Webpatser code.
- **Active branch is `fledge-13`**, not `main`. PRs and clones aimed at framework development should target that branch.
- **Versioning uses 4 segments** (`v13.X.Y.N`). The first three match upstream Laravel exactly, the fourth is Fledge's own patch counter. See [Versioning](#versioning) below.

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
| Fiber-based concurrency driver (Revolt + fledge-fiber) | 2 | Real async I/O in `Concurrency` facade |
| fledge-fiber as default Redis driver | 5 | Non-blocking Redis I/O for all operations |
| Fiber-aware cache layer (locks, failover, tags) | 8 | Concurrent cache ops inside Fibers |
| Fiber-safe queue worker signal handling (Revolt) | 1 | Horizon/queue workers work with fiber drivers |
| Redis required dependency for cache package | 1 | Redis is a first-class citizen |

### The RFC 3986 Problem (and How Fledge Solves It)

PHP 8.5's native URI parser is strictly RFC 3986 compliant, stricter than `league/uri`. It rejects:

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

This makes Fledge a true drop-in replacement: your existing URLs keep working.

## Installation

### In an existing Laravel 13 project

`webpatser/fledge-framework` is on Packagist, so no extra repository config is needed. Require it directly with `-W` (so `with-all-dependencies` resolves the replace correctly):

```bash
composer require "webpatser/fledge-framework:^13.7" -W
```

This installs Fledge and removes `vendor/laravel/framework` from your tree (the `replace` block in Fledge's `composer.json` declares it provides `laravel/framework` and every `illuminate/*` split package). Your application code does not change, the `Illuminate\` namespace continues to work.

To switch back to upstream Laravel:

```bash
composer remove webpatser/fledge-framework
composer require "laravel/framework:^13.0" -W
```

**Verify your install** with the bundled script:

```bash
bash vendor/webpatser/fledge-framework/bin/verify-fledge-install.sh
```

It exits 0 with `OK running Fledge framework v13.X.Y.N` if you are on Fledge, or 1 with a switch hint if you are still on stock Laravel.

### Why `composer require laravel/framework` does NOT pull in Fledge

You might expect `composer require "laravel/framework:^13.3"` to pick up Fledge once the repository is registered. **It does not, even with the VCS repository configured.** Composer's resolver only honors `replace` declarations during transitive dependency resolution, not for top-level requires. A direct `require laravel/framework:...` always installs the upstream `laravel/framework` package, and Fledge stays unused on disk if you also `require` it.

That is why the canonical install line targets `webpatser/fledge-framework` directly. If you see `vendor/laravel/framework` in your tree after switching, you are running stock Laravel; the verify script above will catch that.

### Constraint compatibility

All standard Composer constraint patterns resolve to the latest Fledge tag (`v13.15.0.1` as of 2026-06-12):

| Constraint | Resolves to | Notes |
|------------|------------|-------|
| `^13.3` | v13.15.0.1 | Recommended, accepts any 13.x release |
| `^13.15` | v13.15.0.1 | Pins to current minor |
| `~13.15.0` | v13.15.0.1 | Pins to 13.15.x patches and Fledge revisions |
| `13.15.*` | v13.15.0.1 | Wildcard, identical resolution |
| `^13.15.0.1` | v13.15.0.1 | Pin to a specific Fledge revision |
| `~13.15.0.1` | v13.15.0.1 | Same, accepts higher Fledge patches |
| `>=13.0` | v13.15.0.1 | Open-ended |
| `dev-fledge-13` | (does not resolve cleanly) | Dev branches need a `branch-alias` to satisfy `^13.0` constraints from other Laravel packages |

Use `^13.7` in production. The 4-segment `v13.X.Y.N` versioning is fully Composer-compatible: the resolver treats the fourth segment as a regular patch component.

### From scratch (framework development)

To work on the Fledge framework itself (not consume it as a dependency):

```bash
# Clone the framework fork directly
git clone -b fledge-13 https://github.com/webpatser/fledge-framework
cd fledge-framework
composer install
vendor/bin/phpunit
```

Active development happens on the `fledge-13` branch, not `main`. Released tags follow the `v13.X.Y.N` pattern where the first three segments match upstream Laravel and `N` is Fledge's own patch counter.

### From scratch (full app skeleton)

To start a new Laravel app with Fledge baked in, use the [skeleton](https://github.com/webpatser/fledge). The skeleton is currently distributed via Git, not Packagist, so clone it directly:

```bash
git clone https://github.com/webpatser/fledge my-app
cd my-app
composer install
cp .env.example .env
php artisan key:generate
php artisan serve
```

(Once the skeleton is published to Packagist, `composer create-project webpatser/fledge my-app` will replace the clone step. Watch [the skeleton repo](https://github.com/webpatser/fledge) for status.)

## Compatibility

- Same `Illuminate\` namespace, all Laravel packages work unchanged
- Same API, no code changes needed in your application
- Full framework test suite passing under `--fail-on-deprecation`, including against symfony 8.1 (Fledge carries the header-bag-constructor fix ahead of upstream Laravel)

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

These 4 test failures exist on **vanilla Laravel 13 running on PHP 8.5**, they are not caused by Fledge:

| Test | Root Cause |
|------|------------|
| `RedisConnectionTest::testItScansForKeys` | Predis cursor format incompatibility |
| `RedisConnectionTest::testItHscansForKeys` | Predis cursor format incompatibility |
| `RedisConnectionTest::testItZscansForKeys` | Predis cursor format incompatibility |
| `RedisConnectionTest::testItSscansForKeys` | Predis cursor format incompatibility |

## Credits

**All credit goes to [Taylor Otwell](https://github.com/taylorotwell) and the [Laravel](https://laravel.com) team.** This project is built entirely on their work. Fledge is not a fork intended to compete with Laravel; it's an optimization layer for teams already running PHP 8.5.

## License

MIT, same as Laravel.
