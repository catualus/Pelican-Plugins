<?php

namespace Catualus\QuickNav\Providers;

use App\Filament\Server\Resources\Files\Pages\ListFiles;
use Catualus\QuickNav\Filament\Server\Toolbar\QuickNavToolbar;
use Catualus\QuickNav\Services\QuickNavResolver;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\View\TablesRenderHook;
use Illuminate\Support\ServiceProvider;

class QuickNavPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/quick-nav.php', 'quick-nav');

        // Scoped so the directory probes behind the shortcut list happen once per
        // request rather than once per shortcut.
        $this->app->scoped(QuickNavResolver::class);
    }

    public function boot(): void
    {
        // Anchored inside the toolbar's icon cluster rather than at toolbar.start,
        // which would make the button a separate flex child and split the row.
        // Scoped to ListFiles so it only renders in the file manager, never in any
        // other table in the panel.
        FilamentView::registerRenderHook(
            (string) config('quick-nav.toolbar_hook', TablesRenderHook::TOOLBAR_GROUPING_SELECTOR_BEFORE),
            fn () => QuickNavToolbar::render(),
            scopes: ListFiles::class,
        );
    }
}
