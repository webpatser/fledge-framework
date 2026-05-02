<?php

namespace Fledge\Console\Scheduling;

use Illuminate\Support\ServiceProvider;

class ScheduleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ScheduleWorkCommand::class);
        $this->app->singleton(ScheduleTerminateCommand::class);
    }

    public function boot(): void
    {
        $this->commands([
            ScheduleTerminateCommand::class,
            ScheduleWorkCommand::class,
        ]);

        ServiceProvider::$reloadCommands['schedule worker'] = 'schedule:terminate';
    }
}
