<?php

namespace Fledge\Console\Scheduling;

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\ScheduleWorkCommand as BaseScheduleWorkCommand;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\ProcessUtils;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class ScheduleWorkCommand extends BaseScheduleWorkCommand
{
    public function __construct(protected Cache $cache)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->components->info(
            'Running scheduled tasks.',
            $this->getLaravel()->environment('local') ? OutputInterface::VERBOSITY_NORMAL : OutputInterface::VERBOSITY_VERBOSE
        );

        [$lastExecutionStartedAt, $executions] = [Carbon::now()->subMinutes(10), []];

        $command = Application::formatCommandString('schedule:run');

        if ($this->option('whisper')) {
            $command .= ' --whisper';
        }

        if ($this->option('run-output-file')) {
            $command .= ' >> '.ProcessUtils::escapeArgument($this->option('run-output-file')).' 2>&1';
        }

        $lastTerminate = $this->cache->get('illuminate:schedule:work:terminate');

        while ($this->cache->get('illuminate:schedule:work:terminate') == $lastTerminate) {
            usleep(100 * 1000);

            if (Carbon::now()->second === 0 &&
                ! Carbon::now()->startOfMinute()->equalTo($lastExecutionStartedAt)) {
                $executions[] = $execution = Process::fromShellCommandline($command, base_path());

                $execution->start();

                $lastExecutionStartedAt = Carbon::now()->startOfMinute();
            }

            foreach ($executions as $key => $execution) {
                $output = $execution->getIncrementalOutput().
                    $execution->getIncrementalErrorOutput();

                $this->output->write(ltrim($output, "\n"));

                if (! $execution->isRunning()) {
                    unset($executions[$key]);
                }
            }
        }

        foreach ($executions as $execution) {
            $execution->wait();

            $output = $execution->getIncrementalOutput().
                $execution->getIncrementalErrorOutput();

            $this->output->write(ltrim($output, "\n"));
        }

        $this->components->info('Schedule worker terminated.');

        return self::SUCCESS;
    }
}
