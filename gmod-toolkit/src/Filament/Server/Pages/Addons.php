<?php

namespace Catualus\GmodToolkit\Filament\Server\Pages;

use App\Filament\Server\Resources\Files\Pages\ListFiles;
use BackedEnum;
use Catualus\GmodToolkit\Filament\Server\Pages\Concerns\PaginatesArrays;
use Catualus\GmodToolkit\Filament\Server\Widgets\AddonDiskUsageChart;
use Catualus\GmodToolkit\Services\AddonService;
use Catualus\GmodToolkit\Services\DiskUsageService;
use Catualus\GmodToolkit\Support\Bytes;
use Catualus\GmodToolkit\Support\GarrysMod;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class Addons extends GmodPage implements HasTable
{
    use InteractsWithTable;
    use PaginatesArrays;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-package';

    protected static ?int $navigationSort = 21;

    protected string $view = 'gmod-toolkit::addons';

    /** @var list<array<string, mixed>> */
    public array $addons = [];

    /** @var list<array{label: string, path: string}> */
    public array $darkrp = [];

    public bool $writable = false;

    /** Scan state. Kept on the component so a step can pick up where the last left off. */
    public bool $scanning = false;

    /** @var array<string, string> addon name => path, still to measure */
    public array $scanPending = [];

    /** @var array<string, int> */
    public array $scanSizes = [];

    public int $scanTotal = 0;

    public int $scanRequests = 0;

    public bool $scanPartial = false;

    public string $scanCurrent = '';

    public function mount(): void
    {
        $this->writable = $this->canWriteFiles();
        $this->load();
    }

    public function load(): void
    {
        $server = $this->server();

        $this->addons = app(AddonService::class)->all($server);

        $gmod = app(GarrysMod::class);

        $this->darkrp = array_values(array_filter(
            config('gmod-toolkit.darkrp_paths', []),
            fn (array $shortcut): bool => $gmod->exists($server, $shortcut['path']),
        ));
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, ?string $sortColumn, ?string $sortDirection, int|string $page, int|string $recordsPerPage) => $this->arrayRecords(
                rows: $this->filtered(),
                search: $search,
                sortColumn: $sortColumn,
                sortDirection: $sortDirection,
                page: $page,
                recordsPerPage: $recordsPerPage,
                searchable: ['name'],
            ))
            ->searchable()
            ->searchPlaceholder('Filter by folder name')
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('No addons found')
            ->emptyStateDescription('Either garrysmod/addons is empty, or the server is installing and its files are not readable yet.')
            ->emptyStateIcon('tabler-package-off')
            ->columns([
                TextColumn::make('name')
                    ->label('Addon')
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (array $record): ?string => $record['enabled'] ? null : 'In addons/__disabled')
                    ->url(fn (array $record): string => ListFiles::getUrl(['path' => trim($record['path'], '/')])),

                TextColumn::make('errors')
                    ->label('Errors')
                    ->alignEnd()
                    ->sortable()
                    ->badge()
                    // Red means it is still happening; grey means the log remembers a
                    // problem that has not recurred. The difference is the whole point
                    // of the column.
                    ->color(fn (array $record): string => match (true) {
                        $record['recent'] > 0 => 'danger',
                        $record['errors'] > 0 => 'gray',
                        default => 'success',
                    })
                    ->formatStateUsing(fn ($state): string => $state > 0 ? number_format($state) : '—')
                    ->tooltip(fn (array $record): ?string => $record['recent'] > 0
                        ? number_format($record['recent']) . ' of these are in the recent window'
                        : null),

                TextColumn::make('recent')
                    ->label('Status')
                    ->badge()
                    ->color(fn (array $record): string => $record['recent'] > 0 ? 'warning' : 'gray')
                    ->formatStateUsing(fn ($state, array $record): string => match (true) {
                        $state > 0 => 'Erroring now',
                        $record['errors'] > 0 => 'Historical',
                        default => 'Quiet',
                    }),

                TextColumn::make('size')
                    ->label('On disk')
                    ->alignEnd()
                    ->sortable()
                    ->visibleFrom('md')
                    // Empty until a scan has been run - the daemon cannot report a
                    // folder's size, so pretending to know would be a lie.
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : Bytes::human((int) $state))
                    ->placeholder('—'),

                TextColumn::make('modified')
                    ->label('Modified')
                    ->visibleFrom('lg')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => $state?->diffForHumans() ?? '—')
                    ->tooltip(fn (array $record): ?string => $record['modified']?->toDayDateTimeString()),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('State')
                    ->options(['enabled' => 'Enabled', 'disabled' => 'Disabled']),
                Filter::make('erroring')
                    ->label('Erroring now'),
            ])
            ->recordActions([
                Action::make('toggle')
                    ->label(fn (array $record): string => $record['enabled'] ? 'Disable' : 'Enable')
                    ->icon(fn (array $record): string => $record['enabled'] ? 'tabler-plug-off' : 'tabler-plug')
                    ->color(fn (array $record): string => $record['enabled'] ? 'gray' : 'primary')
                    ->authorize(fn (): bool => $this->canWriteFiles())
                    ->requiresConfirmation(fn (array $record): bool => $record['enabled'])
                    ->modalHeading(fn (array $record): string => "Disable {$record['name']}?")
                    ->modalDescription('The folder moves into addons/__disabled. Garry\'s Mod loads every direct child of addons, so this is what actually unloads it. A restart is needed either way.')
                    ->action(fn (array $record) => $this->toggle($record['name'], !$record['enabled'])),

                Action::make('browse')
                    ->label('Open folder')
                    ->icon('tabler-folder')
                    ->color('gray')
                    ->url(fn (array $record): string => ListFiles::getUrl(['path' => trim($record['path'], '/')])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('enable')
                        ->label('Enable selected')
                        ->icon('tabler-plug')
                        ->authorize(fn (): bool => $this->canWriteFiles())
                        ->requiresConfirmation()
                        ->modalDescription('Moves each selected addon back out of addons/__disabled. Restart the server afterwards to load them.')
                        ->action(fn (Collection $records) => $this->toggleMany($records, true))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('disable')
                        ->label('Disable selected')
                        ->icon('tabler-plug-off')
                        ->color('danger')
                        ->authorize(fn (): bool => $this->canWriteFiles())
                        ->requiresConfirmation()
                        ->modalDescription('Moves each selected addon into addons/__disabled. Nothing is deleted, and enabling puts them back.')
                        ->action(fn (Collection $records) => $this->toggleMany($records, false))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /**
     * Filters are applied here rather than inside the data source closure so the
     * charts above the table and the table itself stay in step.
     *
     * @return list<array<string, mixed>>
     */
    private function filtered(): array
    {
        $state = $this->tableFilters['state']['value'] ?? null;
        $erroringOnly = (bool) ($this->tableFilters['erroring']['isActive'] ?? false);

        return array_values(array_filter($this->addons, static function (array $addon) use ($state, $erroringOnly): bool {
            if ($state === 'enabled' && !$addon['enabled']) {
                return false;
            }

            if ($state === 'disabled' && $addon['enabled']) {
                return false;
            }

            return !$erroringOnly || $addon['recent'] > 0;
        }));
    }

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [AddonDiskUsageChart::class];
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('scan')
                ->label(fn (): string => $this->scanning ? 'Scanning…' : 'Scan disk usage')
                ->icon('tabler-ruler-measure')
                ->color('gray')
                ->disabled(fn (): bool => $this->scanning)
                ->requiresConfirmation()
                ->modalHeading('Measure what each addon uses on disk')
                ->modalDescription('The daemon cannot report the size of a folder, so this walks the addon tree one directory at a time. It runs a few addons at a time and shows its progress, so you can leave it or navigate away. The result is kept for a day.')
                ->modalSubmitActionLabel('Start scanning')
                ->action(fn () => $this->startScan()),
        ];
    }

    /**
     * Disk scanning is thousands of daemon requests, so it runs in steps driven by
     * the page rather than in one call that leaves the browser waiting on a spinner
     * with nothing to look at.
     */
    public function startScan(): void
    {
        $roots = app(DiskUsageService::class)->roots($this->server());

        if ($roots === []) {
            Notification::make()->title('No addon folders to measure.')->send();

            return;
        }

        $this->scanPending = $roots;
        $this->scanSizes = [];
        $this->scanRequests = 0;
        $this->scanPartial = false;
        $this->scanTotal = count($roots);
        $this->scanCurrent = (string) array_key_first($roots);
        $this->scanning = true;
    }

    /**
     * One step of the scan. Called repeatedly by the page while a scan is running.
     */
    public function scanStep(): void
    {
        if (!$this->scanning) {
            return;
        }

        $service = app(DiskUsageService::class);

        $chunk = $service->scanChunk(
            $this->server(),
            $this->scanPending,
            (int) config('gmod-toolkit.disk_scan_addons', 4),
        );

        foreach ($chunk['sizes'] as $name => $bytes) {
            $this->scanSizes[$name] = $bytes;
        }

        foreach ($chunk['done'] as $name) {
            unset($this->scanPending[$name]);
        }

        $this->scanRequests += $chunk['requests'];
        $this->scanPartial = $this->scanPartial || $chunk['partial'];

        // Nothing measured and nothing left to measure means we would loop forever.
        if ($chunk['done'] === [] && $this->scanPending !== []) {
            $this->scanPartial = true;
            $this->scanPending = [];
        }

        $this->scanCurrent = $this->scanPending === [] ? '' : (string) array_key_first($this->scanPending);

        if ($this->scanPending !== []) {
            return;
        }

        $result = $service->store($this->server(), $this->scanSizes, $this->scanRequests, $this->scanPartial);

        $this->scanning = false;
        $this->load();

        Notification::make()
            ->title($result['partial'] ? 'Scanned, but not all the way down' : 'Scan complete')
            ->body(sprintf(
                '%s across %d addons, in %d daemon requests.%s',
                Bytes::human($result['total']),
                $result['scanned'],
                $result['requests'],
                $result['partial']
                    ? ' Some folders were too deep to reach within the per-addon budget, so those are floors rather than totals - raise disk_scan_requests to go deeper.'
                    : '',
            ))
            ->status($result['partial'] ? 'warning' : 'success')
            ->send();
    }

    public function cancelScan(): void
    {
        $this->scanning = false;
        $this->scanPending = [];

        Notification::make()->title('Scan stopped. Nothing was saved.')->send();
    }

    public function scanProgress(): int
    {
        if ($this->scanTotal === 0) {
            return 0;
        }

        return (int) round((count($this->scanSizes) / $this->scanTotal) * 100);
    }

    public function fileUrl(string $path): string
    {
        return ListFiles::getUrl(['path' => trim($path, '/')]);
    }

    public function toggle(string $name, bool $enable): void
    {
        if (!$this->canWriteFiles()) {
            $this->denied();

            return;
        }

        $result = app(AddonService::class)->toggle($this->server(), $name, $enable);

        Notification::make()
            ->title($result['message'])
            ->status($result['ok'] ? 'success' : 'danger')
            ->send();

        $this->load();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     */
    private function toggleMany(Collection $records, bool $enable): void
    {
        if (!$this->canWriteFiles()) {
            $this->denied();

            return;
        }

        // Selecting everything and asking to enable it should not try to enable the
        // forty that are already enabled.
        $names = $records
            ->filter(fn (array $record): bool => $record['enabled'] !== $enable)
            ->pluck('name')
            ->values()
            ->all();

        if ($names === []) {
            Notification::make()
                ->title($enable ? 'Everything selected is already enabled.' : 'Everything selected is already disabled.')
                ->send();

            return;
        }

        $result = app(AddonService::class)->toggleMany($this->server(), $names, $enable);

        Notification::make()
            ->title($result['message'])
            ->status($result['ok'] ? 'success' : 'danger')
            ->send();

        $this->load();
    }

    private function denied(): void
    {
        Notification::make()
            ->title('You do not have permission to modify this server\'s files.')
            ->danger()
            ->send();
    }

    public static function getNavigationLabel(): string
    {
        return 'Addons';
    }

    public function getTitle(): string
    {
        return 'Installed addons';
    }

    public function getSubheading(): ?string
    {
        $enabled = count(array_filter($this->addons, fn (array $a): bool => $a['enabled']));
        $disabled = count($this->addons) - $enabled;
        $erroring = count(array_filter($this->addons, fn (array $a): bool => $a['recent'] > 0));

        $parts = ["{$enabled} enabled"];

        if ($disabled > 0) {
            $parts[] = "{$disabled} disabled";
        }

        if ($erroring > 0) {
            $parts[] = "{$erroring} erroring now";
        }

        return implode(' · ', $parts);
    }
}
