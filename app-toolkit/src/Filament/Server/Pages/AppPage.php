<?php

namespace Catualus\AppToolkit\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use BackedEnum;
use Catualus\AppToolkit\Services\DependencyService;
use Catualus\AppToolkit\Services\DeployService;
use Catualus\AppToolkit\Services\EnvService;
use Catualus\AppToolkit\Support\AppServer;
use Filament\Facades\Filament;
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

    /** @var array<string, bool> */
    public array $revealed = [];

    /** @var array<string, mixed> */
    public array $dependencies = ['file' => null, 'groups' => []];

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
            $this->revealed[$entry['key']] ??= !$entry['secret'];
        }
    }

    public function reveal(string $key): void
    {
        $this->revealed[$key] = !($this->revealed[$key] ?? false);
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
        return match (app(AppServer::class)->runtime($this->server())) {
            AppServer::RUNTIME_NODE => 'Node.js application',
            AppServer::RUNTIME_PYTHON => 'Python application',
            default => null,
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
