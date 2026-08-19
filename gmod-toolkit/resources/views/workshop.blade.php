<x-filament-panels::page>
    @if ($collectionId !== null && $summary['foreign'] > 0)
        <x-filament::callout color="danger" icon="tabler-alert-triangle">
            <x-slot name="heading">
                {{ $summary['foreign'] }} {{ Str::plural('item', $summary['foreign']) }} in this collection
                {{ $summary['foreign'] === 1 ? 'is' : 'are' }} not from Garry's Mod
            </x-slot>

            They will never mount on this server, and the Steam collection page gives no hint
            of it. Filter the table by <strong>Banned or wrong game</strong> to see which.
        </x-filament::callout>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
