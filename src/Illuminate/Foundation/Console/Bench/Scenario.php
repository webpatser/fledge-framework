<?php

namespace Illuminate\Foundation\Console\Bench;

interface Scenario
{
    /**
     * Stable scenario identifier (`uri`, `polyfills`, `redis`).
     */
    public function name(): string;

    /**
     * Human-readable label used in output.
     */
    public function label(): string;

    /**
     * Indicates whether the scenario can run in the current environment.
     *
     * Returns null when ready, or a string explaining what is missing
     * (with install/uninstall instructions for the user).
     */
    public function preflight(): ?string;

    /**
     * One-time setup before the timing loop (open connections, prepare data).
     */
    public function setup(): void;

    /**
     * Variants to time, keyed by variant name (e.g. 'native', 'league').
     *
     * @return array<string, callable>
     */
    public function variants(): array;

    /**
     * One-time teardown after the timing loop.
     */
    public function teardown(): void;
}
