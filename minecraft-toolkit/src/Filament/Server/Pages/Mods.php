<?php

namespace Catualus\MinecraftToolkit\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Filament\Server\Resources\Files\Pages\EditFiles;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use BackedEnum;
use Catualus\MinecraftToolkit\Filament\Server\Pages\Concerns\PaginatesArrays;
use Catualus\MinecraftToolkit\Services\CrashReportService;
use Catualus\MinecraftToolkit\Services\ModService;
use Catualus\MinecraftToolkit\Support\Bytes;
use Catualus\MinecraftToolkit\Support\Minecraft;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Throwable;

class Mods extends MinecraftPage implements HasTable
{
    use InteractsWithTable;
    use PaginatesArrays;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-packages';

    protected static ?int $navigationSort = 32;

    protected string $view = 'minecraft-toolkit::mods';

    /** @var list<array<string, mixed>> */
    public array $items = [];

    /** @var list<array<string, mixed>> */
    public array $crashes = [];

    public bool $writable = false;

    public bool $restartable = false;

    /** Set once a toggle lands, so the restart prompt only appears when it is needed. */
    public bool $pendingRestart = false;

    public ?string $flavour = null;

    public function mount(): void
    {
        $this->writable = $this->canWriteFiles();
        $this->restartable = (bool) user()?->can(SubuserPermission::ControlRestart, $this->server());
        $this->load();
    }

