<?php

namespace Catualus\AppToolkit\Providers;

use Catualus\AppToolkit\Services\DependencyService;
use Catualus\AppToolkit\Services\DeployService;
use Catualus\AppToolkit\Services\EnvService;
use Catualus\AppToolkit\Support\AppServer;
use Illuminate\Support\ServiceProvider;

class AppToolkitPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/app-toolkit.php', 'app-toolkit');

        // Scoped so a page render probes the daemon once, however many sections ask.
        $this->app->scoped(AppServer::class);
        $this->app->scoped(DeployService::class);
        $this->app->scoped(EnvService::class);
        $this->app->scoped(DependencyService::class);
    }
}
