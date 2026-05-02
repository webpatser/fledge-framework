<?php

namespace Illuminate\Tests\Integration\Console;

use Orchestra\Testbench\TestCase;

class BenchCommandTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        $app['config']->set('cache.default', 'array');
    }

    public function testItRunsPolyfillsScenarioAndExitsZero(): void
    {
        $this->artisan('fledge:bench', [
            '--scenario' => 'polyfills',
            '--iterations' => 50,
            '--warmup' => 5,
        ])->assertExitCode(0);
    }

    public function testItProducesValidJsonWithExpectedKeys(): void
    {
        $exitCode = $this->withoutMockingConsoleOutput()
            ->artisan('fledge:bench', [
                '--scenario' => 'polyfills',
                '--iterations' => 50,
                '--warmup' => 5,
                '--format' => 'json',
            ]);

        $this->assertSame(0, $exitCode);

        $output = $this->extractJson(\Illuminate\Support\Facades\Artisan::output());

        $this->assertSame(1, $output['fledge_bench_version']);
        $this->assertSame(PHP_VERSION, $output['php_version']);
        $this->assertArrayHasKey('polyfills', $output['scenarios']);

        $scenario = $output['scenarios']['polyfills'];
        $this->assertSame(50, $scenario['iterations']);
        $this->assertSame(5, $scenario['warmup']);
        $this->assertNotEmpty($scenario['variants']);

        foreach ($scenario['variants'] as $stats) {
            foreach (['iterations', 'min_ns', 'p50_ns', 'p95_ns', 'p99_ns', 'max_ns', 'mean_ns', 'total_ns', 'ops_sec'] as $key) {
                $this->assertArrayHasKey($key, $stats);
            }
            $this->assertSame(50, $stats['iterations']);
            $this->assertGreaterThan(0, $stats['ops_sec']);
            $this->assertGreaterThanOrEqual($stats['p50_ns'], $stats['p95_ns']);
            $this->assertGreaterThanOrEqual($stats['p95_ns'], $stats['p99_ns']);
        }
    }

    public function testItRunsAllScenariosWithoutFailing(): void
    {
        $exitCode = $this->withoutMockingConsoleOutput()
            ->artisan('fledge:bench', [
                '--scenario' => 'all',
                '--iterations' => 25,
                '--warmup' => 2,
                '--format' => 'json',
            ]);

        $this->assertSame(0, $exitCode);

        $output = $this->extractJson(\Illuminate\Support\Facades\Artisan::output());
        $this->assertArrayHasKey('uri', $output['scenarios']);
        $this->assertArrayHasKey('polyfills', $output['scenarios']);
        $this->assertArrayHasKey('redis', $output['scenarios']);
    }

    public function testRedisScenarioSkipsCleanlyWhenRedisIsUnreachable(): void
    {
        $this->app['config']->set('database.redis.default', [
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 0,
            'timeout' => 0.05,
        ]);
        $this->app['config']->set('cache.stores.redis', [
            'driver' => 'redis',
            'connection' => 'default',
        ]);

        $exitCode = $this->withoutMockingConsoleOutput()
            ->artisan('fledge:bench', [
                '--scenario' => 'redis',
                '--iterations' => 10,
                '--warmup' => 1,
                '--format' => 'json',
            ]);

        $this->assertSame(0, $exitCode);
        $output = $this->extractJson(\Illuminate\Support\Facades\Artisan::output());
        $this->assertTrue($output['scenarios']['redis']['skipped']);
        $this->assertStringContainsString('docker run', $output['scenarios']['redis']['reason']);
    }

    public function testItRejectsUnknownScenario(): void
    {
        $this->artisan('fledge:bench', ['--scenario' => 'doesnotexist'])
            ->expectsOutputToContain('Unknown scenario')
            ->assertExitCode(1);
    }

    public function testItRejectsInvalidIterations(): void
    {
        $this->artisan('fledge:bench', ['--iterations' => 0])
            ->expectsOutputToContain('Iterations must be >= 1')
            ->assertExitCode(1);
    }

    public function testItRejectsInvalidFormat(): void
    {
        $this->artisan('fledge:bench', ['--format' => 'xml'])
            ->expectsOutputToContain('Format must be')
            ->assertExitCode(1);
    }

    private function extractJson(string $output): array
    {
        $start = strpos($output, '{');
        $this->assertNotFalse($start, 'No JSON found in output: '.$output);

        $json = substr($output, $start);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded, 'Failed to decode JSON: '.json_last_error_msg()."\n".$json);

        return $decoded;
    }
}
