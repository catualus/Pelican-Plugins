<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Deploy</x-slot>
        <x-slot name="description">
            The panel has no way to run a shell command, so redeploying restarts the server
            and lets the startup command do the pull - which it only does when
            <strong>Auto Update</strong> is on.
        </x-slot>

        @if ($deploy['repo'])
            <div style="display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));">
                <div>
                    <span class="fi-text-xs fi-opacity-70" style="text-transform: uppercase; letter-spacing: 0.04em;">Repository</span><br>
                    <code class="fi-text-sm">{{ $deploy['repo'] }}</code>
                </div>

                <div>
                    <span class="fi-text-xs fi-opacity-70" style="text-transform: uppercase; letter-spacing: 0.04em;">Branch</span><br>
                    <code class="fi-text-sm">{{ $deploy['branch'] ?: 'default' }}</code>
                </div>

                <div>
                    <span class="fi-text-xs fi-opacity-70" style="text-transform: uppercase; letter-spacing: 0.04em;">Running commit</span><br>
                    @if ($deploy['commit'])
                        <code class="fi-text-sm">{{ $deploy['commit'] }}</code>
                        @if ($deploy['commit_ref'])
                            <span class="fi-text-xs fi-opacity-70">on {{ $deploy['commit_ref'] }}</span>
                        @endif
                    @else
                        <span class="fi-text-sm fi-opacity-70">
                            {{ $deploy['has_git_dir'] ? 'Could not read .git' : 'Not cloned yet' }}
                        </span>
                    @endif
                </div>

                <div>
                    <span class="fi-text-xs fi-opacity-70" style="text-transform: uppercase; letter-spacing: 0.04em;">Auto Update</span><br>
                    @if ($deploy['auto_update'])
                        <x-filament::badge color="success">On — pulls on every start</x-filament::badge>
                    @else
                        <x-filament::badge color="warning">Off — restarting will not pull</x-filament::badge>
                    @endif
                </div>
            </div>

            @unless ($deploy['has_git_dir'])
                <x-filament::callout color="info" icon="tabler-git-branch" style="margin-top: 0.75rem;">
                    No <code>.git</code> directory on disk yet, so the repository has not been
                    cloned. The first start will clone it.
                </x-filament::callout>
            @endunless
        @else
            <x-filament::empty-state
                heading="Not deployed from a repository"
                description="This server has no GIT_ADDRESS startup variable, so restarting simply restarts the current files."
                icon="tabler-git-branch"
                icon-color="gray"
                :contained="false"
                compact
            />
        @endif
    </x-filament::section>

    <x-filament::section collapsible :collapsed="! $hasEnv">
        <x-slot name="heading">
            Environment
            @if ($env !== [])
                <x-filament::badge>{{ count($env) }}</x-filament::badge>
            @endif
            @if ($this->dirtyKeys() !== [])
                <x-filament::badge color="warning">{{ count($this->dirtyKeys()) }} unsaved</x-filament::badge>
            @endif
        </x-slot>

        <x-slot name="description">
            <code>.env</code> — comments, ordering and quoting are preserved; only values
            you change are rewritten.
        </x-slot>

        @unless ($hasEnv)
            <x-filament::empty-state
                heading="No .env file"
                description="Nothing named .env in the server root. Create one in the file manager and it will be editable here."
                icon="tabler-file-off"
                icon-color="gray"
                :contained="false"
                compact
            />
        @elseif ($env === [])
            <p class="fi-text-sm">The file exists but defines no variables.</p>
        @else
            {{--
                One Alpine scope for the whole list, so "reveal all" and the per-row
                toggles share state. This is deliberately client-side: the server has no
                business knowing which values are on screen, and a round trip to flip an
                input's type is at the mercy of Livewire's DOM morph - which is exactly
                why the previous server-driven version of this did nothing at all.
            --}}
            <div x-data="{ shown: {}, all: false, reveal(k) { this.shown[k] = ! this.shown[k] } }">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 0.75rem;">
                    <p class="fi-text-xs fi-opacity-70" style="margin: 0; flex: 1 1 20rem;">
                        Hidden values are cover against shoulder-surfing, not a security
                        boundary — anyone who can open this page can already read the raw file.
                    </p>

                    {{-- Two buttons rather than x-text on one: the button component
                         renders its own inner markup, which x-text would replace. --}}
                    <span x-show="! all">
                        <x-filament::button size="xs" color="gray" icon="tabler-eye" x-on:click="all = true; shown = {}">
                            Reveal all
                        </x-filament::button>
                    </span>

                    <span x-show="all" x-cloak>
                        <x-filament::button size="xs" color="gray" icon="tabler-eye-off" x-on:click="all = false; shown = {}">
                            Hide all
                        </x-filament::button>
                    </span>
                </div>

                <div style="display: grid; gap: 0.5rem;">
                    @foreach ($env as $entry)
                        @php
                            $key = $entry['key'];
                            $isDirty = $this->isDirty($key);

                            // Blade compiles {{ }} inside a component's attributes but
                            // NOT @directives - an @js() there is emitted literally and
                            // Alpine then dies on the syntax error, taking the rest of
                            // the tree with it. So build the literal here and echo it.
                            $keyJs = \Illuminate\Support\Js::from($key);
                        @endphp

                        {{-- wire:key matters here: without it Livewire's morph matches these
                             structurally identical rows by position and can keep the wrong
                             input across a re-render. --}}
                        <div
                            wire:key="env-{{ $key }}"
                            x-data="{ secret: @js($entry['secret']) }"
                            style="
                                display: grid;
                                grid-template-columns: minmax(10rem, 18rem) 1fr auto;
                                gap: 0.5rem;
                                align-items: center;
                                padding-inline-start: 0.5rem;
                                border-inline-start: 2px solid {{ $isDirty ? '#f59e0b' : 'transparent' }};
                            "
                        >
                            <div style="min-width: 0;">
                                <code class="fi-text-sm">{{ $key }}</code>
                                @if ($entry['secret'])
                                    <x-filament::badge color="gray" size="xs">secret</x-filament::badge>
                                @endif
                                @if ($isDirty)
                                    <x-filament::badge color="warning" size="xs">changed</x-filament::badge>
                                @endif
                            </div>

                            <x-filament::input.wrapper>
                                <x-filament::input
                                    x-ref="value"
                                    type="text"
                                    x-bind:type="(! secret || all || shown[{{ $keyJs }}]) ? 'text' : 'password'"
                                    wire:model.live.debounce.500ms="envValues.{{ $key }}"
                                    :disabled="! $writable"
                                />
                            </x-filament::input.wrapper>

                            <div style="display: flex; gap: 0.25rem;">
                                <span x-show="secret">
                                    <x-filament::icon-button
                                        icon="tabler-eye"
                                        color="gray"
                                        label="Reveal or hide this value"
                                        x-on:click="reveal({{ $keyJs }})"
                                    />
                                </span>

                                <x-filament::icon-button
                                    icon="tabler-copy"
                                    color="gray"
                                    label="Copy value"
                                    x-on:click="navigator.clipboard?.writeText($refs.value.value)"
                                />
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endunless
    </x-filament::section>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">
            Dependencies
            @if ($dependencies['groups'] !== [])
                <x-filament::badge color="gray">
                    {{ collect($dependencies['groups'])->sum(fn (array $g) => count($g['packages'])) }}
                </x-filament::badge>
            @endif
        </x-slot>

        <x-slot name="description">
            @if ($dependencies['file'])
                <code>{{ $dependencies['file'] }}</code> — read only
            @else
                Nothing to read
            @endif
        </x-slot>

        @if ($dependencies['groups'] === [])
            <x-filament::empty-state
                heading="No dependency file"
                description="No package.json or requirements file in the server root."
                icon="tabler-package-off"
                icon-color="gray"
                :contained="false"
                compact
            />
        @else
            <x-filament::input.wrapper prefix-icon="tabler-search" style="max-width: 18rem; margin-bottom: 0.75rem;">
                <x-filament::input
                    type="search"
                    wire:model.live.debounce.200ms="dependencySearch"
                    placeholder="Filter packages"
                />
            </x-filament::input.wrapper>

            @foreach ($this->visibleDependencies() as $group)
                <h3 class="fi-text-sm" style="margin: 0.75rem 0 0.25rem; font-weight: 600;">
                    {{ $group['label'] }} ({{ count($group['packages']) }})
                </h3>

                <div class="fi-ta-ctn" style="overflow-x: auto;">
                    <table class="fi-ta-table" style="width: 100%; text-align: start;">
                        <tbody>
                            @foreach ($group['packages'] as $package)
                                <tr wire:key="dep-{{ $group['label'] }}-{{ $package['name'] }}">
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

            @if ($this->visibleDependencies() === [])
                <p class="fi-text-sm fi-opacity-70">Nothing matches that filter.</p>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
