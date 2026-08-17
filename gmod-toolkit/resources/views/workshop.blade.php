<x-filament-panels::page>
    @if ($collectionId === null)
        <x-filament::section>
            <x-slot name="heading">No workshop collection set</x-slot>

            <p class="fi-text-sm">
                This server has no <code>WORKSHOP_ID</code> startup variable. Set one on the
                Startup page and the collection will be listed here.
            </p>
        </x-filament::section>
    @elseif ($addons === [])
        <x-filament::section>
            <x-slot name="heading">Nothing to show</x-slot>

            <p class="fi-text-sm">
                Collection <strong>{{ $collectionId }}</strong> returned no addons. Either it is
                empty, it is not a collection, or Steam could not be reached - this page
                retries on the next load.
            </p>

            <x-slot name="footerActions">
                <x-filament::link :href="$this->collectionUrl()" target="_blank" rel="noopener">
                    Open on Steam
                </x-filament::link>
            </x-slot>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Collection contents</x-slot>

            <x-slot name="description">
                Addons updated in the last {{ $recentDays }} days are flagged - a recent
                change is the usual explanation for something that broke overnight.
            </x-slot>

            <div class="fi-ta-ctn" style="overflow-x: auto;">
                <table class="fi-ta-table" style="width: 100%; text-align: start;">
                    <thead>
                        <tr>
                            <th style="text-align: start; padding: 0.5rem 0.75rem;">Addon</th>
                            <th style="text-align: end; padding: 0.5rem 0.75rem;">Size</th>
                            <th style="text-align: start; padding: 0.5rem 0.75rem;">Updated</th>
                            <th style="text-align: end; padding: 0.5rem 0.75rem;">Subscribers</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($addons as $addon)
                            <tr>
                                <td style="padding: 0.5rem 0.75rem;">
                                    <x-filament::link
                                        :href="$addon['url']"
                                        target="_blank"
                                        rel="noopener"
                                    >{{ $addon['title'] }}</x-filament::link>

                                    @if ($addon['banned'])
                                        <x-filament::badge color="danger">
                                            Banned{{ $addon['ban_reason'] ? ': ' . $addon['ban_reason'] : '' }}
                                        </x-filament::badge>
                                    @elseif ($this->isRecent($addon['updated']))
                                        <x-filament::badge color="warning">Recently updated</x-filament::badge>
                                    @endif
                                </td>
                                <td style="padding: 0.5rem 0.75rem; text-align: end; white-space: nowrap;">
                                    {{ $addon['size'] > 0 ? number_format($addon['size'] / 1048576, 1) . ' MB' : '-' }}
                                </td>
                                <td style="padding: 0.5rem 0.75rem; white-space: nowrap;">
                                    {{ $addon['updated'] ? \Illuminate\Support\Carbon::createFromTimestamp($addon['updated'])->diffForHumans() : '-' }}
                                </td>
                                <td style="padding: 0.5rem 0.75rem; text-align: end;">
                                    {{ number_format($addon['subscriptions']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-slot name="footerActions">
                <x-filament::link :href="$this->collectionUrl()" target="_blank" rel="noopener">
                    Open collection on Steam
                </x-filament::link>
            </x-slot>
        </x-filament::section>
    @endif
</x-filament-panels::page>
