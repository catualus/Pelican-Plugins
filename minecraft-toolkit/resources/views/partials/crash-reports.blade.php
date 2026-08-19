<div style="display: grid; gap: 0.875rem;">
    @foreach ($crashes as $crash)
        <div
            wire:key="crash-{{ md5($crash['path']) }}"
            style="
                padding: 0.75rem 0.875rem;
                border-radius: 0.75rem;
                border: 1px solid color-mix(in srgb, currentColor 12%, transparent);
            "
        >
            <div style="display: flex; align-items: baseline; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.375rem;">
                <x-filament::link :href="$fileUrl($crash['path'])">
                    <code class="fi-text-sm">{{ $crash['name'] }}</code>
                </x-filament::link>

                @if ($crash['modified'])
                    <span class="fi-text-xs fi-opacity-60">{{ $crash['modified']->diffForHumans() }}</span>
                @endif
            </div>

            @if ($crash['description'])
                <p class="fi-text-sm" style="margin: 0 0 0.25rem;">{{ $crash['description'] }}</p>
            @endif

            @if ($crash['exception'])
                <code class="fi-text-xs" style="display: block; opacity: 0.8; word-break: break-word;">
                    {{ $crash['exception'] }}
                </code>
            @endif

            @if ($crash['culprit'])
                {{-- The first stack frame that is not Minecraft, Java or the mod loader.
                     It is a strong hint rather than proof, and is labelled as one. --}}
                <p class="fi-text-xs" style="margin: 0.375rem 0 0;">
                    <x-filament::badge color="warning" size="xs">likely from</x-filament::badge>
                    <code class="fi-text-xs">{{ $crash['culprit'] }}</code>
                </p>
            @endif

            @if (! $crash['description'] && ! $crash['exception'])
                <p class="fi-text-xs fi-opacity-70" style="margin: 0;">
                    Could not read a description out of this report - open it to see why.
                </p>
            @endif
        </div>
    @endforeach
</div>

<p class="fi-text-xs fi-opacity-70" style="margin-top: 0.75rem;">
    <x-filament::link :href="$folderUrl">Open the crash-reports folder</x-filament::link>
</p>
