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

        if ($shortcuts === []) {
            return '';
        }

        return view('quick-nav::toolbar', [
            'shortcuts' => array_map(fn (array $shortcut): array => [
                'label' => $shortcut['label'],
                'icon' => $shortcut['icon'] ?? 'tabler-folder',
                'url' => ListFiles::getUrl(['path' => trim($shortcut['path'], '/')]),
            ], $shortcuts),
        ]);
    }
}
