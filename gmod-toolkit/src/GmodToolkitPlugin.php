<?php

namespace Catualus\GmodToolkit;

use Filament\Contracts\Plugin;
use Filament\Panel;

class GmodToolkitPlugin implements Plugin
{
    public function getId(): string
    {
        return 'gmod-toolkit';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(
            plugin_path($this->getId(), "src/Filament/$id/Pages"),
            "Catualus\\GmodToolkit\\Filament\\$id\\Pages",
        );
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
