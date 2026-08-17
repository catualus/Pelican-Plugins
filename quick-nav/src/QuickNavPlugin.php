<?php

namespace Catualus\QuickNav;

use Filament\Contracts\Plugin;
use Filament\Panel;

class QuickNavPlugin implements Plugin
{
    public function getId(): string
    {
        return 'quick-nav';
    }

    public function register(Panel $panel): void
    {
        // The toolbar render hook is registered in QuickNavPluginProvider::boot(),
        // where views are already available.
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
