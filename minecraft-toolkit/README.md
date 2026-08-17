# Minecraft Toolkit

Minecraft tools for the Pelican server panel. Adds a **Minecraft** navigation group with
three pages, shown only on Minecraft servers and only to users who can read server files.

Works on both modded (Forge / NeoForge / Fabric) and Bukkit-family (Paper / Spigot)
servers - the pages adapt to whichever the server actually is.

## Properties

`server.properties` rendered as a typed form: toggles for booleans, dropdowns for
`difficulty` / `gamemode` / `level-type` / `region-file-compression`, number inputs for
integers, a masked field for `rcon.password`, and a filter box for finding a key fast.

Types are **inferred** from the current value where they aren't declared, so keys added by
Paper, a mod, or a future Minecraft version still render sensibly instead of being dropped.

**Saving is non-destructive.** Minecraft writes this file with Java's `Properties.store`,
which escapes `=`, `:`, `#` and `!` in values - a real file contains
`level-type=minecraft\:normal` - and stamps a dated comment header. Rather than reformat
everything, the original line is kept verbatim for every key you didn't touch, and only
changed values are re-serialised (with correct escaping). Comments, ordering, blank lines
and existing escapes all survive a round trip byte-identically.

## Players

`ops.json`, `whitelist.json`, `banned-players.json` and `banned-ips.json` as editable
tables.

Adding a player resolves their UUID from Mojang first, because **Minecraft matches these
files on UUID** - an entry with only a name is silently ignored by the server. Operator
level is selectable. Removal is behind a confirm.

## Mods / Plugins

Lists everything in `mods` or `plugins`, disabled entries first, with a filter box (useful
when a modpack ships 400+ jars).

Enable / disable renames between `foo.jar` and `foo.jar.disabled` - both modded and
Bukkit-family servers only load files ending in `.jar`, so the rename is what actually
stops it loading. This works on **anything on disk, including CurseForge-installed mods**,
which the `minecraft-modrinth` plugin cannot manage.

Recent crash reports appear in a collapsed section at the top: crashes are usually mod
caused, so the workflow is read the report, find the culprit, disable it below.

Requires `file.update`; the buttons are hidden without it. A restart is needed either way.

## Install

1. Zip the `minecraft-toolkit` folder.
2. Panel -> Admin -> Plugins -> Import -> upload the zip, then enable it.

## Detection

Egg tags (`minecraft`) or the `eula` egg feature, falling back to a Java docker image plus
the presence of `server.properties`. Whether the server loads `mods` or `plugins` is decided
by which directory exists.

## Configuration

`config/minecraft-toolkit.php`:

- **`property_enums`** - keys rendered as a dropdown, and their options. A value not in the
  list is added to it, so saving can never silently change an unrecognised setting.
- **`property_secrets`** - keys rendered as password fields.
- **`property_help`** - hint text shown under a key.
- **`crash_report_limit`** (5) - how many recent crash reports to surface.
- **`cache_ttl`** (60s) - directory listing cache.
- **`max_read_bytes`** (8 MB) - refuse to read files larger than this.

## Overlap with other plugins

This deliberately does **not** duplicate what's already on the hub:

- [`minecraft-modrinth`](https://github.com/pelican-dev/plugins/tree/main/minecraft-modrinth)
  installs mods and plugins from Modrinth. This manages what's already on disk.
- [`mclogs-uploader`](https://github.com/pelican-dev/plugins/tree/main/mclogs-uploader)
  shares logs to mclo.gs. This only points you at crash reports.

---

Part of [Pelican Panel Plugins](../README.md). MIT licensed. Written with AI assistance -
see the [disclosure](../README.md#ai-disclosure) in the repository README.
