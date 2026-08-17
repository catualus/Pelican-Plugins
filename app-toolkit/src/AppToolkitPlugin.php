<?php

namespace Catualus\AppToolkit;

use Filament\Contracts\Plugin;
use Filament\Panel;

class AppToolkitPlugin implements Plugin
{
    public function getId(): string
    {
        return 'app-toolkit';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(
            plugin_path($this->getId(), "src/Filament/$id/Pages"),
            "Catualus\\AppToolkit\\Filament\\$id\\Pages",
        );
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
