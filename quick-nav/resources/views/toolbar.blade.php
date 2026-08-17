{{-- Teleported so the menu is not clipped by the toolbar's own bounds. --}}
<x-filament::dropdown placement="bottom-start" teleport>
    <x-slot name="trigger">
        <x-filament::icon-button
            icon="tabler-star"
            color="warning"
            label="Quick Access"
            tooltip="Quick Access"
        />
    </x-slot>

    <x-filament::dropdown.list>
        @foreach ($shortcuts as $shortcut)
            <x-filament::dropdown.list.item
                tag="a"
                :href="$shortcut['url']"
                :icon="$shortcut['icon']"
            >
                {{ $shortcut['label'] }}
            </x-filament::dropdown.list.item>
        @endforeach
    </x-filament::dropdown.list>
</x-filament::dropdown>
