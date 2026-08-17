<?php

namespace Catualus\MinecraftToolkit\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Repositories\Daemon\DaemonServerRepository;
use BackedEnum;
use Catualus\MinecraftToolkit\Services\ModService;
use Catualus\MinecraftToolkit\Support\Minecraft;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Throwable;

class Mods extends MinecraftPage
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-packages';

    protected static ?int $navigationSort = 32;

    protected string $view = 'minecraft-toolkit::mods';

    /** @var list<array<string, mixed>> */
    public array $items = [];

    /** @var list<array{name: string, path: string, modified: ?string}> */
    public array $crashes = [];

    public string $search = '';

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

    public function load(): void
    {
        $server = $this->server();
        $minecraft = app(Minecraft::class);

        $this->flavour = $minecraft->flavour($server);
        $this->items = app(ModService::class)->all($server);

        $reports = [];

        foreach ($minecraft->directory($server, 'crash-reports') ?? [] as $entry) {
            if (($entry['directory'] ?? false) === true) {
                continue;
            }

            $reports[] = [
                'name' => (string) ($entry['name'] ?? ''),
                'path' => 'crash-reports/' . ($entry['name'] ?? ''),
                'modified' => is_string($entry['modified'] ?? null) ? $entry['modified'] : null,
            ];
        }

        // Crash report filenames are timestamped, so name order is time order.
        usort($reports, fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        $this->crashes = array_slice($reports, 0, (int) config('minecraft-toolkit.crash_report_limit', 5));
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
     * @return list<array<string, mixed>>
     */
    public function visibleItems(): array
    {
        $needle = mb_strtolower(trim($this->search));

        if ($needle === '') {
            return $this->items;
        }

        return array_values(array_filter(
            $this->items,
            fn (array $item): bool => str_contains(mb_strtolower($item['file']), $needle),
        ));
    }

    public function fileUrl(string $path): string
    {
        return ListFiles::getUrl(['path' => trim($path, '/')]);
    }

    public static function getNavigationLabel(): string
    {
        $server = Filament::getTenant();

        return $server instanceof \App\Models\Server && app(Minecraft::class)->flavour($server) === Minecraft::FLAVOUR_PLUGINS
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

        return $disabled > 0 ? "{$enabled} enabled, {$disabled} disabled" : "{$enabled} enabled";
    }
}
