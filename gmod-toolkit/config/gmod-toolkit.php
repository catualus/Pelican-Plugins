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
    | Unauthenticated GitHub allows 60 requests an hour, and the only thing asked of
    | it is "what commit is this branch on" - which changes rarely and matters little
    | if it is a few hours out of date. Six hours by default.
    */

    'github_cache_ttl' => 21600,

    /*
    | Most commit lookups the Addon library will make on a single page load, so a
    | cold cache cannot turn one page open into thirty GitHub requests. Anything
    | beyond this is simply left unchecked and reported as such.
    */

    'github_lookups_per_load' => 12,

    /*
    |--------------------------------------------------------------------------
    | Addon downloads
    |--------------------------------------------------------------------------
    |
    | The panel downloads a catalogue addon's archive itself and hands the bytes to
    | the daemon, because the daemon's own remote-download endpoint refuses any
    | response that arrives without a Content-Length - and GitHub builds archives on
    | demand and streams them chunked, so it never sends one.
    |
    | That means the archive is briefly held in the panel's memory and the whole
    | download has to finish inside one PHP request. Both defaults are set to sit
    | comfortably inside a stock php.ini (memory_limit 128M, max_execution_time 30):
    | a timeout longer than max_execution_time would just turn a clean "that took too
    | long" into a 500. Raise both together if you raise those, and remember the write
    | to the daemon shares the same clock.
    |
    | For reference, the largest archive in the shipped catalogue is Wiremod at
    | roughly 8 MB; most are well under 1 MB.
    |
    */

    'max_download_bytes' => 33554432,

    'download_timeout' => 20,

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
    | Client download estimate
    |--------------------------------------------------------------------------
    |
    | The whole collection is what every joining player downloads before they can
    | play. This is the line speed, in megabits per second, used to turn that size
    | into a wait - set it to something like what your players actually have.
    |
    */

    'client_download_mbps' => 50,

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

    /*
    | Slices the log is divided into for the timeline chart. More slices show finer
    | bursts; fewer are smoother. Purely a display choice.
    */

    'timeline_buckets' => 40,

    /*
    |--------------------------------------------------------------------------
    | Disk usage scan
    |--------------------------------------------------------------------------
    |
    | The daemon has no way to report the size of a directory, so measuring what
    | each addon costs on disk means walking the tree - one request per folder.
    | That is far too expensive to do on page load, so it is an explicit action,
    | bounded by both a request count and a wall-clock limit. A scan that runs out
    | of either says so rather than reporting a wrong total.
    |
    | The scan runs a few addons at a time so the page can show what it is measuring
    | and how far along it is, rather than freezing on a click.
    |
    |   disk_scan_requests     most folder listings spent on any one addon
    |   disk_scan_step_seconds wall-clock ceiling per step, so no single request hangs
    |   disk_scan_addons       addons measured per step
    |
    | Raise disk_scan_requests if your addons are deeply nested and scans keep
    | coming back partial.
    |
    */

    'disk_scan_requests' => 400,

    'disk_scan_step_seconds' => 10,

    'disk_scan_addons' => 4,

    'disk_scan_cache_ttl' => 86400,

    /*
    |--------------------------------------------------------------------------
    | Addon catalogue
    |--------------------------------------------------------------------------
    |
    | What the Addon library offers to install. Every entry names a fixed GitHub
    | repository - there is deliberately no way to type a URL into the interface,
    | because this downloads and extracts code onto a running game server.
    |
    |   source       'github' installs; 'workshop' only links out (see below)
    |   repo         owner/name on github.com
    |   ref          branch or tag to install from
    |   target       'addons' or 'gamemodes', relative to garrysmod/
    |   folder       what the extracted folder is renamed to
    |   requires     ids that must be installed first
    |   detect       extra folder names that count as "already installed", so an
    |                addon you installed by hand under another name is recognised
    |   workshop_id  shown for reference even on GitHub entries
    |
    | Garry's Mod loads every direct child of addons/ as a legacy (non-GMA) addon,
    | which is exactly what a GitHub checkout is - so this works for anything whose
    | repository root is the addon root. All of these were checked.
    |
    | A note on 'workshop' entries: the panel cannot add an item to a Steam
    | collection. There is no unauthenticated API for it, and the collection is
    | owned by a Steam account the panel knows nothing about. Those entries show a
    | copyable id and a link, and nothing more - which is honest about the limit
    | rather than pretending to a capability that does not exist.
    |
    */

    'catalogue' => [

        [
            'id' => 'advdupe2',
            'name' => 'Advanced Duplicator 2',
            'description' => 'The standard duplicator. Saves and pastes contraptions, including constraints and Wire connections.',
            'category' => 'Building',
            'source' => 'github',
            'repo' => 'wiremod/advdupe2',
            'ref' => 'master',
            'target' => 'addons',
            'folder' => 'advdupe2',
            'requires' => [],
            'detect' => ['advanced-duplicator-2', 'AdvDupe2'],
        ],

        [
            'id' => 'wire',
            'name' => 'Wiremod',
            'description' => 'Wire tools, gates and E2. The largest entry here at about 8 MB, and the dependency a great many other addons assume.',
            'category' => 'Building',
            'source' => 'github',
            'repo' => 'wiremod/wire',
            'ref' => 'master',
            'target' => 'addons',
            'folder' => 'wire',
            'requires' => [],
            'detect' => ['wiremod', 'wire-extras'],
        ],

        [
            'id' => 'improved-stacker',
            'name' => 'Improved Stacker',
            'description' => 'Stacks props along an axis, with per-player limits admins can configure. A rewrite of the original Stacker tool and the one still maintained.',
            'category' => 'Building',
            'source' => 'github',
            'repo' => 'Mista-Tea/improved-stacker',
            'ref' => 'master',
            'target' => 'addons',
            'folder' => 'improved-stacker',
            'requires' => [],
            'detect' => ['stacker', 'stacker-fixed'],
            'workshop_id' => '264467687',
        ],

        [
            'id' => 'improved-weight',
            'name' => 'Improved Weight',
            'description' => 'Sets prop mass with limits and undo support. Companion to Improved Stacker.',
            'category' => 'Building',
            'source' => 'github',
            'repo' => 'Mista-Tea/improved-weight',
            'ref' => 'master',
            'target' => 'addons',
            'folder' => 'improved-weight',
            'requires' => [],
            'detect' => ['weight'],
        ],

        [
            'id' => 'improved-antinoclip',
            'name' => 'Improved Anti-Noclip',
            'description' => 'Marks props players may not noclip through. Useful on build servers with bases.',
            'category' => 'Building',
            'source' => 'github',
            'repo' => 'Mista-Tea/improved-antinoclip',
            'ref' => 'master',
            'target' => 'addons',
            'folder' => 'improved-antinoclip',
            'requires' => [],
            'detect' => ['antinoclip'],
        ],

        [
            'id' => 'permaprops',
            'name' => 'PermaProps',
            'description' => 'Saves props and entities so they survive a map change or restart. Admin tool.',
            'category' => 'Building',
            'source' => 'github',
            'repo' => 'MalboroDEV/PermaProps',
            'ref' => 'master',
            'target' => 'addons',
            'folder' => 'permaprops',
            'requires' => [],
            'detect' => ['PermaProps'],
        ],

        [
            'id' => 'textscreens',
            'name' => '3D2D Textscreens',
            'description' => 'Places flat text in the world. The usual way rules and signage get put up.',
            'category' => 'Building',
            'source' => 'github',
            'repo' => 'Cherry/3D2D-Textscreens',
            'ref' => 'main',
            'target' => 'addons',
            'folder' => '3d2d-textscreens',
            'requires' => [],
            'detect' => ['textscreens', '3D2D-Textscreens'],
        ],

        [
            'id' => 'pac3',
            'name' => 'PAC3',
            'description' => 'Player appearance customisation. Large, and worth reading its own configuration notes before opening it to everyone.',
            'category' => 'Player',
            'source' => 'github',
            'repo' => 'CapsAdmin/pac3',
            'ref' => 'master',
            'target' => 'addons',
            'folder' => 'pac3',
            'requires' => [],
            'detect' => ['pac'],
            'notes' => 'Grants clients a lot of rendering control. Check its permission settings before leaving it open to non-admins.',
        ],

        [
            'id' => 'ulib',
            'name' => 'ULib',
            'description' => 'The library ULX is built on. Installed on its own only if you want the library without the admin menu.',
            'category' => 'Administration',
            'source' => 'github',
            'repo' => 'TeamUlysses/ulib',
            'ref' => 'master',
            'target' => 'addons',
            'folder' => 'ulib',
            'requires' => [],
            'detect' => ['ULib'],
        ],

        [
            'id' => 'ulx',
            'name' => 'ULX',
            'description' => 'The long-standing free admin mod: groups, bans, kicks and a command menu.',
            'category' => 'Administration',
            'source' => 'github',
            'repo' => 'TeamUlysses/ulx',
            'ref' => 'master',
            'target' => 'addons',
            'folder' => 'ulx',
            'requires' => ['ulib'],
            'detect' => ['ULX'],
        ],

        [
            'id' => 'fpp',
            'name' => "Falco's Prop Protection",
            'description' => 'Stops players touching each other\'s props. Effectively required on any server open to strangers.',
            'category' => 'Administration',
            'source' => 'github',
            'repo' => 'FPtje/Falcos-Prop-protection',
            'ref' => 'master',
            'target' => 'addons',
            'folder' => 'fpp',
            'requires' => [],
            'detect' => ['falcos-prop-protection', 'prop-protection'],
        ],

        [
            'id' => 'cfc-http-whitelist',
            'name' => 'CFC HTTP Whitelist',
            'description' => 'Restricts which domains clientside addons may fetch from. Defence against a compromised addon exfiltrating data.',
            'category' => 'Administration',
            'source' => 'github',
            'repo' => 'CFC-Servers/cfc_cl_http_whitelist',
            'ref' => 'main',
            'target' => 'addons',
            'folder' => 'cfc_cl_http_whitelist',
            'requires' => [],
            'detect' => [],
        ],

        [
            'id' => 'darkrp',
            'name' => 'DarkRP',
            'description' => 'The roleplay gamemode. Installs to gamemodes/, not addons/ - set your startup gamemode to darkrp afterwards.',
            'category' => 'Gamemode',
            'source' => 'github',
            'repo' => 'FPtje/DarkRP',
            'ref' => 'master',
            'target' => 'gamemodes',
            'folder' => 'darkrp',
            'requires' => [],
            'detect' => ['DarkRP'],
            'holds_config' => true,
            'notes' => 'Change the gamemode on the Startup page to darkrp, and configure it through DarkRP Modification rather than by editing the gamemode.',
        ],

        [
            'id' => 'darkrpmodification',
            'name' => 'DarkRP Modification',
            'description' => 'Where DarkRP jobs, entities and settings belong. Editing the gamemode directly is what makes DarkRP unupgradable.',
            'category' => 'Gamemode',
            'source' => 'github',
            'repo' => 'FPtje/darkrpmodification',
            'ref' => 'master',
            'target' => 'addons',
            'folder' => 'darkrpmodification',
            'requires' => ['darkrp'],
            'detect' => [],
            // The whole point of this addon is to hold your jobs, entities and
            // settings, so uninstalling it deletes your work rather than an addon.
            'holds_config' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Presets
    |--------------------------------------------------------------------------
    |
    | Bundles installed in one go. Dependencies are resolved from the catalogue, so
    | listing ulx here also brings ulib.
    |
    */

    'presets' => [

        [
            'id' => 'sandbox',
            'name' => 'Sandbox essentials',
            'description' => 'What a build server is expected to have. Nothing here changes gameplay rules.',
            'addons' => ['advdupe2', 'wire', 'improved-stacker', 'improved-weight', 'permaprops'],
        ],

        [
            'id' => 'admin',
            'name' => 'Administration',
            'description' => 'Admin menu and prop protection - the minimum before opening a server to strangers.',
            'addons' => ['ulx', 'fpp'],
        ],

        [
            'id' => 'darkrp',
            'name' => 'DarkRP starter',
            'description' => 'The gamemode, the modification addon that configures it, and prop protection.',
            'addons' => ['darkrp', 'darkrpmodification', 'fpp'],
        ],

    ],

];
