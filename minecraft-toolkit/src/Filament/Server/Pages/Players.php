<?php

namespace Catualus\MinecraftToolkit\Filament\Server\Pages;

use BackedEnum;
use Catualus\MinecraftToolkit\Services\PlayerListService;
use Filament\Notifications\Notification;

class Players extends MinecraftPage
{
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

    /** @var array<string, string> */
    public array $newEntry = [];

    public int $opLevel = 4;

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
            $this->newEntry[$list] ??= '';
        }
    }

    public function add(string $list): void
    {
        if (!$this->canWriteFiles()) {
            $this->denied();

            return;
        }

        $result = app(PlayerListService::class)
            ->add($this->server(), $list, $this->newEntry[$list] ?? '', $this->opLevel);

        Notification::make()
            ->title($result['message'])
            ->status($result['ok'] ? 'success' : 'danger')
            ->send();

        if ($result['ok']) {
            $this->newEntry[$list] = '';
        }

        $this->load();
    }

    public function remove(string $list, string $identifier): void
    {
        if (!$this->canWriteFiles()) {
            $this->denied();

            return;
        }

        $result = app(PlayerListService::class)->remove($this->server(), $list, $identifier);

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
}
