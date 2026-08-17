<?php

namespace Catualus\MinecraftToolkit\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use Catualus\MinecraftToolkit\Support\Minecraft;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * Shared gate: Minecraft servers only, and only for users who can read files.
 */
abstract class MinecraftPage extends Page
{
    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server
            && user()?->can(SubuserPermission::FileRead, $server)
            && app(Minecraft::class)->detect($server);
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Minecraft';
    }

    protected function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    protected function canWriteFiles(): bool
    {
        return (bool) user()?->can(SubuserPermission::FileUpdate, $this->server());
    }

    protected function denied(): void
    {
        \Filament\Notifications\Notification::make()
            ->title('You do not have permission to modify this server\'s files.')
            ->danger()
            ->send();
    }
}
