<?php

namespace Illuminate\Tests\Cache;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Cache\FailoverStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\CanFlushLocks;
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

    protected function makeFailoverStore(array $stores): FailoverStore
    {
        return new FailoverStore(
            Double::for(CacheManager::class),
            new Dispatcher,
            $stores
        );
    }
}
