<?php

namespace Catualus\AppToolkit\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use BackedEnum;
use Catualus\AppToolkit\Services\DependencyService;
use Catualus\AppToolkit\Services\DeployService;
use Catualus\AppToolkit\Services\EnvService;
use Catualus\AppToolkit\Support\AppServer;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class AppPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-terminal-2';

    protected static ?int $navigationSort = 40;

    protected string $view = 'app-toolkit::app';

    /** @var array<string, mixed> */
    public array $deploy = [];

    /** @var list<array<string, mixed>> */
    public array $env = [];

    /** @var array<string, string> */
    public array $envValues = [];

    /**
     * What was in the file when the page loaded, so an edited value can be marked as
     * edited and the save summary can name what it is about to rewrite.
     *
     * @var array<string, string>
     */
    public array $envOriginal = [];

    /** @var array<string, mixed> */
    public array $dependencies = ['file' => null, 'groups' => []];

    public string $dependencySearch = '';

    public bool $writable = false;

    public bool $restartable = false;

    public bool $hasEnv = false;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server
            && user()?->can(SubuserPermission::FileRead, $server)
            && app(AppServer::class)->detect($server);
    }

    public function mount(): void
    {
        $server = $this->server();

        $this->writable = (bool) user()?->can(SubuserPermission::FileUpdate, $server);
        $this->restartable = (bool) user()?->can(SubuserPermission::ControlRestart, $server);

        $this->load();
    }

    public function load(): void
    {
        $server = $this->server();

        $this->deploy = app(DeployService::class)->status($server);
        $this->dependencies = app(DependencyService::class)->read($server);

        $envService = app(EnvService::class);

        $this->hasEnv = $envService->exists($server);
        $this->env = $envService->entries($server);

        $this->envValues = [];

        foreach ($this->env as $entry) {
            $this->envValues[$entry['key']] = $entry['value'];
        }

        $this->envOriginal = $this->envValues;
    }

    public function isDirty(string $key): bool
    {
        return ($this->envValues[$key] ?? null) !== ($this->envOriginal[$key] ?? null);
    }

    /**
     * @return list<string>
     */
    public function dirtyKeys(): array
    {
        return array_values(array_filter(
            array_keys($this->envValues),
            fn (string $key): bool => $this->isDirty($key),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function visibleDependencies(): array
    {
        $needle = mb_strtolower(trim($this->dependencySearch));

        if ($needle === '') {
            return $this->dependencies['groups'];
        }

        $groups = [];

        foreach ($this->dependencies['groups'] as $group) {
            $packages = array_values(array_filter(
                $group['packages'],
                static fn (array $package): bool => str_contains(mb_strtolower($package['name']), $needle),
            ));

            if ($packages !== []) {
                $groups[] = [...$group, 'packages' => $packages];
            }
        }

        return $groups;
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('redeploy')
                ->label(fn (): string => $this->deploy['repo'] && $this->deploy['auto_update'] ? 'Pull & restart' : 'Restart')
                ->icon('tabler-refresh')
                ->color('warning')
                ->authorize(fn (): bool => $this->restartable)
                ->requiresConfirmation()
                ->modalHeading('Restart this server?')
                ->modalDescription(fn (): string => match (true) {
                    !$this->deploy['repo'] => 'This app is not deployed from a repository, so this restarts the current files.',
                    $this->deploy['auto_update'] => 'The startup command pulls the latest commit before booting, so this does deploy new code.',
                    default => 'Auto Update is off, so the startup command will NOT pull. This restarts exactly the code that is on disk now.',
                })
                ->action(fn () => $this->redeploy()),

            Action::make('saveEnv')
                ->label('Save .env')
                ->icon('tabler-device-floppy')
                ->authorize(fn (): bool => $this->writable && $this->hasEnv)
                ->disabled(fn (): bool => $this->dirtyKeys() === [])
                ->requiresConfirmation()
                ->modalHeading('Write .env')
                ->modalDescription(fn (): string => $this->saveSummary())
                ->modalSubmitActionLabel('Save')
                ->action(fn () => $this->saveEnv()),

            Action::make('addKey')
                ->label('Add variable')
                ->icon('tabler-plus')
                ->color('gray')
                ->authorize(fn (): bool => $this->writable && $this->hasEnv)
                ->schema([
                    TextInput::make('key')
                        ->label('Name')
                        ->required()
                        ->rule('regex:/^[A-Za-z_][A-Za-z0-9_.]*$/')
                        ->validationMessages(['regex' => 'A .env name may only contain letters, digits, underscores and dots, and cannot start with a digit.'])
                        ->helperText('Appended to the end of the file. Existing lines are untouched.'),
                    TextInput::make('value')
                        ->label('Value')
                        ->helperText('Quoting is added only if the value would not survive being read back unquoted.'),
                ])
                ->action(fn (array $data) => $this->addKey($data['key'], (string) ($data['value'] ?? ''))),
        ];
    }

    private function saveSummary(): string
    {
        $dirty = $this->dirtyKeys();

        if ($dirty === []) {
            return 'Nothing has changed.';
        }

        return sprintf(
            'Rewrites %s: %s. Every other line - comments, ordering, blank lines, export prefixes and existing quoting - is left exactly as it was. Restart the app for the change to take effect.',
            count($dirty) === 1 ? 'one variable' : count($dirty) . ' variables',
            implode(', ', $dirty),
        );
    }

    public function saveEnv(): void
    {
        if (!$this->writable) {
            $this->deniedFiles();

            return;
        }

        $result = app(EnvService::class)->save($this->server(), $this->envValues);

        Notification::make()
            ->title($result['message'])
            ->status($result['ok'] ? 'success' : 'danger')
            ->send();

        $this->load();
    }

    public function addKey(string $key, string $value): void
    {
        if (!$this->writable) {
            $this->deniedFiles();

            return;
        }

        $result = app(EnvService::class)->add($this->server(), $key, $value);

        Notification::make()
            ->title($result['message'])
            ->status($result['ok'] ? 'success' : 'danger')
            ->send();

        $this->load();
    }

    public function redeploy(): void
    {
        if (!$this->restartable) {
            Notification::make()
                ->title('You do not have permission to restart this server.')
                ->danger()
                ->send();

            return;
        }

        $result = app(DeployService::class)->redeploy($this->server());

        Notification::make()
            ->title($result['message'])
            ->status($result['ok'] ? 'success' : 'danger')
            ->send();

        $this->load();
    }

    public static function getNavigationLabel(): string
    {
        return 'App';
    }

    public function getTitle(): string
    {
        return 'App';
    }

    public function getSubheading(): ?string
    {
        $runtime = match (app(AppServer::class)->runtime($this->server())) {
            AppServer::RUNTIME_NODE => 'Node.js application',
            AppServer::RUNTIME_PYTHON => 'Python application',
            default => null,
        };

        $dirty = count($this->dirtyKeys());

        return match (true) {
            $dirty > 0 && $runtime !== null => $runtime . ' · ' . $dirty . ' unsaved .env change' . ($dirty === 1 ? '' : 's'),
            $dirty > 0 => $dirty . ' unsaved .env change' . ($dirty === 1 ? '' : 's'),
            default => $runtime,
        };
    }

    protected function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    private function deniedFiles(): void
    {
        Notification::make()
            ->title('You do not have permission to modify this server\'s files.')
            ->danger()
            ->send();
    }
}
