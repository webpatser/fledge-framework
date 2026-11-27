<?php

namespace Illuminate\Tests\Cache;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Time\Duration;

class CacheDurationTtlTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testDurationTtlExpiresLikeTheSameNumberOfSeconds()
    {
        $cache = new Repository(new ArrayStore);

        Carbon::setTestNow('2000-01-01 00:00:00.500');
        $cache->put('duration', 'value', Duration::fromSeconds(10));
        $cache->put('seconds', 'value', 10);

        Carbon::setTestNow('2000-01-01 00:00:10.499');
        $this->assertSame('value', $cache->get('duration'));
        $this->assertSame('value', $cache->get('seconds'));

        Carbon::setTestNow('2000-01-01 00:00:10.500');
        $this->assertNull($cache->get('duration'));
        $this->assertNull($cache->get('seconds'));
    }

    public function testFractionalDurationTtlIsRoundedUp()
    {
        $cache = new Repository(new ArrayStore);

        Carbon::setTestNow('2000-01-01 00:00:00.500');
        $cache->put('key', 'value', Duration::fromMilliseconds(1500));

        Carbon::setTestNow('2000-01-01 00:00:02.499');
        $this->assertSame('value', $cache->get('key'));

        Carbon::setTestNow('2000-01-01 00:00:02.500');
        $this->assertNull($cache->get('key'));
    }

    public function testRememberAndAcceptDurationTtl()
    {
        $cache = new Repository(new ArrayStore);

        Carbon::setTestNow('2000-01-01 00:00:00.500');

        $this->assertSame('first', $cache->remember('remembered', Duration::fromSeconds(5), fn () => 'first'));
        $this->assertTrue($cache->add('added', 'value', Duration::fromSeconds(5)));

        Carbon::setTestNow('2000-01-01 00:00:05.499');
        $this->assertSame('first', $cache->remember('remembered', Duration::fromSeconds(5), fn () => 'second'));
        $this->assertSame('value', $cache->get('added'));

        Carbon::setTestNow('2000-01-01 00:00:05.500');
        $this->assertNull($cache->get('added'));
    }

    public function testZeroAndNegatedDurationTtlStoreNothing()
    {
        $cache = new Repository(new ArrayStore);

        $cache->put('zero', 'value', Duration::fromSeconds(0));
        $cache->put('negated', 'value', Duration::fromSeconds(5)->negate());

        $this->assertNull($cache->get('zero'));
        $this->assertNull($cache->get('negated'));
        $this->assertFalse($cache->add('added', 'value', Duration::fromSeconds(5)->negate()));
    }
}
