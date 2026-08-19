<?php

namespace Catualus\QuickNav\Filament\Server\Toolbar;

use App\Enums\SubuserPermission;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use Catualus\QuickNav\Services\QuickNavResolver;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;

class QuickNavToolbar
{
    /**
     * Rendered into the file manager's own toolbar via a Filament table render hook.
     *
     * ListFiles defines table() itself rather than using CanModifyTable, so its
     * toolbar actions cannot be extended through Pelican's traits - the render hook
     * is the only way into that row.
     */
    public static function render(): View|string
    {
        $server = Filament::getTenant();

        if (!$server instanceof Server || !user()?->can(SubuserPermission::FileRead, $server)) {
            return '';
        }

        $shortcuts = app(QuickNavResolver::class)->shortcuts($server);
        $historyLimit = max(0, (int) config('quick-nav.history_limit', 6));

        // With no preset shortcuts, pins and history are still worth the button - they
        // work on any server. With those switched off too there is genuinely nothing to
        // offer, and the button hides rather than opening an empty menu.
        if ($shortcuts === [] && $historyLimit === 0) {
            return '';
        }

        return view('quick-nav::toolbar', [
            'shortcuts' => array_map(fn (array $shortcut): array => [
                'label' => $shortcut['label'],
                'icon' => $shortcut['icon'] ?? 'tabler-folder',
                'path' => trim($shortcut['path'], '/'),
                'url' => ListFiles::getUrl(['path' => trim($shortcut['path'], '/')]),
            ], $shortcuts),

            // The file list's path is a route segment rather than a query parameter,
            // so building a URL for an arbitrary folder means appending to this.
            'base' => rtrim(ListFiles::getUrl(), '/'),

            // Pins and history are per server and live in the browser - see the view.
            'storageKey' => 'quick-nav:' . $server->uuid,

            'historyLimit' => $historyLimit,
        ]);
    }
}
