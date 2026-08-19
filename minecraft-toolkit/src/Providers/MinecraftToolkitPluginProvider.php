<?php

namespace Catualus\MinecraftToolkit\Providers;

use Catualus\MinecraftToolkit\Services\CrashReportService;
use Catualus\MinecraftToolkit\Services\ModService;
use Catualus\MinecraftToolkit\Services\PlayerListService;
use Catualus\MinecraftToolkit\Services\PropertiesService;
use Catualus\MinecraftToolkit\Support\Minecraft;
use Illuminate\Support\ServiceProvider;

class MinecraftToolkitPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/minecraft-toolkit.php', 'minecraft-toolkit');

        // Scoped so a page render probes the daemon once, however many components ask.
        $this->app->scoped(Minecraft::class);
        $this->app->scoped(ModService::class);
        $this->app->scoped(PlayerListService::class);
        $this->app->scoped(PropertiesService::class);
        $this->app->scoped(CrashReportService::class);
    }
}
