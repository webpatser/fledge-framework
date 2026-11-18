<?php

namespace Illuminate\Tests\Integration\Concurrency;

use Carbon\CarbonInterval;
use Fledge\Async\Cancellation;
use Fledge\Async\CancelledException;
use Fledge\Async\DeferredFuture;
use Fledge\Async\TimeoutException;
use Fledge\Concurrency\TaskCancellation;
use Fledge\Concurrency\TaskConnectionScope;
use Illuminate\Concurrency\FiberDriver;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;

use function Fledge\Async\async;
use function Fledge\Async\delay;

class FiberDriverCancellationTest extends TestCase
{
    public function test_a_task_passing_the_cancellation_to_delay_stops_at_the_timeout()
    {
        $log = [];
        $thrown = null;

        $unhandled = $this->captureLoopErrors(function () use (&$log, &$thrown) {
            $settled = new DeferredFuture;

            try {
                (new FiberDriver)->run([
                    function () use (&$log, $settled) {
                        try {
                            delay(1, cancellation: TaskCancellation::current());
                            $log[] = 'after';
                        } catch (CancelledException $e) {
                            $log[] = 'cancelled';

                            throw $e;
                        } finally {
                            $log[] = 'finally';
                            $settled->complete();
                        }
                    },
                ], CarbonInterval::milliseconds(30));
            } catch (CancelledException $e) {
                $thrown = $e;
            }

            // The task unwinds on the next loop ticks, long before its delay would end.
            $settled->getFuture()->await();
            $log[] = 'checked';
        });

        $this->assertInstanceOf(CancelledException::class, $thrown);
        $this->assertInstanceOf(TimeoutException::class, $thrown->getPrevious());
        $this->assertSame(['cancelled', 'finally', 'checked'], $log);
        $this->assertSame([], $unhandled);
    }

    public function test_a_task_awaiting_a_future_with_the_cancellation_stops_at_the_timeout()
    {
        $log = [];
        $thrown = null;

        $unhandled = $this->captureLoopErrors(function () use (&$log, &$thrown) {
            // Nobody completes this future and nothing else holds the event loop open:
            // the timeout alone must keep the loop alive until it fires.
            $never = new DeferredFuture;
            $settled = new DeferredFuture;

            try {
                (new FiberDriver)->run([
                    function () use (&$log, $never, $settled) {
                        try {
                            $never->getFuture()->await(TaskCancellation::current());
                            $log[] = 'after';
                        } finally {
                            $log[] = 'finally';
                            $settled->complete();
                        }
                    },
                ], CarbonInterval::milliseconds(30));
                $this->fail('Expected the timeout to fire.');
            } catch (CancelledException $e) {
                $thrown = $e;
            }

            $settled->getFuture()->await();
            $log[] = 'checked';
        });

        $this->assertInstanceOf(TimeoutException::class, $thrown->getPrevious());
        $this->assertSame(['finally', 'checked'], $log);
        $this->assertSame([], $unhandled);
    }

    public function test_a_task_polling_throw_if_requested_stops_after_the_timeout()
    {
        $polls = 0;
        $log = [];
        $thrown = null;

        $unhandled = $this->captureLoopErrors(function () use (&$polls, &$log, &$thrown) {
            $settled = new DeferredFuture;

            try {
                (new FiberDriver)->run([
                    function () use (&$polls, &$log, $settled) {
                        try {
                            while (true) {
                                TaskCancellation::current()->throwIfRequested();
                                $polls++;
                                delay(0.005);
                            }
                        } catch (CancelledException $e) {
                            $log[] = 'stopped';

                            throw $e;
                        } finally {
                            $settled->complete();
                        }
                    },
                ], CarbonInterval::milliseconds(30));
                $this->fail('Expected the timeout to fire.');
            } catch (CancelledException $e) {
                $thrown = $e;
            }

            $settled->getFuture()->await();
        });

        $this->assertInstanceOf(TimeoutException::class, $thrown->getPrevious());
        $this->assertGreaterThan(0, $polls);
        $this->assertSame(['stopped'], $log);
        $this->assertSame([], $unhandled);
    }

    public function test_a_failing_task_does_not_cancel_a_cancellable_sibling()
    {
        $sibling = null;
        $thrown = null;

        $unhandled = $this->captureLoopErrors(function () use (&$sibling, &$thrown) {
            try {
                (new FiberDriver)->run([
                    fn () => throw new RuntimeException('boom'),
                    function () use (&$sibling) {
                        delay(0.03, cancellation: TaskCancellation::current());
                        $sibling = TaskCancellation::current()->isRequested() ? 'cancelled' : 'done';
                    },
                ], CarbonInterval::seconds(5));
                $this->fail('Expected the failing task to throw.');
            } catch (RuntimeException $e) {
                $thrown = $e;
            }
        });

        // Like the stock ProcessDriver: the whole pool runs to the end, then the failure is thrown.
        $this->assertSame('boom', $thrown->getMessage());
        $this->assertSame('done', $sibling);
        $this->assertSame([], $unhandled);
    }

