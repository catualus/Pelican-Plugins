# Pelican Panel Plugins

Four plugins for [Pelican Panel](https://pelican.dev) that cut out the repetitive clicking
in day-to-day server administration.

Nothing here replaces the panel's own tools - each plugin adds the one screen you wish
existed for a particular kind of server, and stays out of the way on every other kind.

| Plugin | What it adds | Works on |
|---|---|---|
| **[Quick Nav](quick-nav)** | Jump straight to the folders that matter, from the file manager toolbar | Every server |
| **[GMod Toolkit](gmod-toolkit)** | Overview, workshop charts, addon browser, Lua error digest, one-click addon installer | Garry's Mod |
| **[Minecraft Toolkit](minecraft-toolkit)** | `server.properties` as a grouped form, player lists, mod/plugin toggles, crash report summaries | Minecraft |
| **[App Toolkit](app-toolkit)** | Git redeploy with the running commit, `.env` editor, dependency list | Node.js & Python apps |

Every list is a real Filament table, so sorting, searching, filtering, pagination and bulk
actions come from the panel rather than being reimplemented badly. The charts are the
panel's own Chart.js widgets and follow your theme in both light and dark mode.

---

## Quick Nav

Adds a **Quick Access** button to the file manager toolbar that drops down a list of the
folders you actually use on that server.

It works out what kind of server it is and offers the right shortcuts - `addons`,
`gamemodes` and `maps` on a Garry's Mod server; `mods`, `config` and `kubejs` on a modded
Minecraft server; `plugins`, `world` and `logs` on a Paper one. Shortcuts are checked
against the real filesystem first, so you never see a link to a folder that isn't there.

Underneath those, it remembers the folders you keep coming back to: **pin** the folder
you're in, and reopen **recent** ones. Both live in your browser rather than on the panel -
see the plugin's README for why.

## GMod Toolkit

Five pages, shown only on Garry's Mod servers.

**Overview** answers "is this server all right" without visiting the other four: what the
workshop collection costs a joining player as both a size and a wait, how many addons are
erroring right now and which, how much disk the addon folder uses, and callouts for
collection items that are banned or aren't Garry's Mod items at all.

**Addons** lists everything in `garrysmod/addons`, sorted by what is currently throwing
errors rather than alphabetically - with 50+ addons installed, that difference matters.
Enable and disable moves folders in and out of `addons/__disabled`, which is what actually
stops Garry's Mod loading them, and works on a whole selection at once. An opt-in scan
measures what each addon uses on disk and charts it.

**Lua errors** turns the error logs into a table grouped by addon, with charts for which
addons are failing and *when* they failed. Because these logs contain no timestamps,
recency is measured by position in the file - so an addon is **still happening** if it
appears near the end, and the timeline plots error volume across the log's length, where a
spike on the right is a problem happening now. Each row opens every distinct message that
addon produced, and a `file.lua:42` opens that file in the editor.

**Workshop** lists your collection with sizes, update dates and subscriber counts, plus
charts for which addons are largest, what the download is made of, and how stale the
collection has become. No Steam API key needed.

**Addon library** installs a curated set - AdvDupe2, Wiremod, Improved Stacker, ULX, PAC3,
DarkRP and more - by having the daemon fetch and extract them from GitHub, recording the
exact commit so it can tell you later what has moved on. Presets install a sensible group
in one go. The catalogue is fixed in config and there is no way to type a URL into the
page, because this writes third-party code onto a running game server.

## Minecraft Toolkit

Three pages, on both modded and Bukkit-family servers.

**Properties** renders `server.properties` as a proper form - checkboxes, dropdowns and
number fields instead of a text file - grouped into tabs rather than one flat list of sixty
settings. Changed fields are marked, and saving names exactly what it will rewrite. Saving
is careful: comments, ordering and Java's value escaping are preserved, and only the values
you actually changed are rewritten.

**Players** edits `ops.json`, `whitelist.json` and the ban lists as tables with search and
bulk removal. Adding someone looks their UUID up from Mojang first, because Minecraft
matches these files on UUID and quietly ignores an entry that only has a name.

**Mods / Plugins** lists what's installed with search and sizes, and toggles anything on or
off - one at a time or in bulk - by renaming between `.jar` and `.jar.disabled`. This works
on everything on disk, including mods installed from CurseForge. Crash reports are read
rather than listed: each is summarised down to its description, its exception, and the
first stack frame that isn't Minecraft or the mod loader, which is usually the culprit.

## App Toolkit

One page for plain Node.js and Python servers - bots, backends, workers.

**Deploy** shows the repository, branch and auto-update state, *and which commit is
actually running*, read out of `.git` on disk. It is explicit about what the button does:
the panel can't run shell commands, so redeploying restarts the server and lets the startup
command pull - which it only does if Auto Update is on. If it's off, the button says
**Restart** and warns that nothing will be pulled.

**Environment** edits `.env` with likely-secret values hidden behind a reveal toggle,
a copy button on every value, and the ability to add new variables. Comments and quoting
survive a save untouched.

**Dependencies** shows `package.json` or `requirements.txt` read-only with a filter box, so
you can see what's pinned without opening an editor.

---

## Installing

Each plugin installs on its own - take only the ones you want.

1. Download the plugin folder and compress it to a `.zip`.
   The zip must contain the folder itself, and **the folder name must match the plugin's
   `id`** in `plugin.json` (e.g. `gmod-toolkit/`). Pelican resolves plugin paths by id.
2. In the panel, go to **Admin -> Plugins -> Import** and upload the zip.
3. Enable it.

On Windows you can build all four at once with the included script, which writes correctly
named zips to `dist/`:

```bash
pwsh ./build.ps1
```

### Requirements

- Pelican Panel (developed against `1.0.0-beta38`, which ships Filament 5 and Livewire 4;
  Pelican is still in beta and its plugin API can move between versions)
- No Composer packages, no API keys, no database migrations

### Permissions

Everything respects the panel's existing subuser permissions. Read-only views need
`file.read`; anything that writes needs `file.update`; the restart buttons need
`control.restart`; installing a GMod addon needs `file.create`, `file.archive` and
`file.update` together. Controls you don't have permission for aren't shown rather than
failing when clicked.

## Configuration

Every plugin works with no configuration. Each has a `config/` file if you want to change
something - which folders Quick Nav offers, which `.env` keys count as secrets, which
addons the GMod library installs, how long results are cached, and so on. The options are
commented in place.

## Network requests

These plugins make no network requests except the following, all of which are documented
where they happen and none of which need an account or a key:

| To | Why | Where |
|---|---|---|
| `api.steampowered.com` | workshop collection contents and sizes | GMod Toolkit |
| `codeload.github.com` | downloading a catalogue addon | GMod Toolkit |
| `api.github.com` | the commit a catalogue addon's branch is on | GMod Toolkit |
| `api.minecraftservices.com`, `api.mojang.com` | resolving a username to a UUID | Minecraft Toolkit |
| `crafthead.net` | player head images, requested **by your browser** | Minecraft Toolkit (optional, `player_avatars`) |

The last one is the only one that sends anything about your players anywhere, and it can be
switched off in config.

## Related plugins

These deliberately don't duplicate what already exists on the
[Pelican plugin hub](https://hub.pelican.dev/plugins):

- **minecraft-modrinth** installs mods and plugins from Modrinth. Minecraft Toolkit manages
  what's already on disk, wherever it came from.
- **mclogs-uploader** shares logs to mclo.gs. Minecraft Toolkit only points you at crash
  reports.

Both are worth having alongside these.

## Tests

`tests/` covers the three parsers that have no panel dependencies, which are also the three
places where a bug would quietly corrupt one of your files:

- `LuaErrorParser` - the log digest, its per-addon attribution and its position buckets
- `DotEnvFile` - that everything you didn't edit comes back byte for byte
- `PropertiesFile` - the same promise, plus Java's escaping

There is no `composer.json` - Pelican loads these through its own autoloader, and a vendor
directory would end up inside every distributed zip - so the tests are wired up by
`tests/bootstrap.php` and run with a standalone PHPUnit:

```bash
phpunit
```

CI runs the same suite, along with a syntax check over every PHP and Blade file and a check
that each class's namespace matches its directory.

## AI disclosure

These plugins were written with substantial help from Claude, Anthropic's AI assistant,
working from the Pelican Panel source and from real server data. They were then installed
and used on a live panel before being published here.

Being specific about what that means:

- The parsing logic - the log parser, the `server.properties` and `.env` editors - is
  covered by the tests above, written against real file shapes rather than invented ones.
- Server-type detection, the daemon-backed services and the addon installer are **not**
  unit tested; they need the panel and a running daemon. They were exercised by hand
  against a real server.
- The user interface was checked by hand in a browser, in both light and dark mode. There
  are no automated UI tests.
- Where a decision depended on how something actually behaves (log formats, file layouts,
  what a panel API really returns, which Filament components a given version ships),
  it was verified against the real thing rather than assumed. Several designs changed once
  the real data disagreed with the plan.

If you'd rather not run AI-assisted code on your panel, that's fair. The source is small and
plain - ordinary PHP and Blade, no code generation at runtime, and the only network calls
are the ones listed above.

## Contributing

Issues and pull requests are welcome. If you're reporting a bug, the panel version, the egg
in use, and the exact error text are the three things that make it quickly fixable.

Three conventions worth knowing before changing anything:

- **File edits preserve what they don't touch.** The properties and `.env` editors keep the
  original line for every key you didn't change. Please don't replace them with
  parse-and-rewrite; it silently mangles comments and escaping.
- **Nothing assumes a server type from its name.** Detection reads egg tags, then egg
  features, then the Docker image, then probes the filesystem - in that order.
- **Never put a Blade `@directive` inside a component's attribute.** Blade compiles `{{ }}`
  echoes there, but not directives — so
  `<x-filament::button wire:click="go(@js($id))">` ships the literal text `@js($id)` to the
  browser. Livewire cannot parse the call, and if it lands in an Alpine attribute the
  resulting SyntaxError aborts Alpine's initialisation for that whole subtree, killing every
  control near it. It raises no PHP error, no Blade error and no test failure, and it is
  nearly invisible in review. Use `{{ \Illuminate\Support\Js::from($x) }}`, which compiles
  to exactly what `@js()` would have. `tools/check-structure.php` fails the build on this.
- **Every `@foreach` that renders a control needs a `wire:key`.** Without one, Livewire's
  morph matches structurally identical rows by position and can keep the wrong element
  across a re-render.

## Licence

[MIT](LICENSE) - do what you like with it.
