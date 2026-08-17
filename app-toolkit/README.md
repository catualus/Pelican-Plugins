# App Toolkit

Tools for plain Node.js and Python application servers - bots, backends, workers. Adds a
single **App** page, shown only on those servers and only to users who can read files.

Game servers are explicitly excluded: an egg tagged `minecraft`, `source`, `gmod` or
`steam` never counts as an app server, even when it ships a runtime.

## Deploy

Shows the repository, branch and Auto Update state read from the server's startup
variables, plus whether `.git` actually exists on disk yet.

**About the button.** The panel has no arbitrary shell exec, so a plugin cannot run
`git pull` directly. What it can do is restart the server - and the stock Node and Python
eggs run their pull as part of the startup command, *but only when `AUTO_UPDATE` is `1`*.

So the button is honest about which one you're getting:

| Auto Update | Button | Confirm text |
|---|---|---|
| On | **Pull & restart** | plain restart confirm |
| Off | **Restart** | warns it will **not** pull the latest commit |

Requires `control.restart`.

## Environment

`.env` as an editable list, with values whose keys look secret (`TOKEN`, `SECRET`, `KEY`,
`PASSWORD`, ...) hidden behind a per-row reveal toggle.

**The hiding is cover against shoulder-surfing and screenshots, not a security boundary.**
Anyone who can open this page already has `file.read` and could open the raw file in the
file manager. It is not a permission gate and is not presented as one.

Saving preserves comments, ordering, blank lines, `export` prefixes and existing quote
style - the original line is kept verbatim for every key you didn't touch, and only changed
values are re-serialised. Values that need quoting to survive a round trip get it
automatically. Requires `file.update`.

## Dependencies

Read-only. `package.json` dependencies / devDependencies / peerDependencies when present,
otherwise `requirements.txt`. Pip comment lines, `-r` includes and environment markers
(`; python_version >= '3.8'`) are handled; unpinned packages show as `any`.

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
