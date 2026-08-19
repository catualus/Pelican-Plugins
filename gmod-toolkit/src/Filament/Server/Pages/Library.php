<?php

namespace Catualus\GmodToolkit\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use BackedEnum;
use Catualus\GmodToolkit\Filament\Server\Pages\Concerns\PaginatesArrays;
use Catualus\GmodToolkit\Services\AddonCatalogue;
use Catualus\GmodToolkit\Services\AddonInstaller;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * A curated set of addons the panel can put on the server for you.
 *
 * The mechanism is the daemon's own remote-download and decompress endpoints, so no
 * shell access is needed - see AddonInstaller. The catalogue is fixed in config and
 * the interface offers no way to type a URL, which matters because this writes third
 * party code into a running game server's addons folder.
 */
class Library extends GmodPage implements HasTable
{
    use InteractsWithTable;
    use PaginatesArrays;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-books';

    protected static ?int $navigationSort = 24;

    protected string $view = 'gmod-toolkit::library';

    /** @var list<array<string, mixed>> */
    public array $entries = [];

    /** @var list<array<string, mixed>> */
    public array $presets = [];

    /** @var array<string, string|null> latest known head sha per repo, once checked */
    public array $heads = [];

    public bool $checkedUpdates = false;

    /** Install run state, kept here so each step picks up where the last left off. */
    public bool $installing = false;

    public bool $installUpdate = false;

    /** @var list<string> ids still to install */
    public array $installQueue = [];

    /** @var array<string, mixed> the addon currently being installed */
    public array $installPlan = [];

    /** @var list<string> a running commentary, shown while it works */
    public array $installLog = [];

    /** @var list<string> */
    public array $installDone = [];

    /** @var list<string> */
    public array $installFailures = [];

    public int $installTotal = 0;

    public int $installLoggedUpTo = 0;

    public function mount(): void
    {
        $this->load();
        $this->refreshHeads();
    }

    public function load(): void
    {
        $catalogue = app(AddonCatalogue::class);

        $catalogue->forgetManifest($this->server());

        $this->entries = $catalogue->all($this->server());
        $this->presets = $catalogue->presets($this->server());
    }

    /**
     * Looks up the current commit for each installed addon so the table can say
     * whether it is up to date.
     *
     * This is one request to GitHub per installed addon, which is why it is bounded
     * and cached for hours - but not doing it at all meant the status column could
     * only ever say "installed", which is not the question anyone is asking. A cold
     * cache costs a few requests once; every load after that is free, and a failure
     * degrades to "unknown" rather than to a wrong answer.
     */
    public function refreshHeads(bool $force = false): void
    {
        $catalogue = app(AddonCatalogue::class);
        $budget = max(0, (int) config('gmod-toolkit.github_lookups_per_load', 12));

        foreach ($this->entries as $entry) {
            if ($budget <= 0) {
                break;
            }

            if (!$entry['installed'] || $entry['repo'] === null || $entry['installed_sha'] === null) {
                continue;
            }

            if (!$force && array_key_exists($entry['repo'], $this->heads)) {
                continue;
            }

            $this->heads[$entry['repo']] = $catalogue->headSha($entry['repo'], $entry['ref']);
            $budget--;
        }

        $this->checkedUpdates = true;
    }

    /**
     * Whether the panel may write addons here at all.
     *
     * Installing is three separate daemon operations - download, extract, rename - and
     * each maps to a different subuser permission, so all three are required rather
     * than failing half way through with a folder full of zip.
     */
    public function canInstall(): bool
    {
        $server = $this->server();
        $user = user();

        return $user !== null
            && $user->can(SubuserPermission::FileCreate, $server)
            && $user->can(SubuserPermission::FileArchive, $server)
            && $user->can(SubuserPermission::FileUpdate, $server);
    }

