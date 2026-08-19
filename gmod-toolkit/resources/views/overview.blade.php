@php
    use Catualus\GmodToolkit\Filament\Server\Pages\Addons;
    use Catualus\GmodToolkit\Filament\Server\Pages\Library;
    use Catualus\GmodToolkit\Filament\Server\Pages\LuaErrors;
    use Catualus\GmodToolkit\Filament\Server\Pages\Workshop;
    use Catualus\GmodToolkit\Support\Bytes;

    $wait = $this->downloadWait();
@endphp

<x-filament-panels::page>
    {{-- The one number nothing else in the panel shows: what joining actually costs. --}}
    <div style="display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));">
        <x-gmod-toolkit::stat
            label="Client download"
            :value="$collectionId === null ? 'No collection' : Bytes::human($collection['size'] ?? 0)"
            :description="$wait ? 'About ' . $wait . ' at ' . $downloadMbps . ' Mbps' : 'Set WORKSHOP_ID on the Startup page'"
            icon="tabler-cloud-download"
            :tone="($collection['size'] ?? 0) > 2147483648 ? 'warning' : 'neutral'"
            :url="Workshop::getUrl()"
        />

        <x-gmod-toolkit::stat
            label="Collection"
            :value="number_format($collection['count'] ?? 0)"
            :description="($collection['recent'] ?? 0) > 0
                ? ($collection['recent'] . ' updated in the last ' . config('gmod-toolkit.recent_update_days', 7) . ' days')
                : 'Nothing updated recently'"
            icon="tabler-package"
            :tone="($collection['recent'] ?? 0) > 0 ? 'info' : 'neutral'"
            :url="Workshop::getUrl()"
        />

        <x-gmod-toolkit::stat
            label="Erroring now"
            :value="number_format($errors['active'] ?? 0)"
            :description="($errors['errors'] ?? 0) > 0
                ? number_format($errors['errors']) . ' errors logged in total'
                : 'Nothing in the logs'"
            icon="tabler-alert-triangle"
            :tone="($errors['active'] ?? 0) > 0 ? 'danger' : 'success'"
            :url="LuaErrors::getUrl()"
        />

        <x-gmod-toolkit::stat
            label="Installed addons"
            :value="number_format($addonCount)"
            :description="$disabledCount > 0 ? $disabledCount . ' disabled' : 'All enabled'"
            icon="tabler-folders"
            :url="Addons::getUrl()"
        />

        <x-gmod-toolkit::stat
            label="Addons on disk"
            :value="$disk === null ? 'Not measured' : Bytes::human($disk['total'])"
            :description="$disk === null
                ? 'Use Scan disk usage above'
                : ($disk['partial'] ? 'Partial scan - at least this much' : 'Measured ' . $disk['measured_at']?->diffForHumans())"
            icon="tabler-database"
            :url="Addons::getUrl()"
        />

        <x-gmod-toolkit::stat
            label="From the library"
            :value="number_format($installedFromLibrary)"
            description="Catalogue addons found on this server"
            icon="tabler-books"
            :url="Library::getUrl()"
        />
    </div>

    @if ($collectionId !== null && ($collection['foreign'] ?? 0) > 0)
        <x-filament::callout color="danger" icon="tabler-alert-triangle">
            <x-slot name="heading">
                {{ $collection['foreign'] }} collection {{ Str::plural('item', $collection['foreign']) }} not from Garry's Mod
            </x-slot>

            Those will never mount. The workshop page can filter them out for you.

            <x-slot name="footer">
                <x-filament::link :href="Workshop::getUrl()">Show them</x-filament::link>
            </x-slot>
        </x-filament::callout>
    @endif

    @if (($collection['banned'] ?? 0) > 0)
        <x-filament::callout color="danger" icon="tabler-ban">
            <x-slot name="heading">
                {{ $collection['banned'] }} {{ Str::plural('addon', $collection['banned']) }} in the collection
                {{ $collection['banned'] === 1 ? 'has' : 'have' }} been removed from the workshop
            </x-slot>

            Steam will not serve them any more, so clients cannot download the content they
            provide. Anything depending on them will fail quietly.
        </x-filament::callout>
    @endif

    <x-filament::section>
        <x-slot name="heading">What is failing right now</x-slot>
        <x-slot name="description">
            Addons appearing in the last
            {{ number_format((int) config('gmod-toolkit.recent_window', 100)) }} errors of a log.
            Everything else in the logs is history that has not recurred.
        </x-slot>

        @if ($offenders === [])
            <x-filament::empty-state
                heading="Nothing is erroring"
                description="No addon appears in the recent window of either log. Note that server-side errors are only recorded when -condebug is on the startup command."
                icon="tabler-mood-smile"
                icon-color="success"
                :contained="false"
                compact
            />
        @else
            <div style="display: grid; gap: 0.5rem;">
                @foreach ($offenders as $offender)
                    <div
                        wire:key="offender-{{ md5($offender['addon']) }}"
                        style="display: flex; align-items: baseline; gap: 0.75rem; flex-wrap: wrap;"
                    >
                        <x-filament::badge color="danger">
                            {{ number_format($offender['recent']) }} recent
                        </x-filament::badge>

                        <strong class="fi-text-sm">{{ $offender['addon'] }}</strong>

                        <span class="fi-text-sm fi-opacity-70" style="flex: 1 1 20rem; min-width: 0;">
                            {{ Str::limit($offender['message'], 120) }}
                        </span>

                        @if ($offender['count'] > $offender['recent'])
                            <span class="fi-text-xs fi-opacity-60">
                                {{ number_format($offender['count']) }} all time
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>

            <x-slot name="footerActions">
                <x-filament::link :href="LuaErrors::getUrl()">Open the error digest</x-filament::link>
            </x-slot>
        @endif
    </x-filament::section>
</x-filament-panels::page>
