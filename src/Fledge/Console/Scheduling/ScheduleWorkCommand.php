<?php

namespace Fledge\Console\Scheduling;

use Illuminate\Console\Scheduling\ScheduleWorkCommand as BaseScheduleWorkCommand;
use Illuminate\Contracts\Cache\Repository as Cache;

class ScheduleWorkCommand extends BaseScheduleWorkCommand
{
    /**
     * The value of the terminate cache key when the worker started.
     */
    protected mixed $lastTerminate = null;

    public function __construct(protected Cache $cache)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * The base command builds the schedule:run command line, traps SIGINT,
     * SIGTERM and SIGQUIT, and drains in-flight runs before it returns.
     */
    public function handle(): int
    {
        $status = parent::handle();

        $this->components->info('Schedule worker terminated.');

        return $status;
    }

    /**
     * Run the schedule worker loop until it is signalled to stop.
     *
     * @param  string  $command
     * @return int
     */
    protected function work($command)
    {
        $this->lastTerminate = $this->cache->get('illuminate:schedule:work:terminate');

        return parent::work($command);
    }

    /**
     * Sleep for a short period, then treat a schedule:terminate request like a stop signal.
     *
     * @return void
     */
    protected function sleep()
    {
        parent::sleep();

        if (! $this->shouldQuit &&
            $this->cache->get('illuminate:schedule:work:terminate') != $this->lastTerminate) {
            $this->shouldQuit = true;
        }
    }
}
