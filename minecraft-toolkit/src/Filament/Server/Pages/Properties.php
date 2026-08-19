<?php

namespace Catualus\MinecraftToolkit\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Repositories\Daemon\DaemonServerRepository;
use BackedEnum;
use Catualus\MinecraftToolkit\Services\PropertiesService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Throwable;

class Properties extends MinecraftPage
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-settings';

    protected static ?int $navigationSort = 30;

    protected string $view = 'minecraft-toolkit::properties';

    /** @var list<array<string, mixed>> */
    public array $fields = [];

    /** @var array<string, mixed> */
    public array $values = [];

    /**
     * What was on disk when the page loaded, so a changed field can be marked as
     * changed. Kept separate from $values rather than re-read, because re-reading
     * would make every field look unchanged the moment it was edited.
     *
     * @var array<string, mixed>
     */
    public array $original = [];

    /** @var list<string> */
    public array $groups = [];

    public string $activeGroup = '';

    public bool $writable = false;

    public bool $restartable = false;

    public string $search = '';

    public function mount(): void
    {
        $this->writable = $this->canWriteFiles();
        $this->restartable = (bool) user()?->can(SubuserPermission::ControlRestart, $this->server());
        $this->load();
    }

    public function load(): void
    {
        $service = app(PropertiesService::class);

        $this->fields = $service->fields($this->server());
        $this->groups = $service->groups($this->fields);
        $this->values = [];

        foreach ($this->fields as $field) {
            // Booleans are kept as real booleans so a checkbox binds to them
            // directly. Everything else stays the string that was in the file, which
            // is what gets written back.
            $this->values[$field['key']] = $field['type'] === 'boolean'
                ? $field['value'] === 'true'
                : $field['value'];
        }

        $this->original = $this->values;

        if ($this->activeGroup === '' || !in_array($this->activeGroup, $this->groups, true)) {
            $this->activeGroup = $this->groups[0] ?? '';
        }
    }

    public function save(): void
    {
        if (!$this->canWriteFiles()) {
            $this->denied();

            return;
        }

        $result = app(PropertiesService::class)->save($this->server(), $this->values);

        Notification::make()
            ->title($result['message'])
            ->status($result['ok'] ? 'success' : 'danger')
            ->send();

        $this->load();
    }

    public function restart(): void
    {
        if (!$this->restartable) {
            Notification::make()->title('You do not have permission to restart this server.')->danger()->send();

            return;
        }

        try {
            app(DaemonServerRepository::class)->setServer($this->server())->power('restart');
        } catch (Throwable $e) {
            Notification::make()->title('Could not restart: ' . $e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Restarting the server.')->success()->send();
    }

    /**
     * Fields in the visible group, or across every group while a search is active -
     * searching within one tab would hide the match you were looking for.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleFields(): array
    {
        $needle = mb_strtolower(trim($this->search));

        return array_values(array_filter($this->fields, function (array $field) use ($needle): bool {
            if ($needle !== '') {
                return str_contains(mb_strtolower($field['key']), $needle)
                    || str_contains(mb_strtolower((string) ($field['help'] ?? '')), $needle);
            }

            return $field['group'] === $this->activeGroup;
        }));
    }

    public function isDirty(string $key): bool
    {
        return ($this->values[$key] ?? null) !== ($this->original[$key] ?? null);
    }

    /**
     * @return list<string>
     */
    public function dirtyKeys(): array
    {
        return array_values(array_filter(
            array_keys($this->values),
            fn (string $key): bool => $this->isDirty($key),
        ));
    }

    public function countInGroup(string $group): int
    {
        return count(array_filter($this->fields, static fn (array $f): bool => $f['group'] === $group));
    }

    public function dirtyInGroup(string $group): int
    {
        return count(array_filter(
            $this->fields,
            fn (array $f): bool => $f['group'] === $group && $this->isDirty($f['key']),
        ));
    }

    public function setGroup(string $group): void
    {
        $this->activeGroup = $group;
    }

    public function revert(): void
    {
        $this->values = $this->original;
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save changes')
                ->icon('tabler-device-floppy')
                ->authorize(fn (): bool => $this->writable)
                ->disabled(fn (): bool => $this->dirtyKeys() === [])
                ->requiresConfirmation()
                ->modalHeading('Write server.properties')
                ->modalDescription(fn (): string => $this->saveSummary())
                ->modalSubmitActionLabel('Save')
                ->action(fn () => $this->save()),

            Action::make('revert')
                ->label('Discard changes')
                ->icon('tabler-arrow-back-up')
                ->color('gray')
                ->visible(fn (): bool => $this->dirtyKeys() !== [])
                ->action(fn () => $this->revert()),

            Action::make('restart')
                ->label('Restart server')
                ->icon('tabler-refresh')
                ->color('warning')
                ->authorize(fn (): bool => $this->restartable)
                ->requiresConfirmation()
                ->modalHeading('Restart the server?')
                ->modalDescription('Everyone online is disconnected. server.properties is only read at startup, so changes do not apply until this happens.')
                ->action(fn () => $this->restart()),
        ];
    }

    private function saveSummary(): string
    {
        $dirty = $this->dirtyKeys();

        if ($dirty === []) {
            return 'Nothing has changed.';
        }

        $listed = array_slice($dirty, 0, 8);
        $more = count($dirty) - count($listed);

        return sprintf(
            'Rewrites %s: %s%s. Every other line - comments, ordering and Java\'s escaping - is left exactly as it was. A restart is needed before the server reads it.',
            count($dirty) === 1 ? 'one setting' : count($dirty) . ' settings',
            implode(', ', $listed),
            $more > 0 ? " and {$more} more" : '',
        );
    }

    public static function getNavigationLabel(): string
    {
        return 'Properties';
    }

    public function getTitle(): string
    {
        return 'server.properties';
    }

    public function getSubheading(): ?string
    {
        if ($this->fields === []) {
            return null;
        }

        $dirty = count($this->dirtyKeys());

        return $dirty === 0
            ? count($this->fields) . ' settings'
            : count($this->fields) . ' settings · ' . $dirty . ' unsaved';
    }
}