    /**
     * Deleting a folder is a different permission from writing one, and the panel
     * has a separate one for it - so uninstalling asks for that rather than assuming
     * whoever can install can also remove.
     */
    public function canDelete(): bool
    {
        return (bool) user()?->can(SubuserPermission::FileDelete, $this->server());
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function uninstallWarning(array $record): string
    {
        $lines = ['The folder and everything in it is deleted. This cannot be undone.'];

        // Some entries exist precisely to hold the server owner's own configuration -
        // deleting one of those throws away work rather than just an addon.
        if ($record['holds_config']) {
            $lines[] = "{$record['name']} is where your own configuration lives, so anything you have written in there goes with it. Take a backup first if you are not certain.";
        } else {
            $lines[] = 'Anything you edited inside that folder goes with it.';
        }

        if ($record['enabled'] === false) {
            $lines[] = 'It is currently disabled, so it is not loaded either way.';
        } else {
            $lines[] = 'To stop it loading without losing it, disable it on the Addons page instead - that moves it to addons/__disabled and is reversible.';
        }

        return implode(' ', $lines);
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
                searchable: ['name', 'description', 'repo'],
            ))
            ->searchable()
            ->searchPlaceholder('Filter by name or repository')
            ->paginated([25, 50, 'all'])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Nothing in the catalogue')
            ->emptyStateDescription('The catalogue is defined in config/gmod-toolkit.php. An empty list means every entry was filtered out, or the config was emptied.')
            ->emptyStateIcon('tabler-books-off')
            ->columns([
                TextColumn::make('name')
                    ->label('Addon')
                    ->sortable()
                    ->weight('medium')
                    ->wrap()
                    ->description(fn (array $record): string => $record['description']),

                TextColumn::make('category')
                    ->label('Category')
                    ->sortable()
                    ->badge()
                    ->color('gray')
                    ->visibleFrom('md'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (array $record): string => $this->status($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Missing' => 'danger',
                        'Update available' => 'warning',
                        'Up to date', 'Installed' => 'success',
                        'Disabled', 'Not installable' => 'gray',
                        default => 'primary',
                    })
                    ->description(fn (array $record): ?string => $this->provenance($record)),

                TextColumn::make('repo')
                    ->label('Source')
                    ->visibleFrom('lg')
                    ->formatStateUsing(fn ($state, array $record): string => $state ?? ($record['workshop_id'] ? 'Workshop only' : '—'))
                    ->description(fn (array $record): ?string => $record['repo'] ? 'branch ' . $record['ref'] : null)
                    ->url(fn (array $record): ?string => $record['url'], shouldOpenInNewTab: true),
            ])
            ->filters([
                Filter::make('available')->label('Not installed only'),
                SelectFilter::make('category')
                    ->label('Category')
                    ->options(fn (): array => collect($this->entries)
                        ->pluck('category', 'category')
                        ->sort()
                        ->all()),
            ])
            ->recordActions([
                Action::make('install')
                    ->label('Install')
                    ->icon('tabler-download')
                    ->visible(fn (array $record): bool => $record['installable'] && !$record['installed'])
                    ->authorize(fn (): bool => $this->canInstall())
                    ->disabled(fn (): bool => $this->installing)
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => 'Install ' . $record['name'])
                    ->modalDescription(fn (array $record): string => $this->installSummary($record))
                    ->modalSubmitActionLabel('Download and install')
                    ->action(fn (array $record) => $this->install($record['id'])),

                Action::make('update')
                    ->label('Update')
                    ->icon('tabler-refresh')
                    ->color('warning')
                    ->visible(fn (array $record): bool => $record['installable'] && $record['installed'])
                    ->authorize(fn (): bool => $this->canInstall())
                    ->disabled(fn (): bool => $this->installing)
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => 'Re-download ' . $record['name'])
                    ->modalDescription(fn (array $record): string => sprintf(
                        'Downloads %s@%s again and replaces %s. The existing folder is kept until the new one is in place, so a failed download leaves the working copy alone. Anything you edited inside that folder is lost.',
                        $record['repo'],
                        $record['ref'],
                        'garrysmod/' . $record['target'] . '/' . $record['installed_folder'],
                    ))
                    ->modalSubmitActionLabel('Replace it')
                    ->action(fn (array $record) => $this->update($record['id'])),

                Action::make('folder')
                    ->label('Open folder')
                    ->icon('tabler-folder')
                    ->color('gray')
                    ->visible(fn (array $record): bool => $record['installed'])
                    ->url(fn (array $record): string => ListFiles::getUrl([
                        'path' => (string) $record['installed_path'],
                    ])),

