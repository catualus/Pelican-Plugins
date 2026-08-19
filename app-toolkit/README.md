# App Toolkit

Tools for plain Node.js and Python application servers - bots, backends, workers. Adds a
single **App** page, shown only on those servers and only to users who can read files.

Game servers are explicitly excluded: an egg tagged `minecraft`, `source`, `gmod` or
`steam` never counts as an app server, even when it ships a runtime.

## Deploy

Shows the repository, branch and Auto Update state read from the server's startup
variables - and, separately, **which commit is actually running**, read straight out of
`.git` on disk. The startup variables tell you what *should* be deployed; the commit tells
you what is. Both a detached HEAD and a packed ref are handled.

**About the button.** The panel has no arbitrary shell exec, so a plugin cannot run
`git pull` directly. What it can do is restart the server - and the stock Node and Python
eggs run their pull as part of the startup command, *but only when `AUTO_UPDATE` is on*.

So the button is honest about which one you're getting:

| Auto Update | Button | Confirmation |
|---|---|---|
| On | **Pull & restart** | says the startup command will pull first |
| Off | **Restart** | says plainly it will **not** pull |

"On" means any of `1`, `true`, `yes`, `on` or `enabled`, because forks and hand-edited
variables use all of them and reading one of those as off would make the page warn about
something that is not going to happen.

Requires `control.restart`.

## Environment

`.env` as an editable list, with values whose keys look secret (`TOKEN`, `SECRET`, `KEY`,
`PASSWORD`, ...) hidden by default.

Revealing is **entirely client-side** - it flips the input in the browser and tells the
server nothing, which is both instant and the reason it works at all. There is
**Reveal all**, a per-row toggle, and a copy button on every value.

The 1.0 version of this silently did nothing, and the reason is worth recording: it used
`wire:click="reveal(@js($key))"` on a Blade *component*. Blade compiles `{{ }}` echoes
inside a component's attributes but **not** directives, so the browser received the literal
text `@js($key)` and Livewire could not parse the call. The same mistake in an Alpine
attribute is worse — the SyntaxError aborts Alpine's initialisation for the whole subtree,
which is how one bad attribute takes every control on a page down with it. The build now
fails on it.

**The hiding is cover against shoulder-surfing and screenshots, not a security boundary.**
Anyone who can open this page already has `file.read` and could open the raw file in the
file manager. It is not a permission gate and is not presented as one.

Edited values are marked as changed, and **Save .env** names exactly which variables it is
about to rewrite before doing it. Saving preserves comments, ordering, blank lines, `export`
prefixes and existing quote style - the original line is kept verbatim for every key you
didn't touch, and only changed values are re-serialised. Values that need quoting to survive
a round trip get it automatically. That behaviour is covered by tests.

**Add variable** appends a new line rather than rewriting the file, so everything already
in it keeps its exact bytes — including its line endings and any blank lines at the end.
A CRLF `.env` stays CRLF, and a file with no trailing newline does not acquire one.
Requires `file.update`.

## Dependencies

Read-only, with a filter box. `package.json` dependencies / devDependencies /
peerDependencies when present, otherwise `requirements.txt`. Pip comment lines, `-r`
includes and environment markers (`; python_version >= '3.8'`) are handled; unpinned
packages show as `any`.

Deliberately no "outdated" flagging - that would mean a registry request per package on
every page load, for information `npm outdated` gives you better.

## Install

1. Zip the `app-toolkit` folder.
2. Panel -> Admin -> Plugins -> Import -> upload the zip, then enable it.

## Configuration

`config/app-toolkit.php`:

- **`secret_patterns`** - substrings that mark a `.env` key as hidden by default.
- **`requirements_files`** - Python dependency files, checked in order.
- **`cache_ttl`** (30s) - directory listing cache.
- **`max_read_bytes`** (2 MB) - refuse to read files larger than this.

---

Part of [Pelican Panel Plugins](../README.md). MIT licensed. Written with AI assistance -
see the [disclosure](../README.md#ai-disclosure) in the repository README.
