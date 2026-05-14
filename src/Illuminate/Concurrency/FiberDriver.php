<?php

namespace Illuminate\Concurrency;

use Carbon\CarbonInterval;
use Closure;
use Fledge\Async\TimeoutCancellation;
use Illuminate\Contracts\Concurrency\Driver;
use Illuminate\Support\Arr;
use Illuminate\Support\Defer\DeferredCallback;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;
use function Illuminate\Support\defer;

class FiberDriver implements Driver
{
    /**
     * Run the given tasks concurrently and return an array containing the results.
     */
    public function run(Closure|array $tasks, CarbonInterval|int|null $timeout = null): array
    {
        $tasks = Arr::wrap($tasks);

        $futures = [];

        foreach ($tasks as $key => $task) {
            $futures[$key] = async($task);
        }

        $cancellation = match (true) {
            $timeout instanceof CarbonInterval => new TimeoutCancellation($timeout->totalSeconds),
            is_int($timeout) => new TimeoutCancellation($timeout),
            default => null,
        };

        return await($futures, $cancellation);
    }

    /**
     * Start the given tasks in the background after the current task has finished.
     */
    public function defer(Closure|array $tasks): DeferredCallback
    {
        return defer(fn () => $this->run($tasks));
    }
}
