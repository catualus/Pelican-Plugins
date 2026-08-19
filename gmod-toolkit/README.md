# GMod Toolkit

Garry's Mod tools for the Pelican server panel. Adds a **Garry's Mod** navigation group
with five pages, shown only on GMod servers and only to users who can read server files.

Everything is a real Filament table, so every list sorts, filters, searches and paginates
like the rest of the panel, and the charts are the panel's own Chart.js widgets - they
follow your theme, light or dark, without being told to.

## Overview

The page that answers *is this server all right* without visiting the other four.

- **Client download** - the whole workshop collection is what every joining player has to
  download before they can play, expressed both as a size and as a wait at an assumed line
  speed. Nothing else in the panel surfaces this, and it is the number that decides whether
  a collection has grown past what people will sit through.
- **Erroring now**, **installed addons**, **addons on disk**, and how many catalogue addons
  are present.
- **What is failing right now** - the five addons appearing in the recent window of a log,
  worst first, with their latest message.
- Callouts for collection items that are banned on the workshop or are not Garry's Mod items
  at all - both are silent failures the Steam collection page gives no hint of.

## Addons

Every folder in `garrysmod/addons`, sorted by what is erroring *now* and then by worst
overall - so the addon actually causing trouble is at the top rather than buried
alphabetically among dozens of names.

- **Enable / disable** moves a folder between `addons/` and `addons/__disabled/`. Garry's
  Mod loads every direct child of `addons`, so this is what actually unloads an addon.
  Available in bulk: select forty, disable them, get one summary rather than forty toasts.
- **Filters** for enabled/disabled and "erroring now".
- **Scan disk usage** measures what each addon costs on disk and charts it. This is an
  explicit action rather than something the page does on load, because the daemon cannot
  report a directory's size — the only way to get it is to walk the tree, one request per
  folder. It runs a few addons at a time and shows a progress bar, which addon it is
  measuring, and how many daemon requests it has spent, so a large addons folder does not
  mean staring at a frozen button. It can be stopped, and says plainly when a folder was
  too deep to reach within its budget rather than reporting a total it does not have.
- DarkRP config folders appear under a **DarkRP** menu when they exist.

Requires `file.update` to change anything; the controls are hidden without it. A restart is
needed for any change to take effect.

## Lua errors

Digests Lua error logs into a per-addon table: how many errors, how many distinct players
reported them, and the most recent message. The addon name links to its folder, and
`file.lua:42` opens that exact file in the panel's editor.

- **Messages** on each row opens every *distinct* message that addon produced, with counts.
  An addon failing in three different places reads as one recurring problem in the table;
  this is where that turns back into three.
- **When the errors happened** plots error volume across the length of the log (see below).
- **Errors by addon** stacks still-happening errors against historical ones, so an addon
  with 3,000 errors of which four are recent is visibly different from one with 40 that are
  all recent.
- **Download log** before **Clear log**, so a clean slate does not have to mean losing the
  evidence.

Two sources, each optional:

| Source | File | Notes |
|---|---|---|
| Server-side | `garrysmod/console.log` | Only written if `-condebug` is in your startup command |
| Client-side | `garrysmod/clientside_errors.txt` | Errors players' clients reported back |

Errors are attributed by the `[tag]` GMod prints, falling back to the folder the failing
file lives in - so `[ERROR]` lines still get billed to the right addon, and DarkRP's errors
land on `gamemodes/darkrp` rather than being mistaken for an addon.

### Still happening vs history

**These logs contain no timestamps.** Not dates, not clock times, not unix values - Garry's
Mod simply doesn't write them, so a true "last errored at 14:32" is not recoverable.

What is recoverable is *order*: the logs are append-only, so position is a real recency
signal. An addon is marked **Still happening** if it appears in the last 100 errors
(`recent_window`); otherwise it shows how many errors ago it was last seen. The file's own
mtime is the one wall-clock anchor available.

