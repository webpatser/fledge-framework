<?php

namespace Illuminate\Support\Concerns;

use Fiber;
use Revolt\EventLoop;

/**
 * Fledge addition: lets sleeps that happen inside a Revolt-driven Fiber
 * suspend that Fiber on the event loop instead of blocking the whole
 * process, so other Fibers of the worker keep running while one waits.
 */
trait SuspendsFibers
{
    /**
     * Determine if we are inside a Fiber that can be suspended on the Revolt event loop.
     */
    protected function inFiber(): bool
    {
        return Fiber::getCurrent() !== null && class_exists(EventLoop::class);
    }

    /**
     * Suspend the current Fiber for the given number of microseconds.
     */
    protected function suspendForMicroseconds(int $microseconds): void
    {
        if ($microseconds <= 0) {
            return;
        }

        $suspension = EventLoop::getSuspension();

        EventLoop::delay($microseconds / 1_000_000, static fn () => $suspension->resume());

        $suspension->suspend();
    }
}
