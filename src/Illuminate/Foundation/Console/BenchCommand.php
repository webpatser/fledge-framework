<?php

namespace Illuminate\Foundation\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Foundation\Console\Bench\Runner;
use Illuminate\Foundation\Console\Bench\Scenario;
use Illuminate\Foundation\Console\Bench\Scenarios\PolyfillsScenario;
use Illuminate\Foundation\Console\Bench\Scenarios\RedisScenario;
use Illuminate\Foundation\Console\Bench\Scenarios\UriScenario;
use League\Uri\Uri as LeagueUri;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'fledge:bench')]
class BenchCommand extends Command
{
    protected $signature = 'fledge:bench
        {--scenario=all : uri, polyfills, redis, or all}
        {--iterations=10000 : number of timed iterations per variant}
        {--warmup=1000 : warmup iterations before timing}
        {--format=table : table or json}';

    protected $description = 'Run reproducible Fledge micro-benchmarks (URI, polyfills, Redis cache).';

    public function handle(CacheFactory $cacheFactory): int
    {
        $iterations = (int) $this->option('iterations');
        $warmup = max(0, (int) $this->option('warmup'));
        $format = $this->option('format');
        $scenarioOption = $this->option('scenario');

        if ($iterations < 1) {
            $this->components->error('Iterations must be >= 1.');

            return self::FAILURE;
        }

        if (! in_array($format, ['table', 'json'], true)) {
            $this->components->error('Format must be "table" or "json".');

            return self::FAILURE;
        }

        $scenarios = $this->resolveScenarios($scenarioOption, $cacheFactory);

        if ($scenarios === null) {
            return self::FAILURE;
        }

        $runner = new Runner;
        $report = [];

        foreach ($scenarios as $scenario) {
            $skip = $scenario->preflight();
            if ($skip !== null) {
                $this->renderSkipped($scenario, $skip, $format);
                $report[$scenario->name()] = ['skipped' => true, 'reason' => $skip];

                continue;
            }

            $scenario->setup();

            try {
                $variantResults = [];
                foreach ($scenario->variants() as $variantName => $task) {
                    $variantResults[$variantName] = $runner->measure($task, $iterations, $warmup);
                }
            } finally {
                $scenario->teardown();
            }

            $report[$scenario->name()] = [
                'label' => $scenario->label(),
                'iterations' => $iterations,
                'warmup' => $warmup,
                'variants' => $variantResults,
            ];

            if ($format === 'table') {
                $this->renderTable($scenario, $variantResults);
            }
        }

        $this->renderHints($scenarios);

        if ($format === 'json') {
            $this->line(json_encode([
                'fledge_bench_version' => 1,
                'php_version' => PHP_VERSION,
                'scenarios' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, Scenario>|null
     */
    protected function resolveScenarios(string $option, CacheFactory $cacheFactory): ?array
    {
        $available = [
            'uri' => fn () => new UriScenario,
            'polyfills' => fn () => new PolyfillsScenario,
            'redis' => fn () => new RedisScenario($cacheFactory),
        ];

        if ($option === 'all') {
            return array_map(fn ($factory) => $factory(), array_values($available));
        }

        if (! isset($available[$option])) {
            $this->components->error(sprintf(
                'Unknown scenario "%s". Available: %s, all.',
                $option,
                implode(', ', array_keys($available)),
            ));

            return null;
        }

        return [$available[$option]()];
    }

    /**
     * @param  array<string, array<string, int|float>>  $variants
     */
    protected function renderTable(Scenario $scenario, array $variants): void
    {
        $this->newLine();
        $this->components->info($scenario->label());

        $rows = [];
        foreach ($variants as $name => $stats) {
            $rows[] = [
                $name,
                $this->formatNs($stats['p50_ns']),
                $this->formatNs($stats['p95_ns']),
                $this->formatNs($stats['p99_ns']),
                $this->formatNs((int) $stats['mean_ns']),
                number_format($stats['ops_sec'], 0),
            ];
        }

        $this->table(['variant', 'p50', 'p95', 'p99', 'mean', 'ops/sec'], $rows);

        if (count($variants) === 2) {
            $names = array_keys($variants);
            $a = $variants[$names[0]]['mean_ns'];
            $b = $variants[$names[1]]['mean_ns'];
            if ($a > 0 && $b > 0) {
                $faster = $a < $b ? $names[0] : $names[1];
                $slower = $a < $b ? $names[1] : $names[0];
                $ratio = $a < $b ? $b / $a : $a / $b;
                $this->line(sprintf('  %s is %.1fx faster than %s on mean latency.', $faster, $ratio, $slower));
            }
        }
    }

    protected function renderSkipped(Scenario $scenario, string $reason, string $format): void
    {
        if ($format === 'json') {
            return;
        }

        $this->newLine();
        $this->components->warn(sprintf('Skipped %s: %s', $scenario->name(), $scenario->label()));
        foreach (explode("\n", $reason) as $line) {
            $this->line('  '.$line);
        }
    }

    /**
     * @param  array<int, Scenario>  $scenarios
     */
    protected function renderHints(array $scenarios): void
    {
        foreach ($scenarios as $scenario) {
            if ($scenario instanceof UriScenario && ! class_exists(LeagueUri::class)) {
                $this->newLine();
                $this->components->info('To compare PHP 8.5 native URI against league/uri:');
                foreach (explode("\n", UriScenario::compareInstallHint()) as $line) {
                    $this->line('  '.$line);
                }
            }
        }
    }

    protected function formatNs(int|float $ns): string
    {
        if ($ns < 1_000) {
            return sprintf('%d ns', $ns);
        }

        if ($ns < 1_000_000) {
            return sprintf('%.2f µs', $ns / 1_000);
        }

        return sprintf('%.2f ms', $ns / 1_000_000);
    }
}
