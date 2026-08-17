<x-filament-panels::page>
    @foreach ($sources as $source)
        <x-filament::section collapsible :collapsed="$source['total'] === 0">
            <x-slot name="heading">
                {{ $source['label'] }}
                @if ($source['total'] > 0)
                    <x-filament::badge color="danger">{{ number_format($source['total']) }}</x-filament::badge>
                @endif
            </x-slot>

            <x-slot name="description">
                <code>{{ $source['path'] }}</code>
                @if ($source['modified'])
                    - last written {{ $source['modified']->diffForHumans() }}
                @endif
            </x-slot>

            @if ($source['present'] && $writable && $source['total'] > 0)
                <x-slot name="headerEnd">
                    <x-filament::button
                        size="xs"
                        color="gray"
                        icon="tabler-trash"
                        wire:click="clear(@js($source['key']))"
                        wire:confirm="Empty {{ $source['path'] }}? Past errors are lost, and everything reported from now on starts from a clean slate."
                        wire:loading.attr="disabled"
                    >Clear log</x-filament::button>
                </x-slot>
            @endif

            @unless ($source['present'])
                <p class="fi-text-sm">
                    Not present.{{ $source['hint'] ? ' ' . $source['hint'] : '' }}
                </p>
            @elseif ($source['groups'] === [])
                <p class="fi-text-sm">No Lua errors in this log.</p>
            @else
                <p class="fi-text-sm" style="margin-bottom: 0.75rem;">
                    These logs carry no timestamps, so recency is measured by position in the
                    file. <strong>Active</strong> means the addon appears in the last
                    {{ number_format($source['window']) }} errors; everything else is history
                    that has not recurred since. Click a <code>file.lua:line</code> to open it
                    in the editor.
                </p>

                <div class="fi-ta-ctn" style="overflow-x: auto;">
                    <table class="fi-ta-table" style="width: 100%; text-align: start;">
                        <thead>
                            <tr>
                                <th style="text-align: start; padding: 0.5rem 0.75rem;">Addon</th>
                                <th style="text-align: end; padding: 0.5rem 0.75rem;">Errors</th>
                                <th style="text-align: start; padding: 0.5rem 0.75rem;">Last seen</th>
                                <th style="text-align: end; padding: 0.5rem 0.75rem;">Players</th>
                                <th style="text-align: start; padding: 0.5rem 0.75rem;">Most recent message</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($source['groups'] as $group)
                                @php $ago = $source['total'] - 1 - $group['last_index']; @endphp
                                <tr @class(['fi-opacity-60' => $group['recent'] === 0])>
                                    <td style="padding: 0.5rem 0.75rem; vertical-align: top;">
                                        @if ($group['path'])
                                            <x-filament::link :href="$this->fileUrl($group['path'])">
                                                {{ $group['addon'] }}
                                            </x-filament::link>
                                        @else
                                            {{ $group['addon'] }}
                                        @endif
                                    </td>
                                    <td style="padding: 0.5rem 0.75rem; text-align: end; vertical-align: top;">
                                        <x-filament::badge :color="$group['recent'] > 0 ? 'danger' : 'gray'">
                                            {{ number_format($group['count']) }}
                                        </x-filament::badge>
                                    </td>
                                    <td style="padding: 0.5rem 0.75rem; vertical-align: top; white-space: nowrap;">
                                        @if ($group['recent'] > 0)
                                            <x-filament::badge color="warning">Active</x-filament::badge>
                                        @else
                                            <span class="fi-text-sm">{{ number_format($ago) }} errors ago</span>
                                        @endif
                                    </td>
                                    <td style="padding: 0.5rem 0.75rem; text-align: end; vertical-align: top;">
                                        {{ $group['reporters'] > 0 ? $group['reporters'] : '-' }}
                                    </td>
                                    <td style="padding: 0.5rem 0.75rem;">
                                        <span class="fi-text-sm">{{ $group['message'] }}</span>
                                        @if ($group['location'])
                                            @php $edit = $this->editUrl($group['location']); @endphp
                                            <br>
                                            @if ($edit)
                                                <x-filament::link :href="$edit" size="xs">
                                                    <code class="fi-text-xs">{{ $group['location'] }}</code>
                                                </x-filament::link>
                                            @else
                                                <code class="fi-text-xs">{{ $group['location'] }}</code>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endunless
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
