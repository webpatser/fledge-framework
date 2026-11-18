<?php

namespace Illuminate\Tests\Cache;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Cache\FailoverStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\CanFlushLocks;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Events\Dispatcher;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Fledge\Async\async;

class CacheFailoverStoreTest extends TestCase
{
    use VerifiesDoubles;

    public function testImplementsCanFlushLocks()
    {
        $store = $this->makeFailoverStore([]);

        $this->assertInstanceOf(CanFlushLocks::class, $store);
    }

    public function testFlushLocksCallsFlushLocksOnAllBackingStores()
    {
        $storeA = new ArrayStore;
        $storeB = new ArrayStore;

        $storeA->lock('lock-a', 60)->get();
        $storeB->lock('lock-b', 60)->get();

        $cache = Double::for(CacheManager::class);
        $cache->expects('store')->with('store-a')->returns(new Repository($storeA));
        $cache->expects('store')->with('store-b')->returns(new Repository($storeB));

        $failover = new FailoverStore($cache, new Dispatcher, ['store-a', 'store-b']);

        $result = $failover->flushLocks();

        $this->assertTrue($result);
        $this->assertEmpty($storeA->locks);
        $this->assertEmpty($storeB->locks);
    }

    public function testFlushLocksReturnsTrueWhenNoStoreSupportsIt()
    {
        $store = $this->makeFailoverStore([]);

        $this->assertTrue($store->flushLocks());
    }

    public function testPrimaryStoreIsAuthoritativeForConcurrentReads()
    {
        $primary = new ArrayStore;
        $secondary = new ArrayStore;

        $primary->put('key', 'primary-value', 60);
        $secondary->put('key', 'stale-value', 60);

        $cache = Double::for(CacheManager::class);
        $cache->allows('store')->with('primary')->returns(new Repository($primary));
        $cache->allows('store')->with('secondary')->returns(new Repository($secondary));

        $failover = new FailoverStore($cache, new Dispatcher, ['primary', 'secondary']);

        // Read inside a Fiber, where Fledge request code usually runs.
        $value = async(fn () => $failover->get('key'))->await();

        $this->assertSame('primary-value', $value);
    }

    public function testPrimaryCacheMissIsNotShadowedBySecondary()
    {
        $primary = new ArrayStore;
        $secondary = new ArrayStore;

        // Primary has no value for the key (a cache miss); secondary does. The
        // authoritative primary miss must win so throttle/rate-limiter state is
        // not corrupted by a faster secondary returning a present value.
        $secondary->put('key', 'stale-value', 60);

        $cache = Double::for(CacheManager::class);
        $cache->allows('store')->with('primary')->returns(new Repository($primary));
        $cache->allows('store')->with('secondary')->returns(new Repository($secondary));

        $failover = new FailoverStore($cache, new Dispatcher, ['primary', 'secondary']);

        $value = async(fn () => $failover->get('key'))->await();

        $this->assertNull($value);
    }

    public function testSecondaryIsConsultedWhenPrimaryReadThrows()
    {
        $primary = Double::for(Repository::class);
        $primary->expects('get')->with('key')->throws(new RuntimeException('primary down'));

        $secondary = new ArrayStore;
        $secondary->put('key', 'secondary-value', 60);

        $cache = Double::for(CacheManager::class);
        $cache->allows('store')->with('primary')->returns($primary);
        $cache->allows('store')->with('secondary')->returns(new Repository($secondary));

        $failedOver = 0;
        $events = new Dispatcher;
        $events->listen(CacheFailedOver::class, function () use (&$failedOver) {
            $failedOver++;
        });

        $failover = new FailoverStore($cache, $events, ['primary', 'secondary']);

        $value = async(fn () => $failover->get('key'))->await();

        $this->assertSame('secondary-value', $value);
        $this->assertSame(1, $failedOver);
    }

    public function testHealthyPrimaryReadInsideFiberNeverContactsSecondary()
    {
        [$failover, $primary, $secondary] = $this->makeCountingFailover();

        $primary->put('key', 'primary-value', 60);
        $secondary->put('key', 'stale-value', 60);
        $primary->resetCalls();
        $secondary->resetCalls();

        $this->assertSame('primary-value', async(fn () => $failover->get('key'))->await());
        $this->assertSame(['key' => 'primary-value'], async(fn () => $failover->many(['key']))->await());
        $this->assertIsString(async(fn () => $failover->getPrefix())->await());

        $this->assertSame(['get' => 1, 'many' => 1, 'getPrefix' => 1], $primary->calls);
        $this->assertSame([], $secondary->calls);
    }

