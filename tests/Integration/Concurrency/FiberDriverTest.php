<?php

namespace Illuminate\Tests\Integration\Concurrency;

use Carbon\CarbonInterval;
use Fledge\Async\CancelledException;
use Fledge\Loop;
use Illuminate\Concurrency\FiberDriver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Fledge\Async\delay;

class FiberDriverTest extends TestCase
{
    public function test_results_follow_task_order_not_completion_order_for_string_keys()
    {
        $results = (new FiberDriver)->run([
            'slow' => function () {
                delay(0.05);

                return 'slow';
            },
            'medium' => function () {
                delay(0.02);

                return 'medium';
            },
            'fast' => fn () => 'fast',
        ]);

        $this->assertSame(['slow' => 'slow', 'medium' => 'medium', 'fast' => 'fast'], $results);
    }

    public function test_results_follow_task_order_not_completion_order_for_list_keys()
    {
        $results = (new FiberDriver)->run([
            function () {
                delay(0.05);

                return 'first';
            },
            fn () => 'second',
        ]);

        $this->assertSame([0 => 'first', 1 => 'second'], $results);
        $this->assertTrue(array_is_list($results));
    }

    public function test_task_exception_is_rethrown()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('task failed');

        (new FiberDriver)->run([
            fn () => 'ok',
            fn () => throw new RuntimeException('task failed'),
        ]);
    }

    public function test_first_failure_in_task_order_wins_when_a_later_task_fails_first()
    {
        try {
            (new FiberDriver)->run([
                function () {
                    delay(0.05);

                    throw new RuntimeException('first-in-order');
                },
                fn () => throw new RuntimeException('first-to-fail'),
            ]);
            $this->fail('Expected a task exception.');
        } catch (RuntimeException $e) {
            $this->assertSame('first-in-order', $e->getMessage());
        }
    }

    public function test_all_tasks_have_finished_before_the_rethrow()
    {
        $finished = false;

        try {
            (new FiberDriver)->run([
                fn () => throw new RuntimeException('fails fast'),
                function () use (&$finished) {
                    delay(0.05);

                    $finished = true;
                },
            ]);
            $this->fail('Expected a task exception.');
        } catch (RuntimeException $e) {
            $this->assertSame('fails fast', $e->getMessage());
        }

        $this->assertTrue($finished);
    }

    public function test_a_late_failure_in_another_task_is_not_left_unhandled()
    {
        $unhandled = $this->captureLoopErrors(function () {
            try {
                (new FiberDriver)->run([
                    fn () => throw new RuntimeException('fails fast'),
                    function () {
                        delay(0.05);

                        throw new RuntimeException('fails late');
                    },
                ]);
            } catch (RuntimeException) {
                //
            }
        });

        $this->assertSame([], $unhandled);
    }

    public function test_the_timeout_still_bounds_the_call()
    {
        $this->expectException(CancelledException::class);

        (new FiberDriver)->run([
            fn () => 'done',
            function () {
                delay(0.5);

                return 'too late';
            },
        ], CarbonInterval::milliseconds(50));
    }

    public function test_a_failure_after_the_timeout_is_not_left_unhandled()
    {
        $unhandled = $this->captureLoopErrors(function () {
            try {
                (new FiberDriver)->run([
                    function () {
                        delay(0.1);

                        throw new RuntimeException('fails after timeout');
                    },
                ], CarbonInterval::milliseconds(20));
                $this->fail('Expected the timeout to fire.');
            } catch (CancelledException) {
                //
            }
        });

        $this->assertSame([], $unhandled);
    }

    /**
     * Run the callback, let pending fibers settle, and return the messages the event loop reported as unhandled.
     *
     * @return list<string>
     */
    protected function captureLoopErrors(callable $callback): array
    {
        $errors = [];
        $previous = Loop::getErrorHandler();

        Loop::setErrorHandler(function (\Throwable $e) use (&$errors) {
            $errors[] = $e->getMessage();
        });

        try {
            $callback();

            delay(0.2);
            gc_collect_cycles();
            delay(0.01);
        } finally {
            Loop::setErrorHandler($previous);
        }

        return $errors;
    }
}
