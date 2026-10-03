<?php

namespace Illuminate\Tests\Support;

use Illuminate\Support\Sleep;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;

class SleepFiberTest extends TestCase
{
    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }

    public function testSleepsInsideFibersOverlap()
    {
        $start = hrtime(true);

        await([
            async(function () {
                Sleep::for(60)->milliseconds();
            }),
            async(function () {
                Sleep::for(60)->milliseconds();
            }),
        ]);

        $elapsed = (hrtime(true) - $start) / 1_000_000;

        $this->assertGreaterThanOrEqual(60, $elapsed);
        $this->assertLessThan(110, $elapsed);
    }

    public function testRetryHelperBackoffOverlapsInsideFibers()
    {
        $task = function () {
            return retry(2, function ($attempt) {
                if ($attempt === 1) {
                    throw new RuntimeException('Retry me.');
                }

                return $attempt;
            }, 60);
        };

        $start = hrtime(true);

        $results = await([async($task), async($task)]);

        $elapsed = (hrtime(true) - $start) / 1_000_000;

        $this->assertSame([2, 2], array_values($results));
        $this->assertLessThan(110, $elapsed);
    }

    public function testSleepOutsideFiberStillBlocks()
    {
        $start = hrtime(true);

        Sleep::for(30)->milliseconds();

        $elapsed = (hrtime(true) - $start) / 1_000_000;

        $this->assertGreaterThanOrEqual(30, $elapsed);
    }

    public function testFakeSleepInsideFiberDoesNotWait()
    {
        Sleep::fake();

        $start = hrtime(true);

        await([
            async(function () {
                Sleep::for(5)->seconds();
            }),
        ]);

        $elapsed = (hrtime(true) - $start) / 1_000_000;

        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 5);
        $this->assertLessThan(50, $elapsed);
    }

    public function testWhileLoopIsHonouredInsideFiber()
    {
        $counter = 0;

        $start = hrtime(true);

        await([
            async(function () use (&$counter) {
                Sleep::for(20)->milliseconds()->while(function () use (&$counter) {
                    return $counter++ < 3;
                });
            }),
        ]);

        $elapsed = (hrtime(true) - $start) / 1_000_000;

        $this->assertGreaterThanOrEqual(60, $elapsed);
        $this->assertSame(4, $counter);
    }
}
