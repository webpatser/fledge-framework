<?php

namespace Illuminate\Foundation\Console\Bench\Scenarios;

use Illuminate\Foundation\Console\Bench\Scenario;

class PolyfillsScenario implements Scenario
{
    private array $haystack;

    public function name(): string
    {
        return 'polyfills';
    }

    public function label(): string
    {
        return 'Native PHP 8.5 array_all/array_any vs handwritten foreach';
    }

    public function preflight(): ?string
    {
        if (! function_exists('array_all') || ! function_exists('array_any')) {
            return 'array_all() / array_any() are missing. Fledge requires PHP 8.5+ where these are native; your PHP build does not appear to expose them.';
        }

        return null;
    }

    public function setup(): void
    {
        $this->haystack = range(1, 100);
    }

    public function variants(): array
    {
        $haystack = $this->haystack;
        $needle = fn ($v) => $v === 99;
        $allTruthy = fn ($v) => $v > 0;

        return [
            'native_array_any' => function () use ($haystack, $needle) {
                array_any($haystack, $needle);
            },
            'foreach_array_any' => function () use ($haystack, $needle) {
                foreach ($haystack as $k => $v) {
                    if ($needle($v, $k)) {
                        return;
                    }
                }
            },
            'native_array_all' => function () use ($haystack, $allTruthy) {
                array_all($haystack, $allTruthy);
            },
            'foreach_array_all' => function () use ($haystack, $allTruthy) {
                foreach ($haystack as $k => $v) {
                    if (! $allTruthy($v, $k)) {
                        return;
                    }
                }
            },
        ];
    }

    public function teardown(): void
    {
        //
    }
}
