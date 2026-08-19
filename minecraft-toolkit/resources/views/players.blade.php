<x-filament-panels::page>
    @php
        $lists = \Catualus\MinecraftToolkit\Filament\Server\Pages\Players::LISTS;
        $meta = $this->meta();
    @endphp

    <x-filament::section>
        <x-slot name="heading">{{ $meta['label'] }}</x-slot>

        <x-slot name="description">
            <code>{{ $meta['file'] }}</code>
            @unless ($activeList === 'banned-ips')
                — matched on UUID, not name. An entry with only a name is silently ignored
                by the server, which is why adding someone looks their UUID up from Mojang
                first.
            @endunless
        </x-slot>

        <x-filament::tabs contained>
            @foreach ($lists as $key => $list)
                <x-filament::tabs.item
                    wire:key="playerlist-{{ $key }}"
                    :active="$activeList === $key"
                    :badge="$this->count($key) ?: null"
                    wire:click="setList({{ \Illuminate\Support\Js::from($key) }})"
                >
                    {{ $list['label'] }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        {{ $this->table }}

        <p class="fi-text-xs fi-opacity-70" style="margin-top: 0.75rem;">
            Changes are written straight to the JSON file. Run <code>/reload</code> or restart
            for the server to pick them up.
        </p>
    </x-filament::section>
</x-filament-panels::page>
