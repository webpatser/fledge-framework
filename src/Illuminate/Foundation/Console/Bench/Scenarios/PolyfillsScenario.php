<?php

namespace Illuminate\Foundation\Console\Bench\Scenarios;

use Illuminate\Foundation\Console\Bench\Scenario;

class PolyfillsScenario implements Scenario
{
    /**
     * @var list<int>
     */
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
        $needle = fn (int $v, int $k): bool => $v === 99;
        $allTruthy = fn (int $v, int $k): bool => $v > 0;

        return [
            'native_array_any' => function () use ($haystack, $needle): bool {
                return array_any($haystack, $needle);
            },
            'foreach_array_any' => function () use ($haystack, $needle): bool {
                foreach ($haystack as $k => $v) {
                    if ($needle($v, $k)) {
                        return true;
                    }
                }

                return false;
            },
            'native_array_all' => function () use ($haystack, $allTruthy): bool {
                return array_all($haystack, $allTruthy);
            },
            'foreach_array_all' => function () use ($haystack, $allTruthy): bool {
                foreach ($haystack as $k => $v) {
                    if (! $allTruthy($v, $k)) {
                        return false;
                    }
                }

                return true;
            },
        ];
    }

    public function teardown(): void
    {
        //
    }
}