    public function load(): void
    {
        $server = $this->server();

        $this->flavour = app(Minecraft::class)->flavour($server);
        $this->items = app(ModService::class)->all($server);
        $this->crashes = app(CrashReportService::class)->recent($server);
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
                searchable: ['name', 'file'],
            ))
            ->searchable()
            ->searchPlaceholder('Filter by name or filename')
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading($this->flavour === null ? 'No mods or plugins directory' : 'Nothing installed')
            ->emptyStateDescription($this->flavour === null
                ? 'Neither a mods nor a plugins directory exists. If the server has not finished installing, try again once it has.'
                : 'The ' . $this->flavour . ' directory is empty.')
            ->emptyStateIcon('tabler-package-off')
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->sortable()
                    ->weight('medium')
                    ->wrap()
                    ->description(fn (array $record): string => $record['file'])
                    ->color(fn (array $record): ?string => $record['enabled'] ? null : 'gray'),

                TextColumn::make('enabled')
                    ->label('State')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => $state ? 'Enabled' : 'Disabled')
                    ->color(fn ($state): string => $state ? 'success' : 'gray'),

                TextColumn::make('size')
                    ->label('Size')
                    ->alignEnd()
                    ->sortable()
                    ->visibleFrom('md')
                    ->formatStateUsing(fn ($state): string => $state > 0 ? Bytes::human((int) $state) : '—'),

                TextColumn::make('modified')
                    ->label('Modified')
                    ->sortable()
                    ->visibleFrom('lg')
                    ->formatStateUsing(fn ($state): string => $state?->diffForHumans() ?? '—')
                    ->tooltip(fn (array $record): ?string => $record['modified']?->toDayDateTimeString()),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('State')
                    ->options(['enabled' => 'Enabled', 'disabled' => 'Disabled']),
            ])
            ->recordActions([
                Action::make('toggle')
                    ->label(fn (array $record): string => $record['enabled'] ? 'Disable' : 'Enable')
                    ->icon(fn (array $record): string => $record['enabled'] ? 'tabler-plug-off' : 'tabler-plug')
                    ->color(fn (array $record): string => $record['enabled'] ? 'gray' : 'primary')
                    ->authorize(fn (): bool => $this->canWriteFiles())
                    ->action(fn (array $record) => $this->toggle($record['file'], !$record['enabled'])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('enable')
                        ->label('Enable selected')
                        ->icon('tabler-plug')
                        ->authorize(fn (): bool => $this->canWriteFiles())
                        ->requiresConfirmation()
                        ->modalDescription('Renames each selected file back from .jar.disabled to .jar. A restart is needed before the server loads them.')
                        ->action(fn (Collection $records) => $this->toggleMany($records, true))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('disable')
                        ->label('Disable selected')
                        ->icon('tabler-plug-off')
                        ->color('danger')
                        ->authorize(fn (): bool => $this->canWriteFiles())
                        ->requiresConfirmation()
                        ->modalDescription('Renames each selected file to .jar.disabled. Nothing is deleted, and enabling puts the name back.')
                        ->action(fn (Collection $records) => $this->toggleMany($records, false))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filtered(): array
    {
        $state = $this->tableFilters['state']['value'] ?? null;

        if ($state === null || $state === '') {
            return $this->items;
        }

        return array_values(array_filter(
            $this->items,
            static fn (array $item): bool => $item['enabled'] === ($state === 'enabled'),
        ));
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('restart')
                ->label('Restart to apply')
                ->icon('tabler-refresh')
                ->color('warning')
                ->authorize(fn (): bool => $this->restartable)
                ->visible(fn (): bool => $this->pendingRestart)
                ->requiresConfirmation()
                ->modalHeading('Restart the server now?')
                ->modalDescription('Enabling and disabling only rename files on disk. The server reads that directory at startup, so nothing you changed is in effect until it restarts.')
                ->action(fn () => $this->restart()),

            Action::make('crashes')
                ->label(fn (): string => 'Crash reports (' . count($this->crashes) . ')')
                ->icon('tabler-alert-triangle')
                ->color('danger')
                ->visible(fn (): bool => $this->crashes !== [])
                ->modalHeading('Recent crash reports')
                ->modalDescription('Newest first. A crash is nearly always one mod - find the culprit in the report, then disable it in the table.')
                ->modalContent(fn () => view('minecraft-toolkit::partials.crash-reports', [
                    'crashes' => $this->crashes,
                    'folderUrl' => ListFiles::getUrl(['path' => 'crash-reports']),
                    'fileUrl' => fn (string $path): string => EditFiles::getUrl(['path' => $path]),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),
        ];
    }

    public function restart(): void
    {
        if (!$this->restartable) {
            Notification::make()
                ->title('You do not have permission to restart this server.')
                ->danger()
                ->send();

            return;
        }

        try {
            app(DaemonServerRepository::class)->setServer($this->server())->power('restart');
        } catch (Throwable $e) {
            Notification::make()->title('Could not restart: ' . $e->getMessage())->danger()->send();

            return;
        }

        $this->pendingRestart = false;

        Notification::make()->title('Restarting the server.')->success()->send();
    }

    public function toggle(string $file, bool $enable): void
    {
        if (!$this->canWriteFiles()) {
            $this->denied();

            return;
        }

        $result = app(ModService::class)->toggle($this->server(), $file, $enable);

        Notification::make()
            ->title($result['message'])
            ->status($result['ok'] ? 'success' : 'danger')
            ->send();

        $this->pendingRestart = $this->pendingRestart || $result['ok'];

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

        // Enabling a selection that is already enabled should be a no-op, not forty
        // pointless rename calls to the daemon.
        $files = $records
            ->filter(fn (array $record): bool => $record['enabled'] !== $enable)
            ->pluck('file')
            ->values()
            ->all();

        if ($files === []) {
            Notification::make()
                ->title($enable ? 'Everything selected is already enabled.' : 'Everything selected is already disabled.')
                ->send();

            return;
        }

        $result = app(ModService::class)->toggleMany($this->server(), $files, $enable);

        Notification::make()
            ->title($result['message'])
            ->status($result['ok'] ? 'success' : 'danger')
            ->send();

        $this->pendingRestart = $this->pendingRestart || $result['ok'];

        $this->load();
    }

    public static function getNavigationLabel(): string
    {
        $server = Filament::getTenant();

        return $server instanceof Server && app(Minecraft::class)->flavour($server) === Minecraft::FLAVOUR_PLUGINS
            ? 'Plugins'
            : 'Mods';
    }

    public function getTitle(): string
    {
        return $this->flavour === Minecraft::FLAVOUR_PLUGINS ? 'Plugins' : 'Mods';
    }

    public function getSubheading(): ?string
    {
        if ($this->items === []) {
            return null;
        }

        $enabled = count(array_filter($this->items, fn (array $i): bool => $i['enabled']));
        $disabled = count($this->items) - $enabled;
        $size = array_sum(array_column($this->items, 'size'));

        $parts = ["{$enabled} enabled"];

        if ($disabled > 0) {
            $parts[] = "{$disabled} disabled";
        }

        $parts[] = Bytes::human($size) . ' on disk';

        return implode(' · ', $parts);
    }
}
