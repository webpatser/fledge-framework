<?php

namespace Illuminate\Tests\Cache;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Cache\FailoverStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\CanFlushLocks;
use Illuminate\Contracts\Events\Dispatcher;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Fledge\Async\async;

class CacheFailoverStoreTest extends TestCase
{
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

        $cache = m::mock(CacheManager::class);
        $cache->shouldReceive('store')->with('store-a')->andReturn(new Repository($storeA));
        $cache->shouldReceive('store')->with('store-b')->andReturn(new Repository($storeB));

        $failover = new FailoverStore($cache, m::mock(Dispatcher::class), ['store-a', 'store-b']);

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

        $cache = m::mock(CacheManager::class);
        $cache->shouldReceive('store')->with('primary')->andReturn(new Repository($primary));
        $cache->shouldReceive('store')->with('secondary')->andReturn(new Repository($secondary));

        $failover = new FailoverStore($cache, m::mock(Dispatcher::class), ['primary', 'secondary']);

        // Read inside a Fiber so the concurrent read path is exercised.
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

        $cache = m::mock(CacheManager::class);
        $cache->shouldReceive('store')->with('primary')->andReturn(new Repository($primary));
        $cache->shouldReceive('store')->with('secondary')->andReturn(new Repository($secondary));

        $failover = new FailoverStore($cache, m::mock(Dispatcher::class), ['primary', 'secondary']);

        $value = async(fn () => $failover->get('key'))->await();

        $this->assertNull($value);
    }

    public function testSecondaryIsConsultedWhenPrimaryReadThrows()
    {
        $primary = m::mock(Repository::class);
        $primary->shouldReceive('get')->with('key')->andThrow(new RuntimeException('primary down'));

        $secondary = new ArrayStore;
        $secondary->put('key', 'secondary-value', 60);

        $cache = m::mock(CacheManager::class);
        $cache->shouldReceive('store')->with('primary')->andReturn($primary);
        $cache->shouldReceive('store')->with('secondary')->andReturn(new Repository($secondary));

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->once()->with(m::type(CacheFailedOver::class));

        $failover = new FailoverStore($cache, $events, ['primary', 'secondary']);

        $value = async(fn () => $failover->get('key'))->await();

        $this->assertSame('secondary-value', $value);
    }

    protected function makeFailoverStore(array $stores): FailoverStore
    {
        return new FailoverStore(
            m::mock(CacheManager::class),
            m::mock(Dispatcher::class),
            $stores
        );
    }
}
