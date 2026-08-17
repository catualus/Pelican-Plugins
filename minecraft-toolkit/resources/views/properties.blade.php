<x-filament-panels::page>
    @if ($fields === [])
        <x-filament::section>
            <x-slot name="heading">server.properties not found</x-slot>
            <p class="fi-text-sm">
                The server has not generated it yet. Start the server once, accept the EULA,
                and it will appear here.
            </p>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Settings</x-slot>
            <x-slot name="description">
                Comments, ordering and escaping in the file are preserved - only the
                values you change are rewritten. A restart is needed for changes to apply.
            </x-slot>

            <x-slot name="headerEnd">
                <x-filament::input.wrapper prefix-icon="tabler-search" style="max-width: 16rem;">
                    <x-filament::input type="search" wire:model.live.debounce.200ms="search" placeholder="Filter settings" />
                </x-filament::input.wrapper>
            </x-slot>

            <div style="display: grid; gap: 0.75rem;">
                @foreach ($this->visibleFields() as $field)
                    <div style="display: grid; grid-template-columns: minmax(12rem, 20rem) 1fr; gap: 1rem; align-items: start;">
                        <div>
                            <code class="fi-text-sm">{{ $field['key'] }}</code>
                            @if ($field['help'])
                                <p class="fi-text-xs fi-opacity-70" style="margin-top: 0.125rem;">{{ $field['help'] }}</p>
                            @endif
                        </div>

                        <div>
                            @switch ($field['type'])
                                @case ('boolean')
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select wire:model="values.{{ $field['key'] }}" :disabled="! $writable">
                                            <option value="1">true</option>
                                            <option value="0">false</option>
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                    @break

                                @case ('enum')
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select wire:model="values.{{ $field['key'] }}" :disabled="! $writable">
                                            @foreach ($field['options'] as $option)
                                                <option value="{{ $option }}">{{ $option }}</option>
                                            @endforeach
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                    @break

                                @case ('integer')
                                    <x-filament::input.wrapper>
                                        <x-filament::input type="number" wire:model="values.{{ $field['key'] }}" :disabled="! $writable" />
                                    </x-filament::input.wrapper>
                                    @break

                                @case ('secret')
                                    <x-filament::input.wrapper>
                                        <x-filament::input type="password" wire:model="values.{{ $field['key'] }}" :disabled="! $writable" />
                                    </x-filament::input.wrapper>
                                    @break

                                @default
                                    <x-filament::input.wrapper>
                                        <x-filament::input type="text" wire:model="values.{{ $field['key'] }}" :disabled="! $writable" />
                                    </x-filament::input.wrapper>
                            @endswitch
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($writable)
                <x-slot name="footerActions">
                    <x-filament::button wire:click="save" wire:loading.attr="disabled" icon="tabler-device-floppy">
                        Save changes
                    </x-filament::button>
                </x-slot>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