                // Only offered when the folder is genuinely on disk. An entry whose
                // record says installed but whose folder is gone gets "Forget" instead:
                // that one is a stale record, and the folder may well have been moved
                // or removed deliberately.
                Action::make('uninstall')
                    ->label('Uninstall')
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->visible(fn (array $record): bool => $record['installed'])
                    ->authorize(fn (): bool => $this->canDelete())
                    ->disabled(fn (): bool => $this->installing)
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => 'Delete ' . $record['installed_path'] . '?')
                    ->modalDescription(fn (array $record): string => $this->uninstallWarning($record))
                    ->modalSubmitActionLabel('Delete it')
                    ->action(fn (array $record) => $this->uninstall($record['id'])),

                Action::make('forget')
                    ->label('Forget')
                    ->icon('tabler-eraser')
                    ->color('gray')
                    ->visible(fn (array $record): bool => !$record['installed'] && $record['installed_sha'] !== null)
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => 'Forget the record for ' . $record['name'])
                    ->modalDescription('This addon is recorded as installed but its folder is not on the server. Forgetting clears that record. Nothing on disk is touched - if you moved or renamed the folder yourself, it stays exactly where you put it.')
                    ->modalSubmitActionLabel('Forget it')
                    ->action(fn (array $record) => $this->forget($record['id'])),

                Action::make('workshop')
                    ->label('Copy workshop id')
                    ->icon('tabler-copy')
                    ->color('gray')
                    ->visible(fn (array $record): bool => !$record['installable'] && $record['workshop_id'] !== null)
                    ->modalHeading(fn (array $record): string => $record['name'] . ' is workshop-only')
                    // Being explicit rather than offering a button that cannot work:
                    // a Steam collection belongs to a Steam account, and the panel has
                    // no way to authenticate as it.
                    ->modalDescription(fn (array $record): string => sprintf(
                        'The panel cannot add an item to a Steam collection - there is no API for it that does not require signing in as the collection\'s owner. Add %s to your collection on Steam, then restart the server. The id is %s.',
                        $record['name'],
                        $record['workshop_id'],
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ]);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function status(array $record): string
    {
        if (!$record['installable'] && !$record['installed']) {
            return 'Not installable';
        }

        if (!$record['installed']) {
            return $record['installed_sha'] === null ? 'Available' : 'Missing';
        }

        if ($record['enabled'] === false) {
            return 'Disabled';
        }

        $head = $record['repo'] === null ? null : ($this->heads[$record['repo']] ?? null);

        // Only claims we can actually back. Not having checked is not the same as
        // being up to date, so an unknown stays "Installed" rather than claiming either.
        if ($head === null || $record['installed_sha'] === null) {
            return 'Installed';
        }

        return $head === $record['installed_sha'] ? 'Up to date' : 'Update available';
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function provenance(array $record): ?string
    {
        // The manifest records what was installed; the folder list is the truth. When
        // they disagree, say so - a silent difference between the two is how a failed
        // install gets mistaken for a working one.
        if (!$record['installed'] && $record['installed_sha'] !== null) {
            return 'recorded as installed ' . ($record['installed_at']?->diffForHumans() ?? 'previously')
                . ', but the folder is not on the server';
        }

        if (!$record['installed']) {
            return null;
        }

        if ($record['installed_sha'] === null) {
            // Found on disk but not in the manifest - installed by hand, or before
            // this plugin started recording. Say so rather than implying we put it there.
            return 'in ' . $record['installed_folder'] . ' (not installed from here)';
        }

        return sprintf(
            '%s @ %s, %s',
            $record['installed_folder'],
            substr((string) $record['installed_sha'], 0, 7),
            $record['installed_at']?->diffForHumans() ?? 'unknown date',
        );
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function installSummary(array $record): string
    {
        $missing = array_values(array_filter(
            $record['requires'],
            fn (string $id): bool => !($this->entryById($id)['installed'] ?? true),
        ));

        $lines = [sprintf(
            'Downloads %s from %s and extracts it to garrysmod/%s/%s.',
            $record['ref'],
            $record['url'],
            $record['target'],
            $record['folder'],
        )];

        if ($missing !== []) {
            $names = array_map(fn (string $id): string => $this->entryById($id)['name'] ?? $id, $missing);

            $lines[] = 'Its dependencies will be installed first: ' . implode(', ', $names) . '.';
        }

        if ($record['notes']) {
            $lines[] = $record['notes'];
        }

        $lines[] = 'A restart is needed before the server loads it.';

        return implode(' ', $lines);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function entryById(string $id): ?array
    {
        foreach ($this->entries as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filtered(): array
    {
        $availableOnly = (bool) ($this->tableFilters['available']['isActive'] ?? false);
        $category = $this->tableFilters['category']['value'] ?? null;

        return array_values(array_filter($this->entries, static function (array $entry) use ($availableOnly, $category): bool {
            if ($availableOnly && $entry['installed']) {
                return false;
            }

            return $category === null || $category === '' || $entry['category'] === $category;
        }));
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('preset')
                ->label('Install a preset')
                ->icon('tabler-stack-2')
                ->authorize(fn (): bool => $this->canInstall())
                ->disabled(fn (): bool => $this->installing)
                ->visible(fn (): bool => $this->presets !== [])
                ->schema([
                    Select::make('preset')
                        ->label('Preset')
                        ->required()
                        ->selectablePlaceholder(false)
                        ->options(fn (): array => collect($this->presets)
                            ->mapWithKeys(fn (array $preset): array => [
                                $preset['id'] => $preset['complete']
                                    ? $preset['name'] . ' (already installed)'
                                    : $preset['name'] . ' — ' . count($preset['pending']) . ' to install',
                            ])
                            ->all())
                        ->helperText('Each preset installs its addons and their dependencies in order, skipping anything already present.'),
                ])
                ->modalHeading('Install a preset')
                ->modalDescription('Everything in the chosen preset is downloaded from GitHub and extracted into the addons folder. Nothing already installed is touched.')
                ->modalSubmitActionLabel('Install them')
                ->action(fn (array $data) => $this->installPreset($data['preset'])),

            Action::make('check')
                ->label('Check for updates')
                ->icon('tabler-refresh-dot')
                ->color('gray')
                // Not done on page load: it is one GitHub request per installed addon,
                // and unauthenticated GitHub allows sixty an hour. Asking is cheaper
                // than a page that sometimes takes ten seconds to open.
                ->action(fn () => $this->checkUpdates()),
        ];
    }

    public function checkUpdates(): void
    {
        // Forced, so this button means "look again now" rather than replaying what
        // was cached hours ago - which is what you want right after pushing a commit.
        $this->refreshHeads(force: true);

        $installed = array_filter(
            $this->entries,
            static fn (array $entry): bool => $entry['installed'] && $entry['repo'] !== null,
        );

        $checked = count(array_filter(
            $installed,
            fn (array $entry): bool => ($this->heads[$entry['repo']] ?? null) !== null,
        ));

        $unknown = count($installed) - $checked;

        $behind = count(array_filter(
            $this->entries,
            fn (array $entry): bool => $this->status($entry) === 'Update available',
        ));

        Notification::make()
            ->title(match (true) {
                $checked === 0 && $unknown === 0 => 'Nothing installed from the catalogue to check.',
                $behind === 0 => 'Everything checked is up to date.',
                default => $behind . ' ' . ($behind === 1 ? 'addon has' : 'addons have') . ' a newer commit upstream.',
            })
            ->body($unknown > 0
                ? "GitHub did not answer for {$unknown} of them - it rate limits unauthenticated requests to sixty an hour."
                : null)
            ->status($behind > 0 ? 'warning' : 'success')
            ->send();
    }

    public function install(string $id): void
    {
        $this->begin(app(AddonInstaller::class)->order($this->server(), $id));
    }

    public function update(string $id): void
    {
        $this->begin([$id], update: true);
    }

    public function installPreset(string $presetId): void
    {
        $preset = collect($this->presets)->firstWhere('id', $presetId);

        if ($preset === null) {
            Notification::make()->title('Unknown preset.')->danger()->send();

            return;
        }

        $installer = app(AddonInstaller::class);
        $ids = [];

        foreach ($preset['pending'] as $member) {
            foreach ($installer->order($this->server(), $member['id']) as $id) {
                $ids[] = $id;
            }
        }

        $this->begin(array_values(array_unique($ids)));
    }

    /**
     * @param  list<string>  $ids
     */
    private function begin(array $ids, bool $update = false): void
    {
        if (!$this->guard()) {
            return;
        }

        if ($ids === []) {
            Notification::make()->title('Nothing to install.')->send();

            return;
        }

        $this->installQueue = $ids;
        $this->installUpdate = $update;
        $this->installLog = [];
        $this->installFailures = [];
        $this->installDone = [];
        $this->installTotal = count($ids);
        $this->installPlan = [];
        $this->installing = true;
    }

    /**
     * One step of an install. Driven by the page while a run is in progress, so it
     * can say what it is doing rather than freezing on a click, and so no single
     * request has to survive a whole download-extract-move sequence.
     */
    public function installStep(): void
    {
        if (!$this->installing) {
            return;
        }

        if (!$this->canInstall()) {
            $this->finishRun('You no longer have permission to install addons here.');

            return;
        }

        $installer = app(AddonInstaller::class);

        // Nothing in flight: take the next addon off the queue.
        if ($this->installPlan === []) {
            if ($this->installQueue === []) {
                $this->finishRun();

                return;
            }

            $id = (string) array_shift($this->installQueue);
            $this->installPlan = $installer->plan($this->server(), $id, $this->installUpdate);
            $this->installLog[] = '— ' . $this->installPlan['name'];
        }

        $this->installPlan = $installer->step($this->server(), $this->installPlan);

        // Only the notes added by this step, so the log reads as a running commentary.
        $notes = array_slice($this->installPlan['log'], $this->installLoggedUpTo);
        $this->installLoggedUpTo = count($this->installPlan['log']);

        foreach ($notes as $note) {
            $this->installLog[] = $note;
        }

        if (!$this->installPlan['done']) {
            return;
        }

        if ($this->installPlan['ok']) {
            $this->installDone[] = $this->installPlan['name'];
        } else {
            $this->installFailures[] = $this->installPlan['message'];
        }

        $this->installPlan = [];
        $this->installLoggedUpTo = 0;

        // A failed dependency makes everything queued behind it pointless.
        if ($this->installFailures !== []) {
            $this->installQueue = [];
        }

        if ($this->installQueue === []) {
            $this->finishRun();
        }
    }

    public function uninstall(string $id): void
    {
        if (!$this->canDelete()) {
            Notification::make()
                ->title('You do not have permission to delete files on this server.')
                ->danger()
                ->send();

            return;
        }

        $result = app(AddonInstaller::class)->uninstall($this->server(), $id);

        Notification::make()
            ->title($result['ok'] ? 'Uninstalled' : 'Could not uninstall')
            ->body($result['message'])
            ->status($result['ok'] ? 'success' : 'danger')
            ->send();

        $this->load();
    }

    public function forget(string $id): void
    {
        if (!$this->canInstall()) {
            Notification::make()
                ->title('You do not have permission to modify this server\'s files.')
                ->danger()
                ->send();

            return;
        }

        $forgotten = app(AddonCatalogue::class)->forgetInstall($this->server(), $id);

        Notification::make()
            ->title($forgotten ? 'Record cleared.' : 'Could not rewrite the install record.')
            ->status($forgotten ? 'success' : 'danger')
            ->send();

        $this->load();
    }

    public function cancelInstall(): void
    {
        // Only stops between addons - an addon mid-extract is left to finish rather
        // than abandoned half-written.
        $this->installQueue = [];

        Notification::make()->title('Stopping after the current addon.')->send();
    }

    public function currentStageLabel(): string
    {
        $stage = $this->installPlan['stage'] ?? null;

        return AddonInstaller::STAGES[$stage] ?? 'Starting';
    }

    public function installProgress(): int
    {
        if ($this->installTotal === 0) {
            return 0;
        }

        $finished = count($this->installDone) + count($this->installFailures);

        // Part-credit for the addon in flight, so the bar moves within an addon too.
        $stages = array_keys(AddonInstaller::STAGES);
        $at = array_search($this->installPlan['stage'] ?? '', $stages, true);
        $partial = $at === false ? 0 : ($at / count($stages));

        return (int) round((($finished + $partial) / $this->installTotal) * 100);
    }

    private function finishRun(?string $reason = null): void
    {
        $this->installing = false;
        $this->installPlan = [];
        $this->installQueue = [];

        $this->load();
        $this->refreshHeads();

        if ($reason !== null) {
            Notification::make()->title($reason)->danger()->send();

            return;
        }

        $ok = $this->installFailures === [];

        $notification = Notification::make()
            ->title($ok
                ? 'Installed ' . implode(', ', $this->installDone)
                : 'Could not finish')
            ->body($ok
                ? 'Restart the server to load ' . (count($this->installDone) === 1 ? 'it.' : 'them.')
                : implode(' ', $this->installFailures))
            ->status($ok ? 'success' : 'danger');

        // A failure here says something specific about the server's filesystem, and
        // is worth more than the four seconds a toast normally gets.
        if (!$ok) {
            $notification->persistent();
        }

        $notification->send();
    }

    private function guard(): bool
    {
        if ($this->canInstall()) {
            return true;
        }

        Notification::make()
            ->title('You do not have permission to install addons on this server.')
            ->body('Installing needs file create, archive and update permissions.')
            ->danger()
            ->send();

        return false;
    }

    public static function getNavigationLabel(): string
    {
        return 'Addon library';
    }

    public function getTitle(): string
    {
        return 'Addon library';
    }

    public function getSubheading(): ?string
    {
        $installed = count(array_filter($this->entries, static fn (array $e): bool => $e['installed']));

        return sprintf('%d of %d installed', $installed, count($this->entries));
    }
}
