<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lua error logs
    |--------------------------------------------------------------------------
    |
    | Read in order; each is optional and skipped silently when absent. The hint
    | is shown in the empty state so you know why a source has nothing in it.
    |
    */

    'error_logs' => [
        'server' => [
            'label' => 'Server-side',
            'path' => 'garrysmod/console.log',
            'hint' => 'Add -condebug to your startup command and restart to have Garry\'s Mod write this file.',
        ],
        'client' => [
            'label' => 'Client-side',
            'path' => 'garrysmod/clientside_errors.txt',
            'hint' => 'Written when a player\'s client reports a Lua error back to the server.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | DarkRP shortcuts
    |--------------------------------------------------------------------------
    |
    | Folders opened in the file manager. Each is checked before being shown, so
    | a server without DarkRP simply sees no shortcuts.
    |
    */

    'darkrp_paths' => [
        ['label' => 'DarkRP config', 'path' => 'garrysmod/gamemodes/darkrp/gamemode/config'],
        ['label' => 'Custom jobs & entities', 'path' => 'garrysmod/addons/darkrpmodification/lua/darkrp_customthings'],
        ['label' => 'Modification config', 'path' => 'garrysmod/addons/darkrpmodification/lua/darkrp_config'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Workshop collection variable
    |--------------------------------------------------------------------------
    |
    | Startup variables checked, in order, for the workshop collection id. The
    | stock Garry's Mod egg uses WORKSHOP_ID; forks sometimes rename it.
    |
    */

    'workshop_variables' => ['WORKSHOP_ID', 'WORKSHOP_COLLECTION_ID', 'WORKSHOP'],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | cache_ttl           seconds to cache directory listings (0 disables)
    | workshop_cache_ttl  seconds to cache Steam responses; Steam rate limits, and
    |                     collections rarely change, so keep this generous
    | max_log_bytes       refuse to read error logs larger than this
    |
    */

    'cache_ttl' => 60,

    'workshop_cache_ttl' => 900,

    /*
    | Parsed error digests are keyed on each log's mtime and size, so an unchanged
    | log is never re-downloaded or re-parsed, and a changed one is picked up at
    | once. This can safely be long; it is not a staleness window.
    */

    'digest_cache_ttl' => 3600,

    'max_log_bytes' => 16777216,

    /*
    |--------------------------------------------------------------------------
    | Workshop "recently updated" window
    |--------------------------------------------------------------------------
    |
    | Days within which a collection addon is flagged as recently updated. An
    | addon that changed just before things started breaking is usually the cause.
    |
    */

    'recent_update_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | "Still happening" window
    |--------------------------------------------------------------------------
    |
    | These logs contain no timestamps, so recency is measured by position: an
    | addon is treated as still erroring if it appears in the last N errors of the
    | log. Everything else is marked stale.
    |
    | Raise this if your server is quiet and the log spans months; lower it if the
    | log fills quickly and you only care about the last few minutes.
    |
    */

    'recent_window' => 100,

];
