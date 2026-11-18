<?php

namespace Illuminate\Tests\Console\Scheduling;

use Fledge\Console\Scheduling\ScheduleWorkCommand;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Console\Signals;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Tests\Console\Fixtures\FakeSignalsRegistry;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class FledgeScheduleWorkCommandTest extends TestCase
{
    /**
     * The signal availability resolver in place before the test ran.
     *
     * @var (callable(): bool)|null
     */
    protected $originalAvailabilityResolver;

    protected function setUp(): void
    {
        $this->originalAvailabilityResolver = (new ReflectionProperty(Signals::class, 'availabilityResolver'))
            ->getValue();

        // Off the zero second, so the worker never spawns a real schedule:run.
        Carbon::setTestNow(Carbon::create(2026, 6, 27, 12, 0, 30));
    }

    protected function tearDown(): void
    {
        Signals::resolveAvailabilityUsing($this->originalAvailabilityResolver);

        Carbon::setTestNow();
    }

    #[RequiresPhpExtension('pcntl')]
    public function test_sigterm_stops_the_worker_through_the_base_trap(): void
    {
        $previousHandlers = [];

        foreach ([SIGINT, SIGTERM, SIGQUIT] as $signal) {
            $previousHandlers[$signal] = pcntl_signal_get_handler($signal);

            pcntl_signal($signal, SIG_DFL);
        }

        try {
            Signals::resolveAvailabilityUsing(fn () => true);

            $registry = new FakeSignalsRegistry;

            $command = $this->makeCommand($cache = $this->makeCache());

            (fn () => $this->signals = new Signals($registry))->call($command);

            $command->onTick = function (FledgeScheduleWorkCommandTestStub $command) use ($registry) {
                if ($command->ticks === 1) {
                    $registry->handle(SIGTERM);
                }
            };

            [$status, $output] = $this->runCommand($command);

            $this->assertSame(ScheduleWorkCommand::SUCCESS, $status);
            $this->assertSame(1, $command->ticks, 'The worker should stop on the tick that received SIGTERM.');
            $this->assertStringContainsString('Schedule worker terminated.', $output);
        } finally {
            foreach ($previousHandlers as $signal => $handler) {
                pcntl_signal($signal, $handler ?: SIG_DFL);
            }
        }
    }

    public function test_schedule_terminate_cache_key_stops_the_worker(): void
    {
        Signals::resolveAvailabilityUsing(fn () => false);

        $command = $this->makeCommand($cache = $this->makeCache());

        $command->onTick = function (FledgeScheduleWorkCommandTestStub $command) use ($cache) {
            if ($command->ticks === 1) {
                $cache->forever('illuminate:schedule:work:terminate', 1782561630);
            }
        };

        [$status, $output] = $this->runCommand($command);

        $this->assertSame(ScheduleWorkCommand::SUCCESS, $status);
        $this->assertSame(1, $command->ticks, 'The worker should stop on the tick after schedule:terminate.');
        $this->assertStringContainsString('Schedule worker terminated.', $output);
    }

    public function test_terminate_value_present_at_boot_does_not_stop_the_worker(): void
    {
        Signals::resolveAvailabilityUsing(fn () => false);

        $cache = $this->makeCache();
        $cache->forever('illuminate:schedule:work:terminate', 1782561000);

        $command = $this->makeCommand($cache);

        $command->onTick = function (FledgeScheduleWorkCommandTestStub $command) use ($cache) {
            if ($command->ticks === 3) {
                $cache->forever('illuminate:schedule:work:terminate', 1782561630);
            }
        };

        [$status] = $this->runCommand($command);

        $this->assertSame(ScheduleWorkCommand::SUCCESS, $status);
        $this->assertSame(3, $command->ticks, 'Only a value written after boot should stop the worker.');
    }

    protected function makeCache(): Repository
    {
        return new Repository(new FledgeScheduleWorkSafetyStore);
    }

    protected function makeCommand(Repository $cache): FledgeScheduleWorkCommandTestStub
    {
        $app = new Application(__DIR__);
        $app['env'] = 'testing';

        $command = new FledgeScheduleWorkCommandTestStub($cache);
        $command->setLaravel($app);

        return $command;
    }

    /**
     * @return array{0: int, 1: string}
     */
    protected function runCommand(FledgeScheduleWorkCommandTestStub $command): array
    {
        $status = $command->run(new ArrayInput([]), $buffer = new BufferedOutput);

        return [$status, $buffer->fetch()];
    }
}

class FledgeScheduleWorkCommandTestStub extends ScheduleWorkCommand
{
    /**
     * The number of times the worker has ticked (slept).
     *
     * @var int
     */
    public $ticks = 0;

    /**
     * A callback invoked on every worker tick, used to drive the loop in tests.
     *
     * @var (callable(self): void)|null
     */
    public $onTick;

    protected function sleep()
    {
        $this->ticks++;

        if ($this->onTick) {
            ($this->onTick)($this);
        }

        parent::sleep();
    }
}

/**
 * Array store that changes the terminate key after many reads, so a worker
 * loop that never ticks cannot hang the test run.
 */
class FledgeScheduleWorkSafetyStore extends ArrayStore
{
    protected int $terminateReads = 0;

    public function get($key)
    {
        if ($key === 'illuminate:schedule:work:terminate' && ++$this->terminateReads > 50) {
            return 'safety-valve';
        }

        return parent::get($key);
    }
}
