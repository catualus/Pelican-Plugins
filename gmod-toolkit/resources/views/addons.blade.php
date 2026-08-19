<x-filament-panels::page>
    @if ($scanning)
        {{-- wire:poll drives the scan a few addons at a time. Doing the whole walk in
             one request would mean thousands of daemon calls behind a spinner that
             says nothing, and any reverse proxy in front of the panel would time it
             out long before it finished. --}}
        <div wire:poll.750ms="scanStep">
            <x-filament::section>
                <x-slot name="heading">Measuring disk usage</x-slot>

                <x-slot name="description">
                    {{ count($scanSizes) }} of {{ $scanTotal }} addons ·
                    {{ number_format($scanRequests) }} daemon requests so far
                    @if ($scanCurrent !== '')
                        · currently <code>{{ $scanCurrent }}</code>
                    @endif
                </x-slot>

                <x-slot name="headerEnd">
                    <x-filament::button size="sm" color="gray" icon="tabler-x" wire:click="cancelScan">
                        Stop
                    </x-filament::button>
                </x-slot>

                @php $progress = $this->scanProgress(); @endphp

                <div
                    role="progressbar"
                    aria-valuenow="{{ $progress }}"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    style="
                        height: 0.5rem;
                        border-radius: 999px;
                        overflow: hidden;
                        background-color: color-mix(in srgb, currentColor 12%, transparent);
                    "
                >
                    <div style="width: {{ $progress }}%; height: 100%; background-color: #3b82f6; transition: width 0.3s;"></div>
                </div>

                <p class="fi-text-xs fi-opacity-70" style="margin-top: 0.5rem;">
                    The daemon has no way to report a folder's size, so this walks the tree
                    one directory at a time. You can leave this page - the scan stops if you
                    do, and nothing is saved until it finishes.
                </p>
            </x-filament::section>
        </div>
    @endif

    @if ($darkrp !== [])
        <x-filament::section>
            <x-slot name="heading">DarkRP</x-slot>
            <x-slot name="description">
                The folders worth editing. Configure DarkRP through
                <code>darkrpmodification</code> rather than the gamemode itself, or the next
                update overwrites your changes.
            </x-slot>

            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                @foreach ($darkrp as $shortcut)
                    <x-filament::button
                        wire:key="darkrp-{{ md5($shortcut['path']) }}"
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

    {{ $this->table }}
</x-filament-panels::page>
