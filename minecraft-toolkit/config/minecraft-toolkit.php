<?php

return [

    /*
    |--------------------------------------------------------------------------
    | server.properties field types
    |--------------------------------------------------------------------------
    |
    | Booleans and integers are inferred from the current value, so keys added by
    | Paper, a mod, or a future Minecraft version still render sensibly without
    | being listed here. Only enums and secrets need declaring.
    |
    */

    'property_enums' => [
        'difficulty' => ['peaceful', 'easy', 'normal', 'hard'],
        'gamemode' => ['survival', 'creative', 'adventure', 'spectator'],
        'level-type' => [
            'minecraft:normal',
            'minecraft:flat',
            'minecraft:large_biomes',
            'minecraft:amplified',
            'minecraft:single_biome_surface',
        ],
        'region-file-compression' => ['deflate', 'lz4', 'none'],
    ],

    'property_secrets' => ['rcon.password'],

    'property_help' => [
        'white-list' => 'Only players on whitelist.json may join.',
        'enforce-whitelist' => 'Kicks players already online who are not whitelisted.',
        'online-mode' => 'Verifies players against Mojang. Turning this off allows cracked clients and lets anyone impersonate any username.',
        'spawn-protection' => 'Radius in blocks around spawn that non-operators cannot build in. 0 disables it.',
        'view-distance' => 'Chunks sent to each player. The single biggest lever on memory and CPU.',
        'simulation-distance' => 'Chunks where entities and blocks actually tick.',
        'max-tick-time' => 'Milliseconds before the watchdog kills a hung server. -1 disables it.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Crash reports
    |--------------------------------------------------------------------------
    |
    | How many recent crash reports to surface on the mods page.
    |
    */

    'crash_report_limit' => 5,

    /*
    |--------------------------------------------------------------------------
    | Caching and limits
    |--------------------------------------------------------------------------
    */

    'cache_ttl' => 60,

    'max_read_bytes' => 8388608,

];
