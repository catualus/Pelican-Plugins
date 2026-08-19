<?php

namespace Catualus\GmodToolkit\Providers;

use Catualus\GmodToolkit\Services\AddonCatalogue;
use Catualus\GmodToolkit\Services\AddonInstaller;
use Catualus\GmodToolkit\Services\AddonService;
use Catualus\GmodToolkit\Services\DiskUsageService;
use Catualus\GmodToolkit\Services\LuaErrorService;
use Catualus\GmodToolkit\Services\WorkshopService;
use Catualus\GmodToolkit\Support\GarrysMod;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class GmodToolkitPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/gmod-toolkit.php', 'gmod-toolkit');

        // Scoped so a page render probes the daemon and parses each error log once,
        // however many components ask for the result.
        $this->app->scoped(GarrysMod::class);
        $this->app->scoped(LuaErrorService::class);
        $this->app->scoped(AddonService::class);
        $this->app->scoped(WorkshopService::class);
        $this->app->scoped(DiskUsageService::class);
        $this->app->scoped(AddonCatalogue::class);
        $this->app->scoped(AddonInstaller::class);
    }

    public function boot(): void
    {
        // Pelican registers resources/views under the plugin id, so this maps
        // <x-gmod-toolkit::stat /> onto resources/views/components/stat.blade.php.
        Blade::anonymousComponentNamespace('gmod-toolkit::components', 'gmod-toolkit');
    }
}
