<x-filament-panels::page>
    @if ($crashes !== [])
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">
                Recent crashes
                <x-filament::badge color="danger">{{ count($crashes) }}</x-filament::badge>
            </x-slot>
            <x-slot name="description">
                Newest first. Crashes are usually caused by a mod - find the culprit in
                the report, then disable it below.
            </x-slot>

            <div style="display: grid; gap: 0.375rem;">
                @foreach ($crashes as $crash)
                    <div>
                        <x-filament::link :href="$this->fileUrl('crash-reports')">
                            <code class="fi-text-sm">{{ $crash['name'] }}</code>
                        </x-filament::link>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">{{ $this->getTitle() }}</x-slot>

        <x-slot name="description">
            Disabling renames the file to <code>.jar.disabled</code>, which is what stops the
            server loading it. Works for anything on disk, including CurseForge mods. A
            restart is needed either way.
        </x-slot>

        <x-slot name="headerEnd">
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                @if ($pendingRestart && $restartable)
                    <x-filament::button
                        size="sm"
                        color="warning"
                        icon="tabler-refresh"
                        wire:click="restart"
                        wire:confirm="Restart the server now to apply your changes?"
                        wire:loading.attr="disabled"
                    >Restart to apply</x-filament::button>
                @endif

                <x-filament::input.wrapper prefix-icon="tabler-search" style="max-width: 16rem;">
                    <x-filament::input type="search" wire:model.live.debounce.200ms="search" placeholder="Filter by filename" />
                </x-filament::input.wrapper>
            </div>
        </x-slot>

        @if ($flavour === null)
            <p class="fi-text-sm">
                No <code>mods</code> or <code>plugins</code> directory found. If the server has
                not finished installing, try again once it has.
            </p>
        @elseif ($items === [])
            <p class="fi-text-sm">Nothing installed in <code>{{ $flavour }}</code>.</p>
        @else
            @php $visible = $this->visibleItems(); @endphp

            <p class="fi-text-sm" style="margin-bottom: 0.75rem;">
                Showing {{ number_format(count($visible)) }} of {{ number_format(count($items)) }}.
                Disabled entries are listed first.
            </p>

            <div class="fi-ta-ctn" style="overflow-x: auto; max-height: 40rem; overflow-y: auto;">
                <table class="fi-ta-table" style="width: 100%; text-align: start;">
                    <thead>
                        <tr>
                            <th style="text-align: start; padding: 0.5rem 0.75rem;">Name</th>
                            <th style="text-align: end; padding: 0.5rem 0.75rem;">Size</th>
                            <th style="text-align: start; padding: 0.5rem 0.75rem;">Modified</th>
                            <th style="text-align: end; padding: 0.5rem 0.75rem;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($visible as $item)
                            <tr @class(['fi-opacity-60' => ! $item['enabled']])>
                                <td style="padding: 0.5rem 0.75rem;">
                                    {{ $item['name'] }}
                                    @unless ($item['enabled'])
                                        <x-filament::badge color="gray">Disabled</x-filament::badge>
                                    @endunless
                                    <br><code class="fi-text-xs fi-opacity-70">{{ $item['file'] }}</code>
                                </td>
                                <td style="padding: 0.5rem 0.75rem; text-align: end; white-space: nowrap;">
                                    {{ $item['size'] > 0 ? number_format($item['size'] / 1048576, 1) . ' MB' : '-' }}
                                </td>
                                <td style="padding: 0.5rem 0.75rem; white-space: nowrap;">
                                    {{ $item['modified']?->diffForHumans() ?? '-' }}
                                </td>
                                <td style="padding: 0.5rem 0.75rem; text-align: end;">
                                    @if ($writable)
                                        <x-filament::button
                                            size="xs"
                                            :color="$item['enabled'] ? 'gray' : 'primary'"
                                            wire:click="toggle(@js($item['file']), {{ $item['enabled'] ? 'false' : 'true' }})"
                                            wire:loading.attr="disabled"
                                        >{{ $item['enabled'] ? 'Disable' : 'Enable' }}</x-filament::button>
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
