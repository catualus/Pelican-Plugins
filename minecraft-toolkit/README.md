# Minecraft Toolkit

Minecraft tools for the Pelican server panel. Adds a **Minecraft** navigation group with
three pages, shown only on Minecraft servers and only to users who can read server files.

Works on both modded (Forge / NeoForge / Fabric) and Bukkit-family (Paper / Spigot)
servers - the pages adapt to whichever the server actually is.

## Properties

`server.properties` rendered as a typed form: checkboxes for booleans, dropdowns for
`difficulty` / `gamemode` / `level-type` / `region-file-compression`, number inputs for
integers, and a reveal toggle for `rcon.password`.

A stock `server.properties` is around sixty settings in one flat alphabetical list, which
is a poor way to find the four you came for - so they are **grouped into tabs**: World,
Players, Performance, Network, RCON and JMX & debug, with anything unrecognised in Other.
Searching spans every group, because a search that only looked inside the open tab would
hide the match you were looking for.

Changed fields are marked as changed, with the previous value shown underneath, and the tab
carries a badge for how many unsaved changes are hiding inside it. **Save changes** names
exactly what it is about to rewrite before doing it, and is disabled when nothing has
changed.

Types are **inferred** from the current value where they aren't declared, so keys added by
Paper, a mod, or a future Minecraft version still render sensibly instead of being dropped.

**Saving is non-destructive.** Minecraft writes this file with Java's `Properties.store`,
which escapes `=`, `:`, `#` and `!` in values - a real file contains
`level-type=minecraft\:normal` - and stamps a dated comment header. Rather than reformat
everything, the original line is kept verbatim for every key you didn't touch, and only
changed values are re-serialised (with correct escaping). Comments, ordering, blank lines
and existing escapes all survive a round trip byte-identically, and that is covered by
tests.

## Players

`ops.json`, `whitelist.json`, `banned-players.json` and `banned-ips.json` as sortable,
searchable tables behind one set of tabs, each showing its count.

Adding a player resolves their UUID first, because **Minecraft matches these files on
UUID** - an entry with only a name is silently ignored by the server. The lookup tries
`api.minecraftservices.com` and falls back to `api.mojang.com`, so a rate limit or an
outage on one does not stop somebody being whitelisted. Operator level is a dropdown that
says what each level actually grants.

Removal works one at a time or in bulk; a bulk removal is a single rewrite of the file
rather than one read-modify-write per player.

Player heads are shown beside names to make a long whitelist quicker to scan. That is the
one thing in this plugin that reaches a third party: the images are loaded **by your
browser** from crafthead.net, so your players' UUIDs are visible to that service. UUIDs are
public identifiers rather than secrets, but if you would rather not, set `player_avatars`
to `false` and the column simply does not render.

## Mods / Plugins

Everything in `mods` or `plugins` as a sortable table with search, sizes and modified
dates - useful when a modpack ships 400+ jars.

Enable / disable renames between `foo.jar` and `foo.jar.disabled` - both modded and
Bukkit-family servers only load files ending in `.jar`, so the rename is what actually
stops it loading. This works on **anything on disk, including CurseForge-installed mods**,
which the `minecraft-modrinth` plugin cannot manage. Select many and toggle them all in one
go; you get one honest summary rather than forty notifications.

Nothing is ever deleted, and a banner appears once changes are on disk but not yet in
effect, because renaming a file does nothing until the server restarts and re-reads the
directory.

### Crash reports

Crash reports are read rather than merely listed. Each one is summarised down to its
`Description:` line, the exception under it, and the **first stack frame that is not
Minecraft, Java or the mod loader** - which is usually the mod at fault, and is labelled as
a likely cause rather than a certain one. A report never changes after it is written, so
each summary is parsed once and cached.

Requires `file.update` to change anything; the controls are hidden without it.

## Install

1. Zip the `minecraft-toolkit` folder.
2. Panel -> Admin -> Plugins -> Import -> upload the zip, then enable it.

## Detection

Egg tags (`minecraft`) or the `eula` egg feature, falling back to a Java docker image plus
the presence of `server.properties`. Whether the server loads `mods` or `plugins` is decided
by which directory exists.

## Configuration

`config/minecraft-toolkit.php`:

- **`property_groups`** - which settings appear under which tab, in order. Anything not
  listed lands in Other rather than disappearing.
- **`property_enums`** - keys rendered as a dropdown, and their options. A value not in the
  list is added to it, so saving can never silently change an unrecognised setting.
- **`property_secrets`** - keys hidden behind a reveal toggle.
- **`property_help`** - hint text shown under a key.
- **`player_avatars`** (true) / **`player_avatar_url`** - player heads on the Players page.
  See the note above about the third-party request.
- **`crash_report_limit`** (5) - how many recent crash reports to read and summarise.
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
