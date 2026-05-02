<?php

namespace Illuminate\Foundation\Console\Bench;

final class Runner
{
    /**
     * Time a callable and return latency statistics in nanoseconds.
     *
     * @return array{
     *     iterations:int, min_ns:int, p50_ns:int, p95_ns:int, p99_ns:int,
     *     max_ns:int, mean_ns:float, total_ns:int, ops_sec:float
     * }
     */
    public function measure(callable $task, int $iterations, int $warmup): array
    {
        if ($iterations < 1) {
            throw new \InvalidArgumentException('Iterations must be >= 1.');
        }

        for ($i = 0; $i < $warmup; $i++) {
            $task();
        }

        $samples = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $task();
            $samples[] = hrtime(true) - $start;
        }

        sort($samples);
        $total = array_sum($samples);

        return [
            'iterations' => $iterations,
            'min_ns'  => $samples[0],
            'p50_ns'  => $samples[(int) floor($iterations * 0.50)],
            'p95_ns'  => $samples[(int) floor($iterations * 0.95)],
            'p99_ns'  => $samples[(int) floor($iterations * 0.99)],
            'max_ns'  => $samples[$iterations - 1],
            'mean_ns' => $total / $iterations,
            'total_ns' => $total,
            'ops_sec' => $iterations / ($total / 1e9),
        ];
    }
}
