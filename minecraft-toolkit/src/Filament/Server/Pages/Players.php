<?php

namespace Catualus\MinecraftToolkit\Filament\Server\Pages;

use BackedEnum;
use Catualus\MinecraftToolkit\Filament\Server\Pages\Concerns\PaginatesArrays;
use Catualus\MinecraftToolkit\Services\PlayerListService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class Players extends MinecraftPage implements HasTable
{
    use InteractsWithTable;
    use PaginatesArrays;

    public const LISTS = [
        'ops' => ['label' => 'Operators', 'file' => 'ops.json', 'key' => 'uuid'],
        'whitelist' => ['label' => 'Whitelist', 'file' => 'whitelist.json', 'key' => 'uuid'],
        'banned-players' => ['label' => 'Banned players', 'file' => 'banned-players.json', 'key' => 'uuid'],
        'banned-ips' => ['label' => 'Banned IPs', 'file' => 'banned-ips.json', 'key' => 'ip'],
    ];

    protected static string|BackedEnum|null $navigationIcon = 'tabler-users';

    protected static ?int $navigationSort = 31;

    protected string $view = 'minecraft-toolkit::players';

    /** @var array<string, list<array<string, mixed>>> */
    public array $lists = [];

    public string $activeList = 'ops';

    public bool $writable = false;

    public function mount(): void
    {
        $this->writable = $this->canWriteFiles();
        $this->load();
    }

    public function load(): void
    {
        $service = app(PlayerListService::class);

        foreach (array_keys(self::LISTS) as $list) {
            $this->lists[$list] = $service->read($this->server(), $list);
        }
    }

    public function setList(string $list): void
    {
        if (!array_key_exists($list, self::LISTS)) {
            return;
        }

        $this->activeList = $list;

        // Filters and paging from the previous list mean nothing in the next one.
        $this->resetTable();
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return self::LISTS[$this->activeList];
    }

    public function count(string $list): int
    {
        return count($this->lists[$list] ?? []);
    }

    /**
     * Player heads are loaded by the browser from a third-party service, so whether
     * they appear at all is a deliberate choice rather than a default nobody sees.
     */
    public function avatarsEnabled(): bool
    {
        return (bool) config('minecraft-toolkit.player_avatars', true)
            && filled(config('minecraft-toolkit.player_avatar_url'));
    }

    public function table(Table $table): Table
    {
        $isIps = $this->activeList === 'banned-ips';
        $isBans = str_starts_with($this->activeList, 'banned');

        return $table
            ->records(fn (?string $search, ?string $sortColumn, ?string $sortDirection, int|string $page, int|string $recordsPerPage) => $this->arrayRecords(
                rows: $this->rows(),
                search: $search,
                sortColumn: $sortColumn,
                sortDirection: $sortDirection,
                page: $page,
                recordsPerPage: $recordsPerPage,
                searchable: ['label', 'identifier', 'reason'],
            ))
            ->searchable()
            ->searchPlaceholder($isIps ? 'Filter by IP' : 'Filter by name or UUID')
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Empty')
            ->emptyStateDescription(fn (): string => $this->meta()['file'] . ' has no entries.')
            ->emptyStateIcon('tabler-user-off')
            ->columns(array_values(array_filter([
                ($isIps || !$this->avatarsEnabled()) ? null : ImageColumn::make('avatar')
                    ->label('')
                    ->circular()
                    ->visibleFrom('sm')
                    ->extraImgAttributes(['loading' => 'lazy']),

                TextColumn::make('label')
                    ->label($isIps ? 'IP address' : 'Player')
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (array $record): ?string => $isIps ? null : $record['identifier']),

                $this->activeList === 'ops' ? TextColumn::make('level')
                    ->label('Level')
                    ->badge()
                    ->sortable()
                    ->color(fn ($state): string => (int) $state >= 4 ? 'danger' : 'gray')
                    ->tooltip('4 grants every command, including stop and op.') : null,

                $isBans ? TextColumn::make('reason')
                    ->label('Reason')
                    ->wrap()
                    ->lineClamp(2)
                    ->placeholder('—') : null,

                $isBans ? TextColumn::make('created')
                    ->label('Banned')
                    ->visibleFrom('lg')
                    ->placeholder('—') : null,
            ])))
            ->recordActions([
                Action::make('remove')
                    ->label('Remove')
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->authorize(fn (): bool => $this->canWriteFiles())
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => 'Remove ' . $record['label'] . '?')
                    ->modalDescription(fn (): string => 'Rewrites ' . $this->meta()['file'] . '. Run /reload or restart for the server to pick it up.')
                    ->action(fn (array $record) => $this->remove($record['identifier'])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('remove')
                        ->label('Remove selected')
                        ->icon('tabler-trash')
                        ->color('danger')
                        ->authorize(fn (): bool => $this->canWriteFiles())
                        ->requiresConfirmation()
                        ->modalDescription(fn (): string => 'Removes every selected entry from ' . $this->meta()['file'] . '.')
                        ->action(fn (Collection $records) => $this->removeMany($records))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /**
     * The active list flattened into table rows, so the four differently-shaped JSON
     * files can share one table definition.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $meta = $this->meta();
        $rows = [];

        foreach ($this->lists[$this->activeList] ?? [] as $entry) {
            $identifier = (string) ($entry[$meta['key']] ?? '');

            if ($identifier === '') {
                continue;
            }

            $uuid = $entry['uuid'] ?? null;

            $rows[] = [
                '__key' => $identifier,
                'label' => (string) ($entry['name'] ?? $entry['ip'] ?? $identifier),
                'identifier' => $identifier,
                // Heads are fetched by the browser from a third party, so this is
                // config-gated - see player_avatars in config/minecraft-toolkit.php.
                'avatar' => $this->avatarsEnabled() && is_string($uuid) && $uuid !== ''
                    ? str_replace('{uuid}', $uuid, (string) config('minecraft-toolkit.player_avatar_url'))
                    : null,
                'level' => $entry['level'] ?? null,
                'reason' => $entry['reason'] ?? null,
                'created' => $entry['created'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')
                ->label(fn (): string => $this->activeList === 'banned-ips' ? 'Ban an IP' : 'Add a player')
                ->icon('tabler-plus')
                ->authorize(fn (): bool => $this->writable)
                ->modalHeading(fn (): string => 'Add to ' . $this->meta()['label'])
                ->modalDescription(fn (): string => $this->activeList === 'banned-ips'
                    ? 'Written straight to banned-ips.json.'
                    : 'The UUID is looked up from Mojang first. Minecraft matches these files on UUID, so an entry with only a name is silently ignored by the server.')
                ->schema(fn (): array => array_values(array_filter([
                    TextInput::make('name')
                        ->label($this->activeList === 'banned-ips' ? 'IP address' : 'Minecraft username')
                        ->required()
                        ->autocomplete(false),

                    $this->activeList === 'ops' ? Select::make('level')
                        ->label('Operator level')
                        ->default(4)
                        ->selectablePlaceholder(false)
                        ->options([
                            1 => '1 — bypass spawn protection',
                            2 => '2 — singleplayer cheat commands',
                            3 => '3 — kick, ban, op other players',
                            4 => '4 — everything, including stop',
                        ]) : null,
                ])))
                ->modalSubmitActionLabel('Add')
                ->action(fn (array $data) => $this->add((string) $data['name'], (int) ($data['level'] ?? 4))),
        ];
    }

    public function add(string $name, int $level): void
    {
        if (!$this->canWriteFiles()) {
            $this->denied();

            return;
        }

        $result = app(PlayerListService::class)->add($this->server(), $this->activeList, $name, $level);

        Notification::make()
            ->title($result['message'])
            ->status($result['ok'] ? 'success' : 'danger')
            ->send();

        $this->load();
    }

    public function remove(string $identifier): void
    {
        if (!$this->canWriteFiles()) {
            $this->denied();

            return;
        }

        $result = app(PlayerListService::class)->remove($this->server(), $this->activeList, $identifier);

        Notification::make()
            ->title($result['message'])
            ->status($result['ok'] ? 'success' : 'danger')
            ->send();

        $this->load();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     */
    private function removeMany(Collection $records): void
    {
        if (!$this->canWriteFiles()) {
            $this->denied();

            return;
        }

        $result = app(PlayerListService::class)->removeMany(
            $this->server(),
            $this->activeList,
            $records->pluck('identifier')->values()->all(),
        );

        Notification::make()
            ->title($result['message'])
            ->status($result['ok'] ? 'success' : 'danger')
            ->send();

        $this->load();
    }

    public static function getNavigationLabel(): string
    {
        return 'Players';
    }

    public function getTitle(): string
    {
        return 'Players';
    }

    public function getSubheading(): ?string
    {
        return implode(' · ', array_map(
            fn (string $list): string => self::LISTS[$list]['label'] . ' ' . $this->count($list),
            array_keys(self::LISTS),
        ));
    }
}
