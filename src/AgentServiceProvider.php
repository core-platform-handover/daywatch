<?php

namespace Oritech\Daywatch;

use Oritech\Daywatch\Console\RunAgentCommand;
use Illuminate\Support\ServiceProvider;

class AgentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RunAgentCommand::class]);
        }
    }
}
