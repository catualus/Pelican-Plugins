<x-filament-panels::page>
    @if ($fields === [])
        <x-filament::section>
            <x-slot name="heading">server.properties not found</x-slot>

            <x-filament::empty-state
                heading="The server has not generated it yet"
                description="Start the server once and accept the EULA. The file appears on first boot, and this page will read it."
                icon="tabler-file-off"
                icon-color="gray"
                :contained="false"
                compact
            />
        </x-filament::section>
    @else
        @php $dirty = $this->dirtyKeys(); @endphp

        @if ($dirty !== [])
            <x-filament::callout color="warning" icon="tabler-pencil">
                <x-slot name="heading">
                    {{ count($dirty) }} unsaved {{ Str::plural('change', count($dirty)) }}
                </x-slot>

                {{ implode(', ', $dirty) }} — nothing is written until you save.
            </x-filament::callout>
        @endif

        <x-filament::section>
            <x-slot name="heading">Settings</x-slot>
            <x-slot name="description">
                Only the values you change are rewritten. Comments, ordering and Java's
                escaping in the file are preserved, so a save never reformats what it did
                not touch. A restart is needed for changes to apply.
            </x-slot>

            <x-slot name="headerEnd">
                <x-filament::input.wrapper prefix-icon="tabler-search" style="max-width: 16rem;">
                    <x-filament::input
                        type="search"
                        wire:model.live.debounce.200ms="search"
                        placeholder="Search all settings"
                    />
                </x-filament::input.wrapper>
            </x-slot>

            {{-- Tabs are hidden while searching: a search spans every group, and
                 showing one as selected would misdescribe what is on screen. --}}
            @if (trim($search) === '' && count($groups) > 1)
                <x-filament::tabs contained>
                    @foreach ($groups as $group)
                        {{-- Blade compiles {{ }} inside a component's attributes but not
                             @directives, so @js() here would reach the browser verbatim
                             and Livewire could not parse the call. --}}
                        <x-filament::tabs.item
                            wire:key="tab-{{ Str::slug($group) }}"
                            :active="$activeGroup === $group"
                            :badge="$this->dirtyInGroup($group) ?: null"
                            badge-color="warning"
                            wire:click="setGroup({{ \Illuminate\Support\Js::from($group) }})"
                        >
                            {{ $group }}
                        </x-filament::tabs.item>
                    @endforeach
                </x-filament::tabs>
            @endif

            @php $visible = $this->visibleFields(); @endphp

            @if ($visible === [])
                <x-filament::empty-state
                    heading="No settings match"
                    :description="'Nothing in server.properties matches “' . $search . '”.'"
                    icon="tabler-search-off"
                    icon-color="gray"
                    :contained="false"
                    compact
                />
            @else
                @if (trim($search) !== '')
                    <p class="fi-text-xs fi-opacity-70" style="margin-bottom: 0.75rem;">
                        Showing {{ count($visible) }} of {{ count($fields) }} settings from every group.
                    </p>
                @endif

                <div style="display: grid; gap: 0.875rem;">
                    @foreach ($visible as $field)
                        @php
                            $key = $field['key'];
                            $isDirty = $this->isDirty($key);
                        @endphp

                        <div
                            wire:key="prop-{{ $key }}"
                            style="
                                display: grid;
                                grid-template-columns: minmax(12rem, 22rem) 1fr;
                                gap: 1rem;
                                align-items: start;
                                padding-inline-start: 0.625rem;
                                border-inline-start: 2px solid {{ $isDirty ? '#f59e0b' : 'transparent' }};
                            "
                        >
                            <div>
                                <code class="fi-text-sm">{{ $key }}</code>

                                @if (trim($search) !== '')
                                    <x-filament::badge color="gray" size="xs">{{ $field['group'] }}</x-filament::badge>
                                @endif

                                @if ($field['help'])
                                    <p class="fi-text-xs fi-opacity-70" style="margin-top: 0.125rem;">{{ $field['help'] }}</p>
                                @endif
                            </div>

                            <div>
                                @switch ($field['type'])
                                    @case ('boolean')
                                        {{-- A checkbox, not a select: Livewire binds a checkbox
                                             straight to a PHP boolean, whereas a select whose
                                             option values are "1"/"0" matches neither true nor
                                             false and silently falls back to its first option. --}}
                                        <label style="display: inline-flex; align-items: center; gap: 0.5rem; cursor: {{ $writable ? 'pointer' : 'default' }};">
                                            <input
                                                type="checkbox"
                                                class="fi-checkbox-input"
                                                wire:model.live="values.{{ $key }}"
                                                @disabled(! $writable)
                                            />
                                            <span class="fi-text-sm">
                                                {{ ($values[$key] ?? false) ? 'true' : 'false' }}
                                            </span>
                                        </label>
                                        @break

                                    @case ('enum')
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select wire:model.live="values.{{ $key }}" :disabled="! $writable">
                                                @foreach ($field['options'] as $option)
                                                    <option value="{{ $option }}">{{ $option }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                        @break

                                    @case ('integer')
                                        <x-filament::input.wrapper>
                                            <x-filament::input type="number" wire:model.live.debounce.500ms="values.{{ $key }}" :disabled="! $writable" />
                                        </x-filament::input.wrapper>
                                        @break

                                    @case ('secret')
                                        {{-- Reveal is client-side: a server round trip to flip an
                                             input's type is at the mercy of Livewire's DOM morph,
                                             and there is nothing here the server needs to know. --}}
                                        <div x-data="{ shown: false }" style="display: flex; gap: 0.5rem; align-items: center;">
                                            <x-filament::input.wrapper style="flex: 1;">
                                                <x-filament::input
                                                    x-bind:type="shown ? 'text' : 'password'"
                                                    type="password"
                                                    wire:model.live.debounce.500ms="values.{{ $key }}"
                                                    :disabled="! $writable"
                                                />
                                            </x-filament::input.wrapper>

                                            {{-- Two buttons rather than one with a bound icon:
                                                 `icon` is a Blade prop resolved on the server, so
                                                 x-bind cannot reach it. --}}
                                            <span x-show="! shown">
                                                <x-filament::icon-button
                                                    x-on:click="shown = true"
                                                    icon="tabler-eye"
                                                    color="gray"
                                                    label="Reveal"
                                                />
                                            </span>

                                            <span x-show="shown" x-cloak>
                                                <x-filament::icon-button
                                                    x-on:click="shown = false"
                                                    icon="tabler-eye-off"
                                                    color="gray"
                                                    label="Hide"
                                                />
                                            </span>
                                        </div>
                                        @break

                                    @default
                                        <x-filament::input.wrapper>
                                            <x-filament::input type="text" wire:model.live.debounce.500ms="values.{{ $key }}" :disabled="! $writable" />
                                        </x-filament::input.wrapper>
                                @endswitch

                                @if ($isDirty)
                                    <p class="fi-text-xs" style="margin-top: 0.25rem; color: #f59e0b;">
                                        was
                                        <code>{{ is_bool($original[$key] ?? null) ? (($original[$key]) ? 'true' : 'false') : (($original[$key] ?? '') === '' ? '(empty)' : $original[$key]) }}</code>
                                    </p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