    public function test_a_cancelled_task_releases_its_connection_scope()
    {
        $unhandled = $this->captureLoopErrors(function () {
            $settled = new DeferredFuture;

            try {
                (new FiberDriver)->run([
                    function () use ($settled) {
                        try {
                            delay(1, cancellation: TaskCancellation::current());
                        } finally {
                            $settled->complete();
                        }
                    },
                ], CarbonInterval::milliseconds(30));
                $this->fail('Expected the timeout to fire.');
            } catch (CancelledException) {
                //
            }

            // The scope is released synchronously after the task's finally, before this await resumes.
            $settled->getFuture()->await();
        });

        $this->assertSame(0, TaskConnectionScope::$active);
        $this->assertSame([], $unhandled);
    }

    public function test_a_task_ignoring_the_cancellation_keeps_running_and_its_result_is_discarded()
    {
        $finished = false;

        $unhandled = $this->captureLoopErrors(function () use (&$finished) {
            $settled = new DeferredFuture;

            try {
                (new FiberDriver)->run([
                    function () use (&$finished, $settled) {
                        try {
                            delay(0.08);
                            $finished = true;

                            return 'discarded';
                        } finally {
                            $settled->complete();
                        }
                    },
                ], CarbonInterval::milliseconds(20));
                $this->fail('Expected the timeout to fire.');
            } catch (CancelledException $e) {
                $this->assertInstanceOf(TimeoutException::class, $e->getPrevious());
                $this->assertFalse($finished);
            }

            $settled->getFuture()->await();
        });

        $this->assertTrue($finished);
        $this->assertSame([], $unhandled);
    }

    public function test_a_call_finishing_early_does_not_keep_the_event_loop_alive()
    {
        $results = (new FiberDriver)->run([fn () => 'done'], CarbonInterval::seconds(30));

        $this->assertSame(['done'], $results);

        // With the timeout's timer still pending, this run would wait the full 30 seconds.
        $started = hrtime(true);
        EventLoop::run();

        $this->assertLessThan(5, (hrtime(true) - $started) / 1e9);
    }

    public function test_the_cancellation_is_not_requested_while_the_task_runs_inside_the_timeout()
    {
        $results = (new FiberDriver)->run([
            fn () => TaskCancellation::current()?->isRequested(),
            function () {
                delay(0.01, cancellation: TaskCancellation::current());

                return 'done';
            },
        ], CarbonInterval::seconds(5));

        $this->assertSame([false, 'done'], $results);
    }

    public function test_the_accessor_returns_null_outside_a_task()
    {
        $this->assertNull(TaskCancellation::current());

        $this->assertNull(async(fn () => TaskCancellation::current())->await());

        $inside = (new FiberDriver)->run([fn () => TaskCancellation::current()]);

        $this->assertInstanceOf(Cancellation::class, $inside[0]);
        $this->assertNull(TaskCancellation::current());
    }

    public function test_tasks_without_a_timeout_also_get_a_cancellation()
    {
        $results = (new FiberDriver)->runWithoutConnectionScope([
            fn () => TaskCancellation::current() instanceof Cancellation,
        ]);

        $this->assertSame([true], $results);
    }

    public function test_a_nested_run_gets_its_own_cancellation_and_the_outer_one_cancels_it()
    {
        $log = [];
        $seen = [];

        $unhandled = $this->captureLoopErrors(function () use (&$log, &$seen) {
            $settled = new DeferredFuture;

            try {
                (new FiberDriver)->run([
                    function () use (&$log, &$seen, $settled) {
                        $seen['outer'] = TaskCancellation::current();

                        try {
                            (new FiberDriver)->run([
                                function () use (&$log, &$seen) {
                                    $seen['inner'] = TaskCancellation::current();

                                    try {
                                        delay(1, cancellation: TaskCancellation::current());
                                        $log[] = 'inner after';
                                    } finally {
                                        $log[] = 'inner finally';
                                    }
                                },
                            ]);
                            $log[] = 'outer after';
                        } finally {
                            $log[] = 'outer finally';
                            $settled->complete();
                        }
                    },
                ], CarbonInterval::milliseconds(30));
                $this->fail('Expected the timeout to fire.');
            } catch (CancelledException) {
                //
            }

            $settled->getFuture()->await();
        });

        $this->assertNotSame($seen['outer'], $seen['inner']);
        $this->assertTrue($seen['inner']->isRequested());
        $this->assertSame(['inner finally', 'outer finally'], $log);
        $this->assertSame([], $unhandled);
    }

    public function test_an_inner_timeout_does_not_cancel_the_outer_task()
    {
        $results = (new FiberDriver)->run([
            function () {
                try {
                    (new FiberDriver)->run([
                        fn () => delay(1, cancellation: TaskCancellation::current()),
                    ], CarbonInterval::milliseconds(20));
                } catch (CancelledException) {
                    //
                }

                delay(0.03);

                return TaskCancellation::current()->isRequested();
            },
        ], CarbonInterval::seconds(5));

        $this->assertSame([false], $results);
    }

    /**
     * Run the callback, collect discarded futures, and return the messages the event loop reported as unhandled.
     *
     * @return list<string>
     */
    protected function captureLoopErrors(callable $callback): array
    {
        $errors = [];
        $previous = EventLoop::getErrorHandler();

        EventLoop::setErrorHandler(function (\Throwable $e) use (&$errors) {
            $errors[] = $e->getMessage();
        });

        try {
            $callback();

            delay(0.01);
            gc_collect_cycles();
            delay(0.01);
        } finally {
            EventLoop::setErrorHandler($previous);
        }

        return $errors;
    }
}
