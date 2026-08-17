<?php

namespace Catualus\GmodToolkit\Filament\Server\Pages;

use App\Filament\Server\Resources\Files\Pages\ListFiles;
use BackedEnum;
use Catualus\GmodToolkit\Services\AddonService;
use Catualus\GmodToolkit\Support\GarrysMod;
use Filament\Notifications\Notification;

class Addons extends GmodPage
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-package';

    protected static ?int $navigationSort = 20;

    protected string $view = 'gmod-toolkit::addons';

    /** @var list<array<string, mixed>> */
    public array $addons = [];

    /** @var list<array{label: string, path: string}> */
    public array $darkrp = [];

    public bool $writable = false;

    public function mount(): void
    {
        $this->writable = $this->canWriteFiles();
        $this->load();
    }

    public function load(): void
    {
        $server = $this->server();

        $this->addons = app(AddonService::class)->all($server);

        $gmod = app(GarrysMod::class);

        $this->darkrp = array_values(array_filter(
            config('gmod-toolkit.darkrp_paths', []),
            fn (array $shortcut): bool => $gmod->exists($server, $shortcut['path']),
        ));
    }

    public function toggle(string $name, bool $enable): void
    {
        if (!$this->canWriteFiles()) {
            Notification::make()
                ->title('You do not have permission to modify this server\'s files.')
                ->danger()
                ->send();

            return;
        }

        $result = app(AddonService::class)->toggle($this->server(), $name, $enable);

        Notification::make()
            ->title($result['message'])
            ->status($result['ok'] ? 'success' : 'danger')
            ->send();

        $this->load();
    }

    public function fileUrl(string $path): string
    {
        return ListFiles::getUrl(['path' => trim($path, '/')]);
    }

    public static function getNavigationLabel(): string
    {
        return 'Addons';
    }

    public function getTitle(): string
    {
        return 'Installed addons';
    }

    public function getSubheading(): ?string
    {
        $enabled = count(array_filter($this->addons, fn (array $a): bool => $a['enabled']));
        $disabled = count($this->addons) - $enabled;

        return $disabled > 0
            ? "{$enabled} enabled, {$disabled} disabled"
            : "{$enabled} enabled";
    }
}
