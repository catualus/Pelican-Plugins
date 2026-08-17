# GMod Toolkit

Garry's Mod tools for the Pelican server panel. Adds a **Garry's Mod** navigation group
with three pages, shown only on GMod servers and only to users who can read server files.

## Addons

Lists every folder in `garrysmod/addons`, **sorted by what is erroring now**, then by worst
overall - so the addon actually causing trouble is at the top rather than buried
alphabetically among dozens of names. A red count is still happening; a grey one has not
recurred recently. Each row links straight into that folder in the file manager.

**Enable / disable** moves a folder between `addons/` and `addons/__disabled/`. Garry's Mod
loads every direct child of `addons`, so this is what actually unloads an addon - and it's
the convention most server owners already use by hand. Requires the `file.update` permission; the
buttons are hidden without it. A restart is still needed for the change to take effect.

DarkRP config folders appear as shortcut buttons above the list when they exist.

## Lua errors

Digests Lua error logs into a per-addon table: how many errors, how many distinct players
reported them, and the most recent message. The addon name links to its folder, and the
`file.lua:42` under each message opens that exact file in the panel's editor.

Two sources, each optional:

| Source | File | Notes |
|---|---|---|
| Server-side | `garrysmod/console.log` | Only written if `-condebug` is in your startup command |
| Client-side | `garrysmod/clientside_errors.txt` | Errors players' clients reported back |

Errors are attributed by the `[tag]` GMod prints, falling back to the folder the failing
file lives in - so `[ERROR]` lines still get billed to the right addon, and DarkRP's errors
land on `gamemodes/darkrp` rather than being mistaken for an addon.

### Active vs stale

**These logs contain no timestamps.** Not dates, not clock times, not unix values - Garry's
Mod simply doesn't write them, so a true "last errored at 14:32" is not recoverable.

What is recoverable is *order*: the logs are append-only, so position is a real recency
signal. An addon is marked **Active** if it appears in the last 100 errors
(`recent_window`); otherwise it shows how many errors ago it was last seen and is greyed
out. The file's own mtime is shown as a wall-clock anchor for the newest error in it.

This is why the Addons page sorts by *active* errors before total: an addon that threw 200
errors last month matters less than one throwing them now.

**Clear log** truncates a log so the next report starts clean - the surest way to separate
history from what is happening now. It empties the file rather than deleting it, since
Garry's Mod keeps appending to the same handle. Requires `file.update`.

## Workshop

Reads `WORKSHOP_ID` from the server's startup variables and lists the collection: title,
size, last update, subscriber count, and a link to each item. Addons updated in the last 7
days are flagged - a recent change is the usual explanation for something that broke
overnight. Banned items are called out too.

Uses Steam's `GetCollectionDetails` and `GetPublishedFileDetails`, both of which accept
unauthenticated requests. **No Steam API key is needed or asked for.** Responses are cached
for 15 minutes.

## Install

1. Zip the `gmod-toolkit` folder.
2. Panel -> Admin -> Plugins -> Import -> upload the zip, then enable it.

## Detection

Egg tags (`gmod`, `garrysmod`) win if present. Otherwise the server is narrowed to Source
by egg features (`gsl_token`, `steam_disk_space`) or docker image, then confirmed by probing
for the `garrysmod/` directory - every Source egg carries identical features, so metadata
alone cannot tell GMod from CS2.

## Configuration

`config/gmod-toolkit.php`:

- **`error_logs`** - which files to digest, their labels, and the hint shown when absent.
- **`darkrp_paths`** - shortcut folders; each is existence-checked before being shown.
- **`cache_ttl`** (60s) - directory listing cache.
- **`workshop_cache_ttl`** (900s) - Steam response cache; Steam rate limits, so keep it generous.
- **`digest_cache_ttl`** (1h) - parsed error digests. Keyed on each log's mtime and size, so
  an unchanged log is never re-read and a changed one is picked up at once. This is not a
  staleness window and can safely be long.
- **`recent_window`** (100) - how many trailing errors count as "still happening".
- **`recent_update_days`** (7) - the "recently updated" window for workshop items.
- **`max_log_bytes`** (16 MB) - refuse to read error logs larger than this.

## Notes

Addon *sizes* aren't shown: a directory listing reports the inode size (4096), not the
recursive total, and walking every addon would cost one daemon call per folder.

---

Part of [Pelican Panel Plugins](../README.md). MIT licensed. Written with AI assistance -
see the [disclosure](../README.md#ai-disclosure) in the repository README.
