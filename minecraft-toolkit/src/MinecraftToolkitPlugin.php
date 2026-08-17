<?php

namespace Catualus\MinecraftToolkit;

use Filament\Contracts\Plugin;
use Filament\Panel;

class MinecraftToolkitPlugin implements Plugin
{
    public function getId(): string
    {
        return 'minecraft-toolkit';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(
            plugin_path($this->getId(), "src/Filament/$id/Pages"),
            "Catualus\\MinecraftToolkit\\Filament\\$id\\Pages",
        );
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
