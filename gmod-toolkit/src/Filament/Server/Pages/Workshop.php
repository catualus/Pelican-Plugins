<?php

namespace Catualus\GmodToolkit\Filament\Server\Pages;

use BackedEnum;
use Catualus\GmodToolkit\Filament\Server\Pages\Concerns\PaginatesArrays;
use Catualus\GmodToolkit\Filament\Server\Widgets\CollectionCompositionChart;
use Catualus\GmodToolkit\Filament\Server\Widgets\CollectionFreshnessChart;
use Catualus\GmodToolkit\Filament\Server\Widgets\CollectionSizeChart;
use Catualus\GmodToolkit\Services\WorkshopService;
use Catualus\GmodToolkit\Support\Bytes;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class Workshop extends GmodPage implements HasTable
{
    use InteractsWithTable;
    use PaginatesArrays;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-cloud-download';

    protected static ?int $navigationSort = 22;

    protected string $view = 'gmod-toolkit::workshop';

    public ?string $collectionId = null;

    /** @var list<array<string, mixed>> */
    public array $addons = [];

    /** @var array<string, mixed> */
    public array $summary = [];

    public int $recentDays = 7;

    public function mount(): void
    {
        $workshop = app(WorkshopService::class);
        $server = $this->server();

        $this->recentDays = (int) config('gmod-toolkit.recent_update_days', 7);
        $this->collectionId = $workshop->collectionId($server);
        $this->addons = $this->collectionId === null ? [] : $workshop->addons($this->collectionId);
        $this->summary = $workshop->summarise($this->addons, $this->recentDays);
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
                searchable: ['title', 'id'],
            ))
            ->searchable()
            ->searchPlaceholder('Filter by title or id')
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading($this->collectionId === null ? 'No collection set' : 'Nothing to show')
            ->emptyStateDescription($this->collectionId === null
                ? 'This server has no WORKSHOP_ID startup variable. Set one on the Startup page and the collection will be listed here.'
                : 'The collection returned no addons. Either it is empty, it is not a collection, or Steam could not be reached - this page retries on the next load.')
            ->emptyStateIcon('tabler-cloud-off')
            ->columns([
                TextColumn::make('title')
                    ->label('Addon')
                    ->sortable()
                    ->weight('medium')
                    ->wrap()
                    ->description(fn (array $record): string => 'id ' . $record['id'])
                    ->url(fn (array $record): string => $record['url'], shouldOpenInNewTab: true),

                TextColumn::make('flags')
                    ->label('')
                    ->badge()
                    ->state(fn (array $record): array => $this->flags($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Banned' => 'danger',
                        'Not a GMod item' => 'danger',
                        'Updated recently' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('size')
                    ->label('Size')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => $state > 0 ? Bytes::human((int) $state) : '—')
                    // Share of the whole download, which is the number that decides
                    // whether an entry is worth removing.
                    ->description(fn (array $record): ?string => $this->share($record['size']), position: 'below'),

                TextColumn::make('updated')
                    ->label('Updated')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => $state === null
                        ? '—'
                        : Carbon::createFromTimestamp((int) $state)->diffForHumans())
                    ->tooltip(fn (array $record): ?string => $record['updated'] === null
                        ? null
                        : Carbon::createFromTimestamp((int) $record['updated'])->toDayDateTimeString()),

                TextColumn::make('subscriptions')
                    ->label('Subscribers')
                    ->alignEnd()
                    ->sortable()
                    ->visibleFrom('lg')
                    ->formatStateUsing(fn ($state): string => number_format((int) $state)),
            ])
            ->defaultSort('size', 'desc')
            ->filters([
                Filter::make('recent')->label("Updated in the last {$this->recentDays} days"),
                Filter::make('problems')->label('Banned or wrong game'),
                SelectFilter::make('bracket')
                    ->label('Size')
                    ->options([
                        'large' => 'Over 100 MB',
                        'medium' => '10 - 100 MB',
                        'small' => 'Under 10 MB',
                    ]),
            ])
            ->recordActions([
                Action::make('steam')
                    ->label('Steam')
                    ->icon('tabler-external-link')
                    ->color('gray')
                    ->url(fn (array $record): string => $record['url'], shouldOpenInNewTab: true),
            ]);
    }

    /**
     * An addon's share of the whole download.
     *
     * A small addon in a big collection rounds to 0.0%, and "0% of the download" reads
     * as a bug rather than as "negligible" - so anything that would round to nothing
     * is reported as being under the smallest figure this can show.
     */
    private function share(int $size): ?string
    {
        $total = (int) ($this->summary['size'] ?? 0);

        if ($total <= 0 || $size <= 0) {
            return null;
        }

        $percent = $size / $total * 100;

        if ($percent < 0.1) {
            return 'under 0.1% of the download';
        }

        return round($percent, 1) . '% of the download';
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function flags(array $record): array
    {
        $flags = [];

        if ($record['banned']) {
            $flags[] = 'Banned';
        }

        // An item from another game will never mount on a Garry's Mod server, and
        // the Steam collection page gives no hint that it is in there.
        if ($record['foreign']) {
            $flags[] = 'Not a GMod item';
        }

        if (!$record['banned'] && $this->isRecent($record['updated'])) {
            $flags[] = 'Updated recently';
        }

        return $flags;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filtered(): array
    {
        $recent = (bool) ($this->tableFilters['recent']['isActive'] ?? false);
        $problems = (bool) ($this->tableFilters['problems']['isActive'] ?? false);
        $bracket = $this->tableFilters['bracket']['value'] ?? null;

        return array_values(array_filter($this->addons, function (array $addon) use ($recent, $problems, $bracket): bool {
            if ($recent && !$this->isRecent($addon['updated'])) {
                return false;
            }

            if ($problems && !$addon['banned'] && !$addon['foreign']) {
                return false;
            }

            return match ($bracket) {
                'large' => $addon['size'] > 104857600,
                'medium' => $addon['size'] > 10485760 && $addon['size'] <= 104857600,
                'small' => $addon['size'] <= 10485760,
                default => true,
            };
        }));
    }

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            CollectionSizeChart::class,
            CollectionCompositionChart::class,
            CollectionFreshnessChart::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 2;
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')
                ->label('Open on Steam')
                ->icon('tabler-external-link')
                ->color('gray')
                ->visible(fn (): bool => $this->collectionId !== null)
                ->url(fn (): string => $this->collectionUrl() ?? '#', shouldOpenInNewTab: true),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return 'Workshop';
    }

    public function getTitle(): string
    {
        return 'Workshop collection';
    }

    public function getSubheading(): ?string
    {
        if ($this->collectionId === null || $this->addons === []) {
            return null;
        }

        $mbps = (float) config('gmod-toolkit.client_download_mbps', 50);
        $wait = Bytes::humanDuration(Bytes::downloadSeconds($this->summary['size'], $mbps));

        return sprintf(
            '%d addons · %s · a new player waits about %s at %g Mbps',
            $this->summary['count'],
            Bytes::human($this->summary['size']),
            $wait,
            $mbps,
        );
    }

    public function collectionUrl(): ?string
    {
        return $this->collectionId === null
            ? null
            : 'https://steamcommunity.com/sharedfiles/filedetails/?id=' . $this->collectionId;
    }

    public function isRecent(?int $timestamp): bool
    {
        return $timestamp !== null && $timestamp >= now()->subDays($this->recentDays)->getTimestamp();
    }
}
