# Quick Nav

Egg-aware **Quick Access** shortcuts in the Pelican file manager. Adds a single star button
to the file manager's own toolbar, alongside New File / New Folder / Upload, which drops
down a list of the folders that actually matter for the game running on that server - no
clicking down through the tree.

Collapsed to one icon by default, and it exists nowhere else in the panel.

| Server type | Shortcuts offered |
|---|---|
| Garry's Mod | addons, gamemodes, maps, lua, cfg, data, workshop cache |
| Minecraft | mods, plugins, config, defaultconfigs, kubejs, world, logs, crash-reports |
| Node.js | src, dist, config, data, logs |
| Python | cogs, src, config, data, logs |
| PostgreSQL | postgres_db, postgres_db/log |

Shortcuts are filtered against the real filesystem, so a Paper server shows `plugins` and a
CurseForge modpack shows `mods` and `kubejs` from the same preset list, with no per-server
configuration.

## Pinned and recent folders

Underneath the presets, the dropdown offers two more sections:

- **Pinned** - folders you chose to keep. Whatever folder you are currently looking at can
  be pinned or unpinned from the bottom of the menu.
- **Recent** - the last few folders you opened on this server, newest first, with the one
  you are standing in left out.

These work on any server, including ones with no presets at all - which is why the button
now appears on servers Quick Nav previously had nothing to say about. Set `history_limit` to
`0` to switch both off, and the button goes back to hiding itself when there are no
shortcuts.

### Where they are stored

**In your browser's localStorage, per server. Nothing about them reaches the panel.**

That is a deliberate choice. They are a per-person, per-browser convenience; the panel gives
plugins no durable per-user storage without a database migration, which this plugin does not
want; and the application cache would lose them silently the first time it was cleared.
localStorage is honest about what it is, costs the panel nothing, and keeps one subuser's
pins out of another's dropdown. If localStorage is unavailable the preset shortcuts still
work and these two sections simply never appear.

There is deliberately **no global keyboard shortcut**. Binding a key in a screen where
people type file names is a good way to cause a surprise.

## Install

1. Zip the `quick-nav` folder.
2. Panel -> Admin -> Plugins -> Import -> upload the zip.
3. Enable the plugin.

## How it detects the game

`ServerFamily::fromServer()` reads, in order:

1. **Egg tags** (`minecraft`, `source`, `gmod`, ...) - the ecosystem convention, matching
   `ModrinthProjectType::fromServer()` in the `minecraft-modrinth` plugin.
2. **Egg features** (`eula` -> Minecraft, `steam_disk_space`/`gsl_token` -> Source).
3. **Docker image** (`nodejs` / `python` / `postgres`).

Garry's Mod cannot be told apart from other Source games by egg metadata - every Source egg
carries identical features - so it is confirmed by probing for the `garrysmod/` directory.

If your eggs carry tags, detection is exact. If they don't, the feature and image fallbacks
cover the common cases. Unrecognised servers get no shortcuts and the button hides itself.

## Customising

Edit `config/quick-nav.php`.

**Presets** - each entry is a `label`, a `path` (directories only: these navigate the file
listing, they do not open the editor), and a Tabler `icon` name.

**`toolbar_hook`** - where in the toolbar the button sits. Filament's toolbar is a flex row
of three children (icon cluster, search block, hooks either side), so anchoring to
`tables::toolbar.start` makes the button a fourth child and splits the row apart. The
default anchors *inside* the icon cluster instead:

| Value | Position |
|---|---|
| `tables::toolbar.grouping-selector.before` | last icon in the cluster (default) |
| `tables::toolbar.reorder-trigger.before` | first icon in the cluster |
| `tables::toolbar.search.before` | between the icons and the search box |

Don't use `tables::toolbar.end` - Filament renders that hook without passing scopes, so a
scoped registration there never fires.

**`cache_ttl`** - seconds to cache directory listings (default 600). The sidebar renders on
every server-panel page, so without this the daemon would be probed on each one. A folder
created outside the panel takes up to this long to show up; set `0` to always check live.
Failed probes are never cached, so shortcuts return as soon as a downed node does.

Keep preset paths shallow - existence checking costs one listing per distinct parent
directory. Root-level presets are effectively free after the first.

**`history_limit`** - how many pinned and how many recent folders the dropdown offers
(default 6). Set to `0` to remove both sections; with no presets either, the button hides
itself entirely.

## Permissions

Shortcuts are hidden from subusers without `file.read`, and the group disappears entirely
when nothing resolves.

## Not included

Shortcuts to individual *files* - `server.properties`, `.env` - would need the editor route
rather than the listing route, and are deliberately out of scope here.

---

Part of [Pelican Panel Plugins](../README.md). MIT licensed. Written with AI assistance -
see the [disclosure](../README.md#ai-disclosure) in the repository README.
