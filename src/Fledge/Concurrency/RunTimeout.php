<?php

namespace Fledge\Concurrency;

use Carbon\CarbonInterval;
use Fledge\Async\Cancellation;
use Fledge\Async\DeferredCancellation;
use Fledge\Async\TimeoutException;
use Fledge\Loop;

/**
 * The timeout of one FiberDriver run.
 *
 * Unlike TimeoutCancellation, the timer stays referenced: it holds the event
 * loop open, so a run whose tasks all wait on something unreferenced (or on a
 * future nobody completes) still ends in a CancelledException at the timeout
 * instead of the loop stopping without resuming the call. The caller stops the
 * timer as soon as the run is over, so a run that finishes early does not keep
 * the loop alive.
 *
 * @internal
 */
final class RunTimeout
{
    /**
     * Start the timer, returning the cancellation it requests and its callback id; both null without a timeout.
     *
     * @return array{?Cancellation, ?string}
     */
    public static function start(CarbonInterval|int|null $timeout): array
    {
        if ($timeout === null) {
            return [null, null];
        }

        $source = new DeferredCancellation;

        $timer = Loop::delay(
            $timeout instanceof CarbonInterval ? $timeout->totalSeconds : $timeout,
            static fn () => $source->cancel(new TimeoutException('Operation timed out')),
        );

        return [$source->getCancellation(), $timer];
    }

    /**
     * Stop the timer, if there is one and it has not fired yet.
     */
    public static function stop(?string $timer): void
    {
        if ($timer !== null) {
            Loop::cancel($timer);
        }
    }
}