The timeline chart is the same idea taken further: the log is sliced into equal-width
buckets and error volume is plotted per bucket. The x axis is position rather than time, and
is labelled that way - but because the file only ever grows, a spike at the right-hand end
is a problem happening now, and a spike in the middle with a flat tail is something that
broke once and stopped.

This is also why the Addons page sorts by live errors before total ones.

## Workshop

Reads `WORKSHOP_ID` from the server's startup variables and lists the collection with
sizes, update dates and subscriber counts, plus three charts:

- **Largest addons** - which entries are actually big, horizontally so the names stay
  readable.
- **What the download is made of** - whether the collection is a few heavy items or a long
  tail, which is a different question and changes what you would do about it.
- **Last updated** - addons bucketed by age. A tall "this week" bar right after something
  broke overnight names the likely cause; a tall "over a year" bar is a collection quietly
  rotting.

Every row also shows its share of the total download, and anything **banned** or **not a
GMod item** is flagged - an item from another game will never mount, and Steam's own
collection page says nothing about it.

Uses Steam's `GetCollectionDetails` and `GetPublishedFileDetails`, both of which accept
unauthenticated requests. **No Steam API key is needed or asked for.** Requests are batched
100 items at a time so large collections do not silently come back empty, and responses are
cached for 15 minutes.

## Addon library

A curated set of well-known addons the panel can install for you: **Advanced Duplicator 2**,
**Wiremod**, **Improved Stacker**, **Improved Weight**, **Improved Anti-Noclip**,
**PermaProps**, **3D2D Textscreens**, **PAC3**, **ULib**, **ULX**, **Falco's Prop
Protection**, **CFC HTTP Whitelist**, **DarkRP** and **DarkRP Modification**. Presets
install a sensible group in one go, dependencies included.

### How it works

The panel cannot run shell commands, but it can fetch a URL and the daemon can write bytes
and extract an archive in place. So an install is:

1. the **panel** downloads the addon's archive from `codeload.github.com`
2. the daemon writes it into the target folder
3. the daemon extracts it
4. work out which folder that created by diffing the directory listing, and rename it
5. **read the directory back and confirm the addon is actually there**
6. delete the archive
7. record the repository, branch and commit in `addons/.gmod-toolkit-installed.json`

Each of those is a separate step with its own request, and the page shows which one it is on,
a progress bar and a running log — so a large addon is not a frozen button, and a failure
names the step it failed at.

Step 4 diffs rather than predicting GitHub's `<repo>-<branch>` naming, so a change in how
GitHub names its archives cannot leave a half-installed addon behind.

**Step 5 exists because it once went wrong.** An earlier version ran the whole sequence in
one go and trusted each daemon call's response. When an addon silently failed to appear it
still reported success and wrote a manifest entry saying it was installed — which is worse
than failing, because the record then disagrees with the disk. Nothing is believed now: if
the folder is not there when the directory is read back, the install fails, says so, and
records nothing. Where the manifest and the filesystem do disagree, the library says
**Missing** rather than quietly showing the addon as available again.

An **update** keeps the existing folder until the new one is verified in place, so a failure
leaves you with a working addon rather than none.

**Why the panel does the downloading.** The daemon has its own remote-download endpoint,
and using it would be the obvious choice. It refuses, with
`downloader: request is missing ContentLength`, because it checks a download against the
server's disk quota before writing it — and GitHub builds archives on demand and streams
them chunked, so no GitHub archive URL ever sends a `Content-Length`. No amount of
retrying fixes that, so the bytes go through the panel instead.

The cost of that is a size ceiling: the archive is briefly held in the panel's memory and
the whole download has to finish inside one PHP request. `max_download_bytes` and
`download_timeout` default to values that sit comfortably inside a stock `php.ini`
(`memory_limit 128M`, `max_execution_time 30`). The largest archive in the catalogue is
Wiremod at about 8 MB; most are under 1 MB.

