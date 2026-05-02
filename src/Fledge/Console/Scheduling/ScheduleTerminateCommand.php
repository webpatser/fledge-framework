<?php

namespace Fledge\Console\Scheduling;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\InteractsWithTime;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'schedule:terminate')]
class ScheduleTerminateCommand extends Command
{
    use InteractsWithTime;

    protected $name = 'schedule:terminate';

    protected $description = 'Terminate the schedule worker daemon after the current iteration';

    public function __construct(protected Cache $cache)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $this->cache->forever('illuminate:schedule:work:terminate', $this->currentTime());

        $this->components->info('Broadcasting schedule worker termination signal.');
    }
}
