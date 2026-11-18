<?php

namespace Illuminate\Concurrency;

use Carbon\CarbonInterval;
use Closure;
use Fledge\Async\CancelledException;
use Fledge\Async\DeferredCancellation;
use Fledge\Concurrency\RunTimeout;
use Fledge\Concurrency\TaskCancellation;
use Fledge\Concurrency\TaskConnectionScope;
use Illuminate\Contracts\Concurrency\Driver;
use Illuminate\Support\Arr;
use Illuminate\Support\Defer\DeferredCallback;

use function Fledge\Async\async;
use function Fledge\Async\Future\awaitAll;
use function Illuminate\Support\defer;

class FiberDriver implements Driver
{
    /**
     * Run the given tasks concurrently and return an array containing the results.
     *
     * @param  (\Closure(): mixed)|array<array-key, \Closure(): mixed>  $tasks
     * @return array<array-key, mixed>
     */
    public function run(Closure|array $tasks, CarbonInterval|int|null $timeout = null): array
    {
        return $this->runTasks($tasks, $timeout, true);
    }

    /**
     * Run the given tasks concurrently on the caller's database connections.
     *
     * For ConcurrentMiddlewareGroup: its members are part of one request, so they keep using
     * the request's connections (and see its open transaction) instead of opening their own.
     *
     * @internal
     *
     * @param  (\Closure(): mixed)|array<array-key, \Closure(): mixed>  $tasks
     * @return array<array-key, mixed>
     */
    public function runWithoutConnectionScope(Closure|array $tasks, CarbonInterval|int|null $timeout = null): array
    {
        return $this->runTasks($tasks, $timeout, false);
    }

    /**
     * @param  (\Closure(): mixed)|array<array-key, \Closure(): mixed>  $tasks
     * @return array<array-key, mixed>
     */
    protected function runTasks(Closure|array $tasks, CarbonInterval|int|null $timeout, bool $scoped): array
    {
        $tasks = Arr::wrap($tasks);

        // Every task's ambient cancellation (see TaskCancellation): requested on the timeout, and
        // in a nested run also when the enclosing task's own cancellation is requested.
        $source = new DeferredCancellation;
        $taskCancellation = $source->getCancellation();
        $outer = TaskCancellation::current();
        $outerId = $outer?->subscribe(static fn (CancelledException $e) => $source->cancel($e->getPrevious() ?? $e));

        $futures = [];

        // Each task gets database connections of its own for its whole life (see TaskConnectionScope).
        foreach ($tasks as $key => $task) {
            $futures[$key] = async(fn () => TaskCancellation::run(
                $taskCancellation, $scoped ? fn () => TaskConnectionScope::run($task) : $task
            ));
        }

        // A referenced timer (unlike TimeoutCancellation's) holds the event loop open, so the call
        // still ends in CancelledException when every task waits on something that never completes.
        [$cancellation, $timer] = RunTimeout::start($timeout);

        // Let every task settle, like the stock ProcessDriver waits for the whole pool. The timeout
        // still bounds the whole call: awaitAll() throws CancelledException once it fires. Tasks
        // that pass their cancellation to an await then unwind through their own finally blocks;
        // the others keep running and their results are discarded.
        try {
            [$errors, $values] = awaitAll($futures, $cancellation);
        } catch (CancelledException $e) {
            $source->cancel($e->getPrevious() ?? $e);

            throw $e;
        } finally {
            RunTimeout::stop($timer);

            if ($outerId !== null) {
                $outer->unsubscribe($outerId);
            }
        }

        $results = [];

        // awaitAll() fills both arrays in completion order; rethrow and return in task order instead.
        foreach (array_keys($futures) as $key) {
            if (array_key_exists($key, $errors)) {
                throw $errors[$key];
            }

            $results[$key] = $values[$key];
        }

        return $results;
    }

    /**
     * Start the given tasks in the background after the current task has finished.
     *
     * @param  (\Closure(): mixed)|array<array-key, \Closure(): mixed>  $tasks
     */
    public function defer(Closure|array $tasks): DeferredCallback
    {
        return defer(fn () => $this->run($tasks));
    }
}
