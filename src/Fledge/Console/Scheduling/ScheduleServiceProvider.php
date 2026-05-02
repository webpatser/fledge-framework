<?php

namespace Fledge\Console\Scheduling;

use Illuminate\Console\Scheduling\ScheduleWorkCommand as IlluminateScheduleWorkCommand;
use Illuminate\Support\ServiceProvider;

class ScheduleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IlluminateScheduleWorkCommand::class, ScheduleWorkCommand::class);

        $this->app->singleton(ScheduleTerminateCommand::class);
    }

    public function boot(): void
    {
        $this->commands([ScheduleTerminateCommand::class]);

        ServiceProvider::$reloadCommands['schedule worker'] = 'schedule:terminate';
    }
}