    public function testHealthyPrimaryReadOutsideFiberNeverContactsSecondary()
    {
        [$failover, $primary, $secondary] = $this->makeCountingFailover();

        $primary->put('key', 'primary-value', 60);
        $primary->resetCalls();

        $this->assertSame('primary-value', $failover->get('key'));
        $this->assertSame(['key' => 'primary-value'], $failover->many(['key']));

        $this->assertSame(['get' => 1, 'many' => 1], $primary->calls);
        $this->assertSame([], $secondary->calls);
    }

    public function testFailingPrimaryInsideFiberFallsBackOnceAndRecovers()
    {
        $events = new Dispatcher;
        $failedOver = [];
        $events->listen(CacheFailedOver::class, function (CacheFailedOver $event) use (&$failedOver) {
            $failedOver[] = $event->storeName;
        });

        [$failover, $primary, $secondary] = $this->makeCountingFailover($events);

        $primary->put('key', 'primary-value', 60);
        $secondary->put('key', 'secondary-value', 60);
        $primary->resetCalls();
        $secondary->resetCalls();

        $primary->failing = true;

        $this->assertSame('secondary-value', async(fn () => $failover->get('key'))->await());
        $this->assertSame('secondary-value', async(fn () => $failover->get('key'))->await());

        $this->assertSame(['get' => 2], $primary->calls);
        $this->assertSame(['get' => 2], $secondary->calls);
        $this->assertSame(['primary'], $failedOver);

        $primary->failing = false;

        $this->assertSame('primary-value', async(fn () => $failover->get('key'))->await());

        $this->assertSame(['get' => 3], $primary->calls);
        $this->assertSame(['get' => 2], $secondary->calls);
        $this->assertSame(['primary'], $failedOver);
    }

    public function testAllStoresFailingInsideFiberSurfacesLastException()
    {
        [$failover, $primary, $secondary] = $this->makeCountingFailover();

        $primary->failing = true;
        $secondary->failing = true;

        try {
            async(fn () => $failover->get('key'))->await();
            $this->fail('Expected the last store exception to surface.');
        } catch (RuntimeException $e) {
            $this->assertSame('secondary down', $e->getMessage());
        }

        $this->assertSame(['get' => 1], $primary->calls);
        $this->assertSame(['get' => 1], $secondary->calls);
    }

    /**
     * Build a failover store over two counting array stores, resolved through a real cache manager.
     *
     * @return array{0: FailoverStore, 1: CountingFailoverTestStore, 2: CountingFailoverTestStore}
     */
    protected function makeCountingFailover(?Dispatcher $events = null): array
    {
        $stores = [
            'primary' => new CountingFailoverTestStore('primary'),
            'secondary' => new CountingFailoverTestStore('secondary'),
        ];

        $app = new Container;
        $app->instance('config', new Config([
            'cache' => [
                'stores' => [
                    'primary' => ['driver' => 'counting'],
                    'secondary' => ['driver' => 'counting'],
                ],
            ],
        ]));

        $manager = new CacheManager($app);
        $manager->extend('counting', function ($app, $config) use ($stores) {
            return new Repository($stores[$config['store']]);
        });

        $failover = new FailoverStore($manager, $events ?? new Dispatcher, ['primary', 'secondary']);

        return [$failover, $stores['primary'], $stores['secondary']];
    }

    protected function makeFailoverStore(array $stores): FailoverStore
    {
        return new FailoverStore(
            Double::for(CacheManager::class),
            new Dispatcher,
            $stores
        );
    }
}

/**
 * An array store that counts every call made to it and can be switched to fail.
 */
class CountingFailoverTestStore implements Store
{
    public array $calls = [];

    public bool $failing = false;

    protected ArrayStore $store;

    public function __construct(protected string $name)
    {
        $this->store = new ArrayStore;
    }

    public function resetCalls(): void
    {
        $this->calls = [];
    }

    protected function record(string $method, array $arguments): mixed
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;

        if ($this->failing) {
            throw new RuntimeException("{$this->name} down");
        }

        return $this->store->{$method}(...$arguments);
    }

    public function get($key)
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function many(array $keys)
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function put($key, $value, $seconds)
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function putMany(array $values, $seconds)
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function increment($key, $value = 1)
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function decrement($key, $value = 1)
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function forever($key, $value)
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function touch($key, $seconds)
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function forget($key)
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function flush()
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function getPrefix()
    {
        return $this->record(__FUNCTION__, func_get_args());
    }
}
