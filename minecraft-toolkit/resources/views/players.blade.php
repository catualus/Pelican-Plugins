<x-filament-panels::page>
    @foreach (\Catualus\MinecraftToolkit\Filament\Server\Pages\Players::LISTS as $key => $meta)
        @php $entries = $lists[$key] ?? []; @endphp

        <x-filament::section collapsible :collapsed="$entries === []">
            <x-slot name="heading">
                {{ $meta['label'] }}
                @if ($entries !== [])
                    <x-filament::badge>{{ count($entries) }}</x-filament::badge>
                @endif
            </x-slot>

            <x-slot name="description"><code>{{ $meta['file'] }}</code></x-slot>

            @if ($writable)
                <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem;">
                    <x-filament::input.wrapper style="flex: 1 1 14rem; max-width: 20rem;">
                        <x-filament::input
                            type="text"
                            wire:model="newEntry.{{ $key }}"
                            wire:keydown.enter="add(@js($key))"
                            :placeholder="$key === 'banned-ips' ? 'IP address' : 'Minecraft username'"
                        />
                    </x-filament::input.wrapper>

                    @if ($key === 'ops')
                        <x-filament::input.wrapper style="max-width: 8rem;">
                            <x-filament::input.select wire:model="opLevel">
                                <option value="1">Level 1</option>
                                <option value="2">Level 2</option>
                                <option value="3">Level 3</option>
                                <option value="4">Level 4</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    @endif

                    <x-filament::button
                        icon="tabler-plus"
                        wire:click="add(@js($key))"
                        wire:loading.attr="disabled"
                    >Add</x-filament::button>
                </div>

                @unless ($key === 'banned-ips')
                    <p class="fi-text-xs fi-opacity-70" style="margin-bottom: 0.75rem;">
                        The UUID is looked up from Mojang - Minecraft matches these files on
                        UUID, so an entry without one is ignored by the server.
                    </p>
                @endunless
            @endif

            @if ($entries === [])
                <p class="fi-text-sm">Empty.</p>
            @else
                <div class="fi-ta-ctn" style="overflow-x: auto;">
                    <table class="fi-ta-table" style="width: 100%; text-align: start;">
                        <thead>
                            <tr>
                                <th style="text-align: start; padding: 0.5rem 0.75rem;">
                                    {{ $key === 'banned-ips' ? 'IP' : 'Player' }}
                                </th>
                                @if ($key === 'ops')
                                    <th style="text-align: start; padding: 0.5rem 0.75rem;">Level</th>
                                @endif
                                @if (str_starts_with($key, 'banned'))
                                    <th style="text-align: start; padding: 0.5rem 0.75rem;">Reason</th>
                                @endif
                                <th style="text-align: end; padding: 0.5rem 0.75rem;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($entries as $entry)
                                @php $id = (string) ($entry[$meta['key']] ?? ''); @endphp
                                <tr>
                                    <td style="padding: 0.5rem 0.75rem;">
                                        {{ $entry['name'] ?? $entry['ip'] ?? $id }}
                                        @if (($entry['uuid'] ?? null))
                                            <br><code class="fi-text-xs fi-opacity-70">{{ $entry['uuid'] }}</code>
                                        @endif
                                    </td>
                                    @if ($key === 'ops')
                                        <td style="padding: 0.5rem 0.75rem;">{{ $entry['level'] ?? '-' }}</td>
                                    @endif
                                    @if (str_starts_with($key, 'banned'))
                                        <td style="padding: 0.5rem 0.75rem;">
                                            <span class="fi-text-sm">{{ $entry['reason'] ?? '-' }}</span>
                                        </td>
                                    @endif
                                    <td style="padding: 0.5rem 0.75rem; text-align: end;">
                                        @if ($writable && $id !== '')
                                            <x-filament::button
                                                size="xs"
                                                color="danger"
                                                wire:click="remove(@js($key), @js($id))"
                                                wire:confirm="Remove {{ $entry['name'] ?? $entry['ip'] ?? $id }} from {{ $meta['label'] }}?"
                                                wire:loading.attr="disabled"
                                            >Remove</x-filament::button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
