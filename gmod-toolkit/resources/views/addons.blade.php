<x-filament-panels::page>
    @if ($darkrp !== [])
        <x-filament::section>
            <x-slot name="heading">DarkRP</x-slot>
            <x-slot name="description">Jump straight into the config folders.</x-slot>

            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                @foreach ($darkrp as $shortcut)
                    <x-filament::button
                        tag="a"
                        :href="$this->fileUrl($shortcut['path'])"
                        color="gray"
                        icon="tabler-folder"
                        size="sm"
                    >{{ $shortcut['label'] }}</x-filament::button>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Addons</x-slot>

        <x-slot name="description">
            Sorted by what is erroring <em>now</em>, then by worst overall. A red count is
            still happening; a grey one has not recurred recently. Disabling moves the folder
            into <code>addons/__disabled</code>; Garry's Mod loads every direct child of
            <code>addons</code>, so this is what actually unloads it.
        </x-slot>

        @if ($addons === [])
            <p class="fi-text-sm">
                No addons found. Either <code>garrysmod/addons</code> is empty, or the server
                is installing and its files are not readable yet.
            </p>
        @else
            <div class="fi-ta-ctn" style="overflow-x: auto;">
                <table class="fi-ta-table" style="width: 100%; text-align: start;">
                    <thead>
                        <tr>
                            <th style="text-align: start; padding: 0.5rem 0.75rem;">Addon</th>
                            <th style="text-align: end; padding: 0.5rem 0.75rem;">Errors</th>
                            <th style="text-align: start; padding: 0.5rem 0.75rem;">Modified</th>
                            <th style="text-align: end; padding: 0.5rem 0.75rem;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($addons as $addon)
                            <tr>
                                <td style="padding: 0.5rem 0.75rem;">
                                    <x-filament::link :href="$this->fileUrl($addon['path'])">
                                        {{ $addon['name'] }}
                                    </x-filament::link>

                                    @unless ($addon['enabled'])
                                        <x-filament::badge color="gray">Disabled</x-filament::badge>
                                    @endunless
                                </td>
                                <td style="padding: 0.5rem 0.75rem; text-align: end; white-space: nowrap;">
                                    @if ($addon['errors'] > 0)
                                        <x-filament::badge :color="$addon['recent'] > 0 ? 'danger' : 'gray'">
                                            {{ number_format($addon['errors']) }}
                                        </x-filament::badge>
                                        @if ($addon['recent'] > 0)
                                            <x-filament::badge color="warning">Active</x-filament::badge>
                                        @endif
                                    @else
                                        <span class="fi-text-sm">-</span>
                                    @endif
                                </td>
                                <td style="padding: 0.5rem 0.75rem; white-space: nowrap;">
                                    {{ $addon['modified']?->diffForHumans() ?? '-' }}
                                </td>
                                <td style="padding: 0.5rem 0.75rem; text-align: end;">
                                    @if ($writable)
                                        <x-filament::button
                                            size="xs"
                                            :color="$addon['enabled'] ? 'gray' : 'primary'"
                                            wire:click="toggle(@js($addon['name']), {{ $addon['enabled'] ? 'false' : 'true' }})"
                                            wire:loading.attr="disabled"
                                        >{{ $addon['enabled'] ? 'Disable' : 'Enable' }}</x-filament::button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
