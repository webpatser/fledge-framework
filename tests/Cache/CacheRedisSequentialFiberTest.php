<?php

namespace Illuminate\Tests\Cache;

use Fiber;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\RedisTaggedCache;
use Illuminate\Cache\RedisTagSet;
use Illuminate\Support\LazyCollection;
use Mockery;
use PHPUnit\Framework\TestCase;

class CacheRedisSequentialFiberTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testTagSetAddEntryRunsZaddSequentiallyOnTheCallingFiber()
    {
        $connection = $this->recordingConnection();

        $store = Mockery::mock(RedisStore::class);
        $store->allows('connection')->andReturn($connection);
        $store->allows('getPrefix')->andReturn('prefix:');

        $tagSet = new RedisTagSet($store, ['a', 'b', 'c']);

        $outer = null;

        $fiber = new Fiber(function () use ($tagSet, &$outer) {
            $outer = Fiber::getCurrent();

            $tagSet->addEntry('item', 60);
        });

        $fiber->start();

        $this->assertTrue($fiber->isTerminated());
        $this->assertCount(3, $connection->calls);
        $this->assertSame(
            ['prefix:tag:a:entries', 'prefix:tag:b:entries', 'prefix:tag:c:entries'],
            array_column($connection->calls, 'key')
        );

        foreach ($connection->calls as $call) {
            $this->assertSame($outer, $call['fiber'], 'zadd must run on the calling fiber, not on a spawned one.');
        }
    }

    public function testTagSetAddEntryRunsZaddSequentiallyOutsideAFiber()
    {
        $connection = $this->recordingConnection();

        $store = Mockery::mock(RedisStore::class);
        $store->allows('connection')->andReturn($connection);
        $store->allows('getPrefix')->andReturn('prefix:');

        (new RedisTagSet($store, ['a', 'b']))->addEntry('item', 60);

        $this->assertCount(2, $connection->calls);
        $this->assertNull($connection->calls[0]['fiber']);
    }

    public function testTaggedCacheFlushValuesRunsDelChunksSequentiallyOnTheCallingFiber()
    {
        $connection = $this->recordingConnection();

        $store = Mockery::mock(RedisStore::class);
        $store->allows('connection')->andReturn($connection);
        $store->allows('getPrefix')->andReturn('prefix:');

        $tags = Mockery::mock(RedisTagSet::class);
        $tags->allows('getNamespace')->andReturn('namespace');
        $tags->allows('getNames')->andReturn([]);
        $tags->allows('entries')->andReturn(new LazyCollection(function () {
            for ($i = 0; $i < 2500; $i++) {
                yield "key-{$i}";
            }
        }));

        $cache = new RedisTaggedCache($store, $tags);

        $outer = null;

        $fiber = new Fiber(function () use ($cache, &$outer) {
            $outer = Fiber::getCurrent();

            (fn () => $this->flushValues())->call($cache);
        });

        $fiber->start();

        $this->assertTrue($fiber->isTerminated());
        $this->assertCount(3, $connection->calls);
        $this->assertSame([1000, 1000, 500], array_column($connection->calls, 'count'));

        foreach ($connection->calls as $call) {
            $this->assertSame($outer, $call['fiber'], 'del must run on the calling fiber, not on a spawned one.');
        }
    }

    /**
     * A connection double that records every command with the fiber that issued it.
     */
    private function recordingConnection(): object
    {
        return new class
        {
            public array $calls = [];

            public function zadd(string $key, ...$arguments): void
            {
                $this->calls[] = ['key' => $key, 'fiber' => Fiber::getCurrent()];
            }

            public function del(...$keys): void
            {
                $this->calls[] = ['count' => count($keys), 'fiber' => Fiber::getCurrent()];
            }
        };
    }
}
