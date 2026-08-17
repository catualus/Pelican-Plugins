<?php

use Catualus\QuickNav\Enums\ServerFamily;

return [

    /*
    |--------------------------------------------------------------------------
    | Toolbar position
    |--------------------------------------------------------------------------
    |
    | Which Filament table render hook the Quick Access button hangs off.
    |
    | The toolbar is a flex row of three children: the icon cluster, the search
    | block, and hooks either side. Anchoring to tables::toolbar.start makes the
    | button a fourth child, which splits the row apart - so the options below sit
    | inside the icon cluster itself.
    |
    |   tables::toolbar.grouping-selector.before   last icon in the cluster (default)
    |   tables::toolbar.reorder-trigger.before     first icon in the cluster
    |   tables::toolbar.search.before              between the icons and the search box
    |
    | Do not use tables::toolbar.end - Filament renders that one without passing
    | scopes, so a scoped registration there never fires.
    |
    */

    'toolbar_hook' => 'tables::toolbar.grouping-selector.before',

    /*
    |--------------------------------------------------------------------------
    | Listing cache
    |--------------------------------------------------------------------------
    |
    | Seconds to cache each directory listing used to decide whether a shortcut
    | applies. The sidebar renders on every server-panel page, so without this the
    | daemon would be probed on each one. Set to 0 to always check live.
    |
    | A folder created outside the panel takes up to this long to appear as a
    | shortcut. Failed probes are never cached.
    |
    */

    'cache_ttl' => 600,

    /*
    |--------------------------------------------------------------------------
    | Quick Access presets
    |--------------------------------------------------------------------------
    |
    | Shortcuts offered per server family. Only directories belong here - the
    | shortcuts navigate the file listing, they do not open the editor.
    |
    | Every path is checked against the server before being offered, so a shortcut
    | is never shown for a directory that is not there. That is what lets a single
    | Minecraft preset list cover both a Paper server (plugins/) and a CurseForge
    | modpack (mods/, kubejs/) with no per-server configuration.
    |
    | Checking costs one daemon listing per distinct parent directory, cached per
    | request. Root-level presets are therefore free after the first; nesting a
    | preset deeper adds a round trip per level, so keep paths shallow.
    |
    */

    'presets' => [

        ServerFamily::GarrysMod->value => [
            ['label' => 'Addons', 'path' => 'garrysmod/addons', 'icon' => 'tabler-package'],
            ['label' => 'Gamemodes', 'path' => 'garrysmod/gamemodes', 'icon' => 'tabler-device-gamepad-2'],
            ['label' => 'Maps', 'path' => 'garrysmod/maps', 'icon' => 'tabler-map'],
            ['label' => 'Lua', 'path' => 'garrysmod/lua', 'icon' => 'tabler-code'],
            ['label' => 'Config', 'path' => 'garrysmod/cfg', 'icon' => 'tabler-settings'],
            ['label' => 'Data', 'path' => 'garrysmod/data', 'icon' => 'tabler-database'],
            ['label' => 'Workshop cache', 'path' => 'garrysmod/cache/workshop', 'icon' => 'tabler-cloud-download'],
        ],

        // Every Source game lays its content out differently under its own game
        // directory, so there is nothing safe to assume beyond Garry's Mod above.
        ServerFamily::Source->value => [],

        ServerFamily::Minecraft->value => [
            ['label' => 'Mods', 'path' => 'mods', 'icon' => 'tabler-package'],
            ['label' => 'Plugins', 'path' => 'plugins', 'icon' => 'tabler-plug'],
            ['label' => 'Config', 'path' => 'config', 'icon' => 'tabler-settings'],
            ['label' => 'Default configs', 'path' => 'defaultconfigs', 'icon' => 'tabler-settings-cog'],
            ['label' => 'KubeJS', 'path' => 'kubejs', 'icon' => 'tabler-code'],
            ['label' => 'World', 'path' => 'world', 'icon' => 'tabler-world'],
            ['label' => 'Logs', 'path' => 'logs', 'icon' => 'tabler-file-text'],
            ['label' => 'Crash reports', 'path' => 'crash-reports', 'icon' => 'tabler-alert-triangle'],
        ],

        ServerFamily::Node->value => [
            ['label' => 'Source', 'path' => 'src', 'icon' => 'tabler-code'],
            ['label' => 'Build output', 'path' => 'dist', 'icon' => 'tabler-package'],
            ['label' => 'Config', 'path' => 'config', 'icon' => 'tabler-settings'],
            ['label' => 'Data', 'path' => 'data', 'icon' => 'tabler-database'],
            ['label' => 'Logs', 'path' => 'logs', 'icon' => 'tabler-file-text'],
        ],

        ServerFamily::Python->value => [
            ['label' => 'Cogs', 'path' => 'cogs', 'icon' => 'tabler-puzzle'],
            ['label' => 'Source', 'path' => 'src', 'icon' => 'tabler-code'],
            ['label' => 'Config', 'path' => 'config', 'icon' => 'tabler-settings'],
            ['label' => 'Data', 'path' => 'data', 'icon' => 'tabler-database'],
            ['label' => 'Logs', 'path' => 'logs', 'icon' => 'tabler-file-text'],
        ],

        ServerFamily::Postgres->value => [
            ['label' => 'Database', 'path' => 'postgres_db', 'icon' => 'tabler-database'],
            ['label' => 'Logs', 'path' => 'postgres_db/log', 'icon' => 'tabler-file-text'],
        ],

        // Nothing worth guessing at - the button hides itself entirely.
        ServerFamily::Generic->value => [],

    ],

];
