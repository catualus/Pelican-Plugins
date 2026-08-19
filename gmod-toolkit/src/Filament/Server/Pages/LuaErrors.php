<?php

namespace Catualus\GmodToolkit\Filament\Server\Pages;

use App\Filament\Server\Resources\Files\Pages\EditFiles;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use BackedEnum;
use Catualus\GmodToolkit\Filament\Server\Pages\Concerns\PaginatesArrays;
use Catualus\GmodToolkit\Filament\Server\Widgets\ErrorsByAddonChart;
use Catualus\GmodToolkit\Filament\Server\Widgets\ErrorTimelineChart;
use Catualus\GmodToolkit\Services\LuaErrorService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LuaErrors extends GmodPage implements HasTable
{
    use InteractsWithTable;
    use PaginatesArrays;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-alert-triangle';

    protected static ?int $navigationSort = 23;

    protected string $view = 'gmod-toolkit::lua-errors';

    /** @var list<array<string, mixed>> */
    public array $sources = [];

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public bool $writable = false;

    public function mount(): void
    {
        $this->writable = $this->canWriteFiles();
        $this->load();
    }

    public function load(): void
    {
        $service = app(LuaErrorService::class);

        $this->sources = $service->sources($this->server());
        $this->rows = $this->buildRows();
    }

    /**
     * One row per addon per source, so an addon erroring on both the server and the
     * clients is two rows rather than one merged number that hides where to look.
     *
     * @return list<array<string, mixed>>
     */
    private function buildRows(): array
    {
        $rows = [];

        foreach ($this->sources as $source) {
            foreach ($source['groups'] as $group) {
                $rows[] = [
                    '__key' => $source['key'] . ':' . mb_strtolower($group['addon']),
                    'addon' => $group['addon'],
                    'source' => $source['label'],
                    'source_key' => $source['key'],
                    'path' => $group['path'],
                    'count' => $group['count'],
                    'recent' => $group['recent'],
                    'reporters' => $group['reporters'],
                    'message' => $group['message'],
                    'location' => $group['location'],
                    'variants' => $group['variants'],
                    // These logs have no timestamps, so "how long ago" can only be
                    // expressed in errors: how many were written after this one.
                    'ago' => max(0, $source['total'] - 1 - $group['last_index']),
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$b['recent'], $b['count']] <=> [$a['recent'], $a['count']]);

        return $rows;
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
                searchable: ['addon', 'message', 'location'],
            ))
            ->searchable()
            ->searchPlaceholder('Filter by addon or message')
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No Lua errors')
            ->emptyStateDescription('Either nothing has been reported, or neither log is being written. Server-side errors need -condebug on the startup command.')
            ->emptyStateIcon('tabler-mood-smile')
            ->columns([
                TextColumn::make('addon')
                    ->label('Addon')
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (array $record): string => $record['source'])
                    ->url(fn (array $record): ?string => $record['path'] === null
                        ? null
                        : ListFiles::getUrl(['path' => 'garrysmod/' . trim($record['path'], '/')])),

                TextColumn::make('count')
                    ->label('Errors')
                    ->alignEnd()
                    ->sortable()
                    ->badge()
                    ->color(fn (array $record): string => $record['recent'] > 0 ? 'danger' : 'gray')
                    ->formatStateUsing(fn ($state): string => number_format((int) $state)),

                TextColumn::make('recent')
                    ->label('Last seen')
                    ->sortable()
                    ->badge()
                    ->color(fn (array $record): string => $record['recent'] > 0 ? 'warning' : 'gray')
                    ->formatStateUsing(fn ($state, array $record): string => $state > 0
                        ? 'Still happening'
                        : number_format($record['ago']) . ' errors ago')
                    ->tooltip(fn (array $record): ?string => $record['recent'] > 0
                        ? number_format($record['recent']) . ' of its errors are in the recent window'
                        : 'No occurrences in the recent window - history, not a live problem'),

                TextColumn::make('reporters')
                    ->label('Players')
                    ->alignEnd()
                    ->sortable()
                    ->visibleFrom('lg')
                    ->formatStateUsing(fn ($state): string => $state > 0 ? number_format((int) $state) : '—')
                    ->tooltip('Distinct players whose client reported this. Server-side errors have no reporter.'),

                TextColumn::make('message')
                    ->label('Most recent message')
                    ->wrap()
                    ->lineClamp(2)
                    ->description(fn (array $record): ?string => $record['location'])
                    ->tooltip(fn (array $record): ?string => count($record['variants']) > 1
                        ? count($record['variants']) . ' distinct messages - open the row for all of them'
                        : null),
            ])
            ->filters([
                Filter::make('active')->label('Still happening only'),
                SelectFilter::make('source')
                    ->label('Log')
                    ->options(collect($this->sources)->pluck('label', 'key')->all()),
            ])
            ->recordActions([
                Action::make('detail')
                    ->label('Messages')
                    ->icon('tabler-list-search')
                    ->color('gray')
                    ->modalHeading(fn (array $record): string => $record['addon'])
                    ->modalDescription(fn (array $record): string => sprintf(
                        '%s distinct %s across %s occurrences in the %s log.',
                        number_format(count($record['variants'])),
                        count($record['variants']) === 1 ? 'message' : 'messages',
                        number_format($record['count']),
                        mb_strtolower($record['source']),
                    ))
                    ->modalContent(fn (array $record) => view('gmod-toolkit::partials.error-variants', [
                        'variants' => $record['variants'],
                        'editUrl' => fn (?string $location): ?string => $this->editUrl($location),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),

                Action::make('edit')
                    ->label('Open file')
                    ->icon('tabler-file-code')
                    ->color('gray')
                    ->visible(fn (array $record): bool => $this->editUrl($record['location']) !== null)
                    ->url(fn (array $record): string => (string) $this->editUrl($record['location'])),
            ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filtered(): array
    {
        $activeOnly = (bool) ($this->tableFilters['active']['isActive'] ?? false);
        $source = $this->tableFilters['source']['value'] ?? null;

        return array_values(array_filter($this->rows, static function (array $row) use ($activeOnly, $source): bool {
            if ($activeOnly && $row['recent'] === 0) {
                return false;
            }

            return $source === null || $source === '' || $row['source_key'] === $source;
        }));
    }

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            ErrorTimelineChart::class,
            ErrorsByAddonChart::class,
        ];
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $actions = [];

        // One action per log rather than a dropdown per log: with two logs that is at
        // most four buttons, and each says exactly which file it will touch - which
        // matters more than tidiness for something that empties a file.
        foreach ($this->sources as $source) {
            if (!$source['present'] || $source['total'] === 0) {
                continue;
            }

            $key = $source['key'];
            $label = mb_strtolower($source['label']);
            $path = $source['path'];

            $actions[] = Action::make('download_' . $key)
                ->label("Download {$label} log")
                ->icon('tabler-download')
                ->color('gray')
                ->action(fn () => $this->download($key));

            $actions[] = Action::make('clear_' . $key)
                ->label("Clear {$label} log")
                ->icon('tabler-trash')
                ->color('danger')
                ->authorize(fn (): bool => $this->canWriteFiles())
                ->requiresConfirmation()
                ->modalHeading("Empty {$path}?")
                // Truncating is the only way to tell a stale error from a live one
                // here: the logs carry no timestamps, so a clean slate is what makes
                // the next report meaningful.
                ->modalDescription('Past errors are lost and everything from now on starts from a clean slate. Since these logs have no timestamps, that is the only reliable way to tell a fixed problem from a live one. Download it first if you want to keep it.')
                ->modalSubmitActionLabel('Clear it')
                ->action(fn () => $this->clear($key));
        }

        return $actions;
    }

    public function download(string $key): ?StreamedResponse
    {
        $contents = app(LuaErrorService::class)->contents($this->server(), $key);

        if ($contents === null) {
            Notification::make()->title('Could not read that log.')->danger()->send();

            return null;
        }

        $name = sprintf('gmod-%s-errors-%s.log', $key, now()->format('Ymd-His'));

        return response()->streamDownload(fn () => print $contents, $name, [
            'Content-Type' => 'text/plain',
        ]);
    }

    public function clear(string $key): void
    {
        if (!$this->canWriteFiles()) {
            Notification::make()
                ->title('You do not have permission to modify this server\'s files.')
                ->danger()
                ->send();

            return;
        }

        $cleared = app(LuaErrorService::class)->clear($this->server(), $key);

        Notification::make()
            ->title($cleared ? 'Log cleared.' : 'Could not clear the log.')
            ->status($cleared ? 'success' : 'danger')
            ->send();

        $this->load();
    }

    /**
     * Opens the failing file itself in the editor rather than its folder. The parser
     * already captures "addons/x/lua/y.lua:42", so the line number is stripped and
     * the rest is handed to the editor - one click from an error to the code.
     */
    public function editUrl(?string $location): ?string
    {
        if ($location === null) {
            return null;
        }

        $file = preg_replace('/:\d+$/', '', str_replace('\\', '/', $location));

        if (!is_string($file) || !str_ends_with($file, '.lua')) {
            return null;
        }

        return EditFiles::getUrl(['path' => 'garrysmod/' . ltrim($file, '/')]);
    }

    public static function getNavigationLabel(): string
    {
        return 'Lua errors';
    }

    public function getTitle(): string
    {
        return 'Lua errors';
    }

    public function getSubheading(): ?string
    {
        $total = array_sum(array_column($this->sources, 'total'));

        if ($total === 0) {
            return null;
        }

        $active = count(array_filter($this->rows, static fn (array $row): bool => $row['recent'] > 0));

        return sprintf(
            '%s errors across %d addons · %d still happening',
            number_format($total),
            count(array_unique(array_column($this->rows, 'addon'))),
            $active,
        );
    }
}
