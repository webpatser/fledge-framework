<?php

namespace Illuminate\Tests\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\InteractsWithTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Time\Duration;

class SupportInteractsWithTimeDurationTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[DataProvider('durations')]
    public function testDurationIsRoundedUpToWholeSeconds(Duration $duration, int $expected)
    {
        $subject = new InteractsWithTimeDurationStub;

        $this->assertSame($expected, $subject->seconds($duration));
        $this->assertSame($expected, $subject->seconds($expected));
    }

    #[DataProvider('durations')]
    public function testAvailableAtMatchesTheSameNumberOfSeconds(Duration $duration, int $expected)
    {
        Carbon::setTestNow(Carbon::now());

        $subject = new InteractsWithTimeDurationStub;

        $this->assertSame($subject->availableAtFor($expected), $subject->availableAtFor($duration));
        $this->assertSame(Carbon::now()->getTimestamp() + $expected, $subject->availableAtFor($duration));
    }

    public static function durations(): array
    {
        return [
            'ninety seconds' => [Duration::fromSeconds(90), 90],
            'one and a half seconds' => [Duration::fromMilliseconds(1500), 2],
            'one nanosecond' => [Duration::fromNanoseconds(1), 1],
            'zero' => [Duration::fromSeconds(0), 0],
            'negated seconds' => [Duration::fromSeconds(5)->negate(), 0],
            'negated milliseconds' => [Duration::fromMilliseconds(1500)->negate(), 0],
        ];
    }

    public function testDateIntervalAndDateTimeStillWork()
    {
        Carbon::setTestNow($now = Carbon::now());

        $subject = new InteractsWithTimeDurationStub;

        $this->assertSame(30, $subject->seconds($now->copy()->addSeconds(30)));
        $this->assertSame($now->getTimestamp() + 30, $subject->availableAtFor(new \DateInterval('PT30S')));
    }
}

class InteractsWithTimeDurationStub
{
    use InteractsWithTime;

    public function seconds($delay): int
    {
        return $this->secondsUntil($delay);
    }

    public function availableAtFor($delay): int
    {
        return $this->availableAt($delay);
    }
}
