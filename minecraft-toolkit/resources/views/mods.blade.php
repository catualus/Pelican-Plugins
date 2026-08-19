<x-filament-panels::page>
    @if ($crashes !== [])
        @php $latest = $crashes[0]; @endphp

        <x-filament::callout color="danger" icon="tabler-alert-triangle">
            <x-slot name="heading">
                {{ count($crashes) }} recent crash {{ Str::plural('report', count($crashes)) }}
            </x-slot>

            @if ($latest['culprit'])
                The newest one points at <code>{{ $latest['culprit'] }}</code>.
            @elseif ($latest['exception'])
                The newest one is <code>{{ Str::limit($latest['exception'], 140) }}</code>.
            @else
                Open them to see what failed.
            @endif
            Use <strong>Crash reports</strong> above to read them, then disable the culprit below.
        </x-filament::callout>
    @endif

    @if ($pendingRestart)
        <x-filament::callout color="warning" icon="tabler-refresh">
            <x-slot name="heading">Changes are on disk but not in effect</x-slot>

            Enabling and disabling only renames files. The server reads this directory at
            startup, so nothing you just changed applies until it restarts.
        </x-filament::callout>
    @endif

    <x-filament::section>
        <x-slot name="heading">{{ $this->getTitle() }}</x-slot>

        <x-slot name="description">
            Disabling renames a file to <code>.jar.disabled</code>, which is what stops the
            server loading it - so this works for everything on disk, including mods
            installed from CurseForge. Nothing is ever deleted.
        </x-slot>

        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
