<x-filament-panels::page>
    @php
        $missing = collect($sources)->filter(fn (array $source): bool => ! $source['present']);
    @endphp

    @foreach ($missing as $source)
        <x-filament::callout
            color="gray"
            icon="tabler-file-off"
            wire:key="missing-{{ $source['key'] }}"
        >
            <x-slot name="heading">{{ $source['label'] }} log is not present</x-slot>

            <code>{{ $source['path'] }}</code>@if ($source['hint']) — {{ $source['hint'] }}@endif
        </x-filament::callout>
    @endforeach

    {{ $this->table }}

    <p class="fi-text-xs fi-opacity-70">
        These logs carry no timestamps, so recency is measured by position in the file.
        <strong>Still happening</strong> means the addon appears in the last
        {{ number_format((int) config('gmod-toolkit.recent_window', 100)) }} errors; everything
        else is history that has not recurred since.
    </p>
</x-filament-panels::page>
