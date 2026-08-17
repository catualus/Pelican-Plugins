<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Deploy</x-slot>
        <x-slot name="description">
            The panel has no way to run a shell command, so redeploying restarts the server
            and lets the startup command do the pull - which it only does when
            <strong>Auto Update</strong> is on.
        </x-slot>

        @if ($deploy['repo'])
            <div style="display: grid; gap: 0.5rem;">
                <div>
                    <span class="fi-text-sm fi-opacity-70">Repository</span><br>
                    <code class="fi-text-sm">{{ $deploy['repo'] }}</code>
                </div>
                <div>
                    <span class="fi-text-sm fi-opacity-70">Branch</span><br>
                    <code class="fi-text-sm">{{ $deploy['branch'] ?: 'default' }}</code>
                </div>
                <div>
                    <span class="fi-text-sm fi-opacity-70">Auto Update</span><br>
                    @if ($deploy['auto_update'])
                        <x-filament::badge color="success">On - pulls on every start</x-filament::badge>
                    @else
                        <x-filament::badge color="warning">Off - restarting will not pull</x-filament::badge>
                    @endif
                </div>
            </div>

            @unless ($deploy['has_git_dir'])
                <p class="fi-text-sm" style="margin-top: 0.75rem;">
                    No <code>.git</code> directory on disk yet, so the repository has not been
                    cloned. The first start will clone it.
                </p>
            @endunless
        @else
            <p class="fi-text-sm">
                No <code>GIT_ADDRESS</code> startup variable, so this app is not deployed from a
                repository. Restarting will simply restart the current files.
            </p>
        @endif

        @if ($restartable)
            <x-slot name="footerActions">
                <x-filament::button
                    icon="tabler-refresh"
                    wire:click="redeploy"
                    wire:confirm="Restart this server now?{{ $deploy['repo'] && ! $deploy['auto_update'] ? ' Auto Update is off, so this will NOT pull the latest commit.' : '' }}"
                    wire:loading.attr="disabled"
                >{{ $deploy['repo'] && $deploy['auto_update'] ? 'Pull & restart' : 'Restart' }}</x-filament::button>
            </x-slot>
        @endif
    </x-filament::section>

    <x-filament::section collapsible :collapsed="! $hasEnv">
        <x-slot name="heading">
            Environment
            @if ($env !== [])
                <x-filament::badge>{{ count($env) }}</x-filament::badge>
            @endif
        </x-slot>
        <x-slot name="description">
            <code>.env</code> - comments, ordering and quoting are preserved; only values
            you change are rewritten.
        </x-slot>

        @unless ($hasEnv)
            <p class="fi-text-sm">No <code>.env</code> file in the server root.</p>
        @elseif ($env === [])
            <p class="fi-text-sm">The file exists but defines no variables.</p>
        @else
            <p class="fi-text-xs fi-opacity-70" style="margin-bottom: 0.75rem;">
                Hidden values are cover against shoulder-surfing, not a security boundary
                - anyone who can open this page can already read the raw file.
            </p>

            <div style="display: grid; gap: 0.5rem;">
                @foreach ($env as $entry)
                    @php $key = $entry['key']; @endphp
                    <div style="display: grid; grid-template-columns: minmax(10rem, 18rem) 1fr auto; gap: 0.5rem; align-items: center;">
                        <code class="fi-text-sm">{{ $key }}</code>

                        <x-filament::input.wrapper>
                            <x-filament::input
                                :type="($revealed[$key] ?? false) ? 'text' : 'password'"
                                wire:model="envValues.{{ $key }}"
                                :disabled="! $writable"
                            />
                        </x-filament::input.wrapper>

                        <x-filament::icon-button
                            :icon="($revealed[$key] ?? false) ? 'tabler-eye-off' : 'tabler-eye'"
                            color="gray"
                            :label="($revealed[$key] ?? false) ? 'Hide' : 'Reveal'"
                            wire:click="reveal(@js($key))"
                        />
                    </div>
                @endforeach
            </div>

            @if ($writable)
                <x-slot name="footerActions">
                    <x-filament::button wire:click="saveEnv" wire:loading.attr="disabled" icon="tabler-device-floppy">
                        Save .env
                    </x-filament::button>
                </x-slot>
            @endif
        @endunless
    </x-filament::section>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">Dependencies</x-slot>
        <x-slot name="description">
            @if ($dependencies['file'])
                <code>{{ $dependencies['file'] }}</code> - read only
            @else
                Nothing to read
            @endif
        </x-slot>

        @if ($dependencies['groups'] === [])
            <p class="fi-text-sm">
                No <code>package.json</code> or requirements file found in the server root.
            </p>
        @else
            @foreach ($dependencies['groups'] as $group)
                <h3 class="fi-text-sm" style="margin: 0.5rem 0 0.25rem; font-weight: 600;">
                    {{ $group['label'] }} ({{ count($group['packages']) }})
                </h3>

                <div class="fi-ta-ctn" style="overflow-x: auto;">
                    <table class="fi-ta-table" style="width: 100%; text-align: start;">
                        <tbody>
                            @foreach ($group['packages'] as $package)
                                <tr>
                                    <td style="padding: 0.375rem 0.75rem;">{{ $package['name'] }}</td>
                                    <td style="padding: 0.375rem 0.75rem; text-align: end;">
                                        <code class="fi-text-xs">{{ $package['version'] }}</code>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        @endif
    </x-filament::section>
</x-filament-panels::page>
