<?php

namespace Illuminate\Cache\Concerns;

use Fiber;
use Fledge\Loop;
use Illuminate\Support\Sleep;

trait SuspendsFibers
{
    /**
     * Suspend execution for the given number of milliseconds.
     *
     * Uses Fiber suspension when inside a Fiber context, allowing other
     * Fibers to continue. Falls back to Sleep::usleep() in the main context.
     */
    protected function suspendForMilliseconds(int $milliseconds): void
    {
        if (Fiber::getCurrent() !== null) {
            $suspension = Loop::getSuspension();
            Loop::delay($milliseconds / 1000, static fn () => $suspension->resume());
            $suspension->suspend();
        } else {
            Sleep::usleep($milliseconds * 1000);
        }
    }

    /**
     * Determine if we are currently inside a Fiber context.
     */
    protected function inFiber(): bool
    {
        return Fiber::getCurrent() !== null;
    }
}
