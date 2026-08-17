# Pelican Panel Plugins

Four plugins for [Pelican Panel](https://pelican.dev) that cut out the repetitive clicking
in day-to-day server administration.

Nothing here replaces the panel's own tools - each plugin adds the one screen you wish
existed for a particular kind of server, and stays out of the way on every other kind.

| Plugin | What it adds | Works on |
|---|---|---|
| **[Quick Nav](quick-nav)** | Jump straight to the folders that matter, from the file manager toolbar | Every server |
| **[GMod Toolkit](gmod-toolkit)** | Addon browser, Lua error digest, workshop collection viewer | Garry's Mod |
| **[Minecraft Toolkit](minecraft-toolkit)** | `server.properties` as a form, player lists, mod/plugin toggles | Minecraft |
| **[App Toolkit](app-toolkit)** | Git redeploy, `.env` editor, dependency list | Node.js & Python apps |

---

## Quick Nav

Adds a **Quick Access** button to the file manager toolbar that drops down a list of the
folders you actually use on that server.

It works out what kind of server it is and offers the right shortcuts - `addons`,
`gamemodes` and `maps` on a Garry's Mod server; `mods`, `config` and `kubejs` on a modded
Minecraft server; `plugins`, `world` and `logs` on a Paper one. Shortcuts are checked
against the real filesystem first, so you never see a link to a folder that isn't there.

One button, no configuration, and it disappears on servers it has nothing useful to say
about.

## GMod Toolkit

Three pages, shown only on Garry's Mod servers.

**Addons** lists everything in `garrysmod/addons`, sorted by what is currently throwing
errors rather than alphabetically - with 50+ addons installed, that difference matters.
Enable and disable moves folders in and out of `addons/__disabled`, which is what actually
stops Garry's Mod loading them.

**Lua errors** turns the error logs into a table grouped by addon: how many errors, how many
players hit them, and the most recent message. Because these logs contain no timestamps,
recency is measured by position in the file - an addon is marked **Active** if it appears
near the end, and otherwise shows how many errors ago it was last seen. Clicking a
`file.lua:42` opens that file in the editor. A **Clear log** button gives you a clean slate
when the history stops being useful.

**Workshop** lists your collection with sizes, update dates and subscriber counts, flagging
anything updated in the last week - usually the explanation for something that broke
overnight. No Steam API key needed.

## Minecraft Toolkit

Three pages, on both modded and Bukkit-family servers.

**Properties** renders `server.properties` as a proper form - toggles, dropdowns and number
fields instead of a text file. Saving is careful: comments, ordering and Java's value
escaping are preserved, and only the values you actually changed are rewritten.

**Players** edits `ops.json`, `whitelist.json` and the ban lists as tables. Adding someone
looks their UUID up from Mojang first, because Minecraft matches these files on UUID and
quietly ignores an entry that only has a name.

**Mods / Plugins** lists what's installed with a filter box, and toggles anything on or off
by renaming between `.jar` and `.jar.disabled`. This works on everything on disk, including
mods installed from CurseForge. Recent crash reports sit at the top, since the usual job is
read the crash, find the culprit, disable it.

## App Toolkit

One page for plain Node.js and Python servers - bots, backends, workers.

**Deploy** shows the repository, branch and auto-update state, with a restart button. It is
explicit about what that button does: the panel can't run shell commands, so redeploying
restarts the server and lets the startup command pull - which it only does if Auto Update is
on. If it's off, the button says **Restart** and warns that nothing will be pulled.

**Environment** edits `.env` with likely-secret values hidden behind a reveal toggle.
Comments and quoting survive a save untouched.

**Dependencies** shows `package.json` or `requirements.txt` read-only, so you can see what's
pinned without opening an editor.

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

- Pelican Panel (tested on the current release; Pelican is still in beta and its plugin API
  can move between versions)
- No Composer packages, no API keys, no database migrations

### Permissions

Everything respects the panel's existing subuser permissions. Read-only views need
`file.read`; anything that writes needs `file.update`; the restart buttons need
`control.restart`. Controls you don't have permission for aren't shown rather than failing
when clicked.

## Configuration

Every plugin works with no configuration. Each has a `config/` file if you want to change
something - which folders Quick Nav offers, which `.env` keys count as secrets, how long
results are cached, and so on. The options are commented in place.

## Related plugins

These deliberately don't duplicate what already exists on the
[Pelican plugin hub](https://hub.pelican.dev/plugins):

- **minecraft-modrinth** installs mods and plugins from Modrinth. Minecraft Toolkit manages
  what's already on disk, wherever it came from.
- **mclogs-uploader** shares logs to mclo.gs. Minecraft Toolkit only points you at crash
  reports.

Both are worth having alongside these.

## AI disclosure

These plugins were written with substantial help from Claude, Anthropic's AI assistant,
working from the Pelican Panel source and from real server data. They were then installed
and used on a live panel before being published here.

Being specific about what that means:

- The parsing and service logic - log parsers, the `server.properties` and `.env` editors,
  server-type detection - is covered by tests written against real files, not invented ones.
- The user interface was checked by hand in a browser. There are no automated UI tests.
- Where a decision depended on how something actually behaves (log formats, file layouts,
  what a panel API really returns), it was verified against the real thing rather than
  assumed. Several designs changed once the real data disagreed with the plan.

If you'd rather not run AI-assisted code on your panel, that's fair. The source is small and
plain - ordinary PHP and Blade, no code generation at runtime, no network calls except the
two documented ones (Steam's public workshop endpoints and Mojang's username lookup).

## Contributing

Issues and pull requests are welcome. If you're reporting a bug, the panel version, the egg
in use, and the exact error text are the three things that make it quickly fixable.

Two conventions worth knowing before changing anything:

- **File edits preserve what they don't touch.** The properties and `.env` editors keep the
  original line for every key you didn't change. Please don't replace them with
  parse-and-rewrite; it silently mangles comments and escaping.
- **Nothing assumes a server type from its name.** Detection reads egg tags, then egg
  features, then the Docker image, then probes the filesystem - in that order.

## Licence

[MIT](LICENSE) - do what you like with it.
