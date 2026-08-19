<x-filament-panels::page>
    @if ($installing)
        {{-- One step per request. An install is a download, an extract, two renames
             and a read-back; doing them in one call means a long silent wait and, on
             a slow archive, a PHP execution-time limit part way through. --}}
        <div wire:poll.600ms="installStep">
            <x-filament::section>
                <x-slot name="heading">
                    {{ $installPlan['name'] ?? 'Installing' }}
                </x-slot>

                <x-slot name="description">
                    {{ $this->currentStageLabel() }} ·
                    {{ count($installDone) + count($installFailures) }} of {{ $installTotal }} done
                </x-slot>

                <x-slot name="headerEnd">
                    <x-filament::button size="sm" color="gray" icon="tabler-x" wire:click="cancelInstall">
                        Stop after this one
                    </x-filament::button>
                </x-slot>

                @php $progress = $this->installProgress(); @endphp

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

                @if ($installLog !== [])
                    <div
                        style="
                            margin-top: 0.75rem;
                            max-height: 12rem;
                            overflow-y: auto;
                            font-size: 0.75rem;
                            line-height: 1.35rem;
                            opacity: 0.8;
                        "
                    >
                        @foreach ($installLog as $line)
                            <div wire:key="installlog-{{ $loop->index }}">
                                <code>{{ $line }}</code>
                            </div>
                        @endforeach
                    </div>
                @endif

                <p class="fi-text-xs fi-opacity-70" style="margin-top: 0.5rem;">
                    Leaving this page stops the run between addons. Nothing is recorded as
                    installed until the folder has been read back off the server and found.
                </p>
            </x-filament::section>
        </div>
    @endif

    @unless ($this->canInstall())
        <x-filament::callout color="gray" icon="tabler-lock">
            <x-slot name="heading">You can browse this list but not install from it</x-slot>

            Installing downloads an archive, extracts it and renames the result, which needs
            the <strong>file create</strong>, <strong>file archive</strong> and
            <strong>file update</strong> permissions on this server.
        </x-filament::callout>
    @endunless

    <x-filament::callout color="info" icon="tabler-info-circle">
        <x-slot name="heading">What installing actually does</x-slot>

        The panel cannot run shell commands, so this asks the daemon to download the
        addon's archive from GitHub, extract it in place, and rename the extracted folder.
        Nothing is fetched from anywhere except the fixed repositories listed below - the
        catalogue lives in <code>config/gmod-toolkit.php</code> and there is no way to point
        it at a URL from this page. Each install records the commit it came from, so
        <strong>Check for updates</strong> can tell you later what has moved on.
    </x-filament::callout>

    @if ($presets !== [])
        <x-filament::section collapsible>
            <x-slot name="heading">Presets</x-slot>
            <x-slot name="description">
                Bundles installed in one go, dependencies included. Use
                <strong>Install a preset</strong> above to run one.
            </x-slot>

            <div style="display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr));">
                @foreach ($presets as $preset)
                    <div
                        wire:key="preset-{{ $preset['id'] }}"
                        style="
                            padding: 0.875rem 1rem;
                            border-radius: 0.75rem;
                            border: 1px solid color-mix(in srgb, currentColor 12%, transparent);
                        "
                    >
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
                            <strong class="fi-text-sm">{{ $preset['name'] }}</strong>

                            <x-filament::badge :color="$preset['complete'] ? 'success' : 'gray'">
                                {{ $preset['complete'] ? 'Installed' : count($preset['pending']) . ' to install' }}
                            </x-filament::badge>
                        </div>

                        <p class="fi-text-xs fi-opacity-70" style="margin-bottom: 0.5rem;">
                            {{ $preset['description'] }}
                        </p>

                        <div style="display: flex; flex-wrap: wrap; gap: 0.25rem;">
                            @foreach ($preset['members'] as $member)
                                <x-filament::badge
                                    size="xs"
                                    :color="$member['installed'] ? 'success' : 'gray'"
                                    wire:key="preset-{{ $preset['id'] }}-{{ $member['id'] }}"
                                >{{ $member['name'] }}</x-filament::badge>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}

    <p class="fi-text-xs fi-opacity-70">
        Update status comes from comparing the commit each addon was installed at against
        its branch on GitHub. That lookup is cached for hours and capped per page load,
        because GitHub allows sixty requests an hour without an account — so an addon
        GitHub did not answer for is reported as <strong>Installed</strong> rather than
        being claimed to be up to date. <strong>Check for updates</strong> asks again
        immediately, ignoring the cache.
    </p>
</x-filament-panels::page>
