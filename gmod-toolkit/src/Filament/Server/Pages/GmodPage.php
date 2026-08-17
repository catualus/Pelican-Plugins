<?php

namespace Catualus\GmodToolkit\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use Catualus\GmodToolkit\Support\GarrysMod;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * Shared gate for every page here: only shown on Garry's Mod servers, and only to
 * users who can read that server's files.
 */
abstract class GmodPage extends Page
{
    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server
            && user()?->can(SubuserPermission::FileRead, $server)
            && app(GarrysMod::class)->detect($server);
    }

    public static function getNavigationGroup(): ?string
    {
        return "Garry's Mod";
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
}