That manifest is what makes the update status possible: it compares the commit each addon
was installed at against its branch head on GitHub, and the table says **Up to date**,
**Update available** or **Installed**. The lookup happens on page load, but it is cached for
hours and capped per load (`github_lookups_per_load`), because unauthenticated GitHub allows
sixty requests an hour. An addon GitHub did not answer for is reported as *Installed* —
never as *up to date*, because not having checked is not the same as knowing.
**Check for updates** asks again immediately, ignoring the cache.

### What it will not do

Every catalogue entry is a fixed `owner/repo` in config. **There is no way to type a URL
into this page**, because it writes third-party code into a running game server.

Some addons only exist on the Steam workshop. The panel cannot add an item to a Steam
collection - there is no API for it that does not involve signing in as the collection's
owner - so those entries show the workshop id and say so, rather than offering a button that
cannot work. (Improved Stacker is on the workshop *and* on GitHub, so it installs properly.)

### Removing one again

**Uninstall** deletes the folder and clears its record. It only appears when the folder is
genuinely on disk, and it removes the folder the catalogue actually found — which may be
under one of the entry's aliases, or sitting in `addons/__disabled` — rather than the name
it would have used had it installed the addon itself. As with installing, the directory is
read back afterwards and nothing is recorded until the folder is really gone.

The confirmation says what will be lost, and says it more loudly for entries that exist to
hold your own configuration: deleting `darkrpmodification` throws away your jobs and
entities, not just an addon. It also points at the reversible option — disabling on the
Addons page moves a folder into `addons/__disabled` and can be undone.

An addon whose record says installed but whose folder is gone shows as **Missing**, and gets
**Forget** instead of Uninstall. That clears the stale record and touches nothing on disk,
because a missing folder usually means you moved or deleted it yourself, and offering to
"uninstall" something that is not there would be nonsense.

### Permissions

Installing needs `file.create`, `file.archive` and `file.update` — all three, because the
install is several daemon operations and failing half way through would leave a folder full
of zip. Uninstalling additionally needs `file.delete`, which the panel treats as a separate
permission for good reason: being trusted to add files is not the same as being trusted to
remove them.

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

- **`catalogue`** / **`presets`** - what the Addon library offers. Add your own entries here.
- **`error_logs`** - which files to digest, their labels, and the hint shown when absent.
- **`darkrp_paths`** - shortcut folders; each is existence-checked before being shown.
- **`client_download_mbps`** (50) - line speed used for the "a new player waits about…"
  estimate. Set it to something like what your players actually have.
- **`cache_ttl`** (60s) - directory listing cache.
- **`workshop_cache_ttl`** (900s) - Steam response cache; Steam rate limits, so keep it generous.
- **`github_cache_ttl`** (6h) / **`github_lookups_per_load`** (12) - commit lookups for the
  update status, and how many a single page load may make.
- **`max_download_bytes`** (32 MB) / **`download_timeout`** (20s) - limits on fetching a
  catalogue addon. Both are sized for a stock `php.ini`; raise them together, and only
  alongside `memory_limit` and `max_execution_time`.
- **`digest_cache_ttl`** (1h) - parsed error digests. Keyed on each log's mtime and size, so
  an unchanged log is never re-read and a changed one is picked up at once. This is not a
  staleness window and can safely be long.
- **`recent_window`** (100) - how many trailing errors count as "still happening".
- **`timeline_buckets`** (40) - slices the log is divided into for the timeline chart.
- **`recent_update_days`** (7) - the "recently updated" window for workshop items.
- **`disk_scan_requests`** (400) / **`disk_scan_step_seconds`** (10) /
  **`disk_scan_addons`** (4) - limits on the disk usage scan: folder listings per addon,
  seconds per step, and addons per step. Raise the first if your addons nest deeply and
  scans keep coming back partial.
- **`max_log_bytes`** (16 MB) - refuse to read error logs larger than this.

---

Part of [Pelican Panel Plugins](../README.md). MIT licensed. Written with AI assistance -
see the [disclosure](../README.md#ai-disclosure) in the repository README.
