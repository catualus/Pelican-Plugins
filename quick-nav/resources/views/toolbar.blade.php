{{--
    Pins and recent folders live in the browser's localStorage, not on the server.

    That is deliberate rather than a shortcut: they are a per-person, per-browser
    convenience; the panel offers plugins no durable per-user storage without a database
    migration; and putting them in the application cache would mean they quietly vanish
    whenever it is cleared. localStorage is honest about what it is, costs the panel
    nothing, and keeps one subuser's pins out of another's dropdown.

    Each slot below is a real Filament dropdown item that is shown or hidden rather than
    markup built in an x-for. Hidden anchors cost nothing, and it means every item carries
    the component's own classes and colours instead of a hand-copied approximation of them
    that would drift the first time Filament changed.

    If localStorage is unavailable the configured shortcuts still work and these sections
    simply never appear.
--}}
<div
    x-data="{
        key: @js($storageKey),
        base: @js($base),
        limit: @js($historyLimit),
        pinned: [],
        recent: [],

        init() {
            this.load();
            this.remember();
        },

        load() {
            try {
                const saved = JSON.parse(window.localStorage.getItem(this.key) || '{}');
                this.pinned = Array.isArray(saved.pinned) ? saved.pinned.slice(0, this.limit) : [];
                this.recent = Array.isArray(saved.recent) ? saved.recent.slice(0, this.limit) : [];
            } catch (e) {
                this.pinned = [];
                this.recent = [];
            }
        },

        save() {
            try {
                window.localStorage.setItem(this.key, JSON.stringify({ pinned: this.pinned, recent: this.recent }));
            } catch (e) {
                // A full or disabled localStorage is not worth breaking the toolbar over.
            }
        },

        /* The file list's path is a route segment, not a query parameter, so the current
           folder is whatever follows the resource's own URL. */
        current() {
            const here = decodeURIComponent(window.location.pathname);
            const root = decodeURIComponent(new URL(this.base, window.location.origin).pathname);

            return here.startsWith(root) ? here.slice(root.length).replace(/^\//, '') : '';
        },

        /* Matches the panel's own encode_path(): rawurlencode per segment. */
        url(path) {
            return this.base + '/' + path.split('/').map(encodeURIComponent).join('/');
        },

        remember() {
            const path = this.current();

            if (! path) {
                return;
            }

            this.recent = [path, ...this.recent.filter((p) => p !== path)].slice(0, this.limit);
            this.save();
        },

        /* The folder you are standing in is not a useful thing to offer to navigate to. */
        history() {
            return this.recent.filter((p) => p !== this.current());
        },

        isPinned(path) {
            return this.pinned.includes(path);
        },

        togglePin(path) {
            if (! path) {
                return;
            }

            this.pinned = this.isPinned(path)
                ? this.pinned.filter((p) => p !== path)
                : [path, ...this.pinned].slice(0, this.limit);

            this.save();
        },

        forget() {
            this.recent = [];
            this.save();
        },

        name(path) {
            const parts = path.split('/');

            return parts[parts.length - 1] || path;
        },
    }"
>
    {{-- Teleported so the menu is not clipped by the toolbar's own bounds. --}}
    <x-filament::dropdown placement="bottom-start" teleport width="xs">
        <x-slot name="trigger">
            <x-filament::icon-button
                icon="tabler-star"
                color="warning"
                label="Quick Access"
                tooltip="Quick Access"
            />
        </x-slot>

        {{-- Pinned ------------------------------------------------------------- --}}
        <x-filament::dropdown.header icon="tabler-pin" x-show="pinned.length" x-cloak>
            Pinned
        </x-filament::dropdown.header>

        <x-filament::dropdown.list x-show="pinned.length" x-cloak>
            @for ($i = 0; $i < $historyLimit; $i++)
                <x-filament::dropdown.list.item
                    tag="a"
                    href="#"
                    icon="tabler-pin"
                    x-show="pinned[{{ $i }}]"
                    x-bind:href="pinned[{{ $i }}] ? url(pinned[{{ $i }}]) : '#'"
                    x-bind:title="pinned[{{ $i }}]"
                >
                    <span x-text="pinned[{{ $i }}] ? name(pinned[{{ $i }}]) : ''"></span>
                </x-filament::dropdown.list.item>
            @endfor
        </x-filament::dropdown.list>

        {{-- The configured, existence-checked shortcuts for this kind of server. --}}
        @if ($shortcuts !== [])
            <x-filament::dropdown.header icon="tabler-folders">Shortcuts</x-filament::dropdown.header>

            <x-filament::dropdown.list>
                @foreach ($shortcuts as $shortcut)
                    <x-filament::dropdown.list.item
                        wire:key="qn-{{ md5($shortcut['path']) }}"
                        tag="a"
                        :href="$shortcut['url']"
                        :icon="$shortcut['icon']"
                    >
                        {{ $shortcut['label'] }}
                    </x-filament::dropdown.list.item>
                @endforeach
            </x-filament::dropdown.list>
        @endif

        {{-- Recently visited, newest first ------------------------------------- --}}
        <x-filament::dropdown.header icon="tabler-history" x-show="history().length" x-cloak>
            Recent
        </x-filament::dropdown.header>

        <x-filament::dropdown.list x-show="history().length" x-cloak>
            @for ($i = 0; $i < $historyLimit; $i++)
                <x-filament::dropdown.list.item
                    tag="a"
                    href="#"
                    icon="tabler-folder"
                    x-show="history()[{{ $i }}]"
                    x-bind:href="history()[{{ $i }}] ? url(history()[{{ $i }}]) : '#'"
                    x-bind:title="history()[{{ $i }}]"
                >
                    <span x-text="history()[{{ $i }}] || ''"></span>
                </x-filament::dropdown.list.item>
            @endfor

            <x-filament::dropdown.list.item icon="tabler-eraser" x-on:click="forget()">
                Clear history
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>

        {{-- Pin or unpin whatever folder is open right now. --------------------- --}}
        <x-filament::dropdown.list x-show="current()" x-cloak>
            <x-filament::dropdown.list.item icon="tabler-pin" x-on:click="togglePin(current())">
                <span x-text="(isPinned(current()) ? 'Unpin ' : 'Pin ') + name(current())"></span>
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</div>
