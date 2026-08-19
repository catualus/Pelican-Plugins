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

    /*
    |--------------------------------------------------------------------------
    | server.properties grouping
    |--------------------------------------------------------------------------
    |
    | A stock server.properties is around sixty settings in one flat alphabetical
    | list, which is a poor way to find the four you came for. These groups are
    | rendered as tabs, in this order, and anything not listed lands in "Other" -
    | so a key added by Paper, a mod, or a future Minecraft version still appears
    | rather than being dropped.
    |
    */

    'property_groups' => [

        'World' => [
            'level-name', 'level-seed', 'level-type', 'generator-settings',
            'gamemode', 'force-gamemode', 'difficulty', 'hardcore',
            'allow-nether', 'generate-structures', 'spawn-monsters',
            'spawn-npcs', 'spawn-animals', 'spawn-protection', 'max-world-size',
            'pvp', 'region-file-compression', 'initial-enabled-packs',
            'initial-disabled-packs',
        ],

        'Players' => [
            'max-players', 'white-list', 'enforce-whitelist', 'online-mode',
            'player-idle-timeout', 'op-permission-level',
            'function-permission-level', 'enforce-secure-profile',
            'allow-flight', 'enable-command-block', 'motd', 'hide-online-players',
            'previews-chat', 'require-resource-pack', 'resource-pack',
            'resource-pack-prompt', 'resource-pack-sha1', 'resource-pack-id',
        ],

        'Performance' => [
            'view-distance', 'simulation-distance', 'entity-broadcast-range-percentage',
            'max-tick-time', 'max-chained-neighbor-updates', 'sync-chunk-writes',
            'use-native-transport', 'network-compression-threshold',
            'rate-limit', 'pause-when-empty-seconds',
        ],

        'Network' => [
            'server-ip', 'server-port', 'query.port', 'enable-query',
            'enable-status', 'prevent-proxy-connections', 'accepts-transfers',
            'log-ips', 'text-filtering-config', 'text-filtering-version',
        ],

        'RCON' => [
            'enable-rcon', 'rcon.port', 'rcon.password', 'broadcast-rcon-to-ops',
            'broadcast-console-to-ops',
        ],

        'JMX & debug' => [
            'enable-jmx-monitoring', 'debug', 'bug-report-link',
        ],

    ],

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
    | Player heads
    |--------------------------------------------------------------------------
    |
    | Shows each player's skin head beside their name on the Players page, which
    | makes a long whitelist far quicker to scan.
    |
    | This is the one thing in this plugin that reaches a third party from the
    | browser: the images are loaded from crafthead.net, which means your players'
    | UUIDs are visible to that service. UUIDs are public identifiers rather than
    | secrets, but if you would rather not, set this to false and the column simply
    | does not render - nothing else changes.
    |
    | The panel itself never makes this request; the browser does, when it draws
    | the page.
    |
    */

    'player_avatars' => true,

    'player_avatar_url' => 'https://crafthead.net/avatar/{uuid}/32',

    /*
    |--------------------------------------------------------------------------
    | Caching and limits
    |--------------------------------------------------------------------------
    */

    'cache_ttl' => 60,

    'max_read_bytes' => 8388608,

];
