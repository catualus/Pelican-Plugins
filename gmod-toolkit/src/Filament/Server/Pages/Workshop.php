<?php

namespace Catualus\GmodToolkit\Filament\Server\Pages;

use BackedEnum;
use Catualus\GmodToolkit\Services\WorkshopService;

class Workshop extends GmodPage
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-cloud-download';

    protected static ?int $navigationSort = 21;

    protected string $view = 'gmod-toolkit::workshop';

    public ?string $collectionId = null;

    /** @var list<array<string, mixed>> */
    public array $addons = [];

    public int $recentDays = 7;

    public function mount(): void
    {
        $workshop = app(WorkshopService::class);
        $server = $this->server();

        $this->recentDays = (int) config('gmod-toolkit.recent_update_days', 7);
        $this->collectionId = $workshop->collectionId($server);
        $this->addons = $this->collectionId === null ? [] : $workshop->addons($this->collectionId);
    }

    public static function getNavigationLabel(): string
    {
        return 'Workshop';
    }

    public function getTitle(): string
    {
        return 'Workshop collection';
    }

    public function getSubheading(): ?string
    {
        if ($this->collectionId === null) {
            return null;
        }

        return count($this->addons) . ' addons in collection ' . $this->collectionId;
    }

    public function collectionUrl(): ?string
    {
        return $this->collectionId === null
            ? null
            : 'https://steamcommunity.com/sharedfiles/filedetails/?id=' . $this->collectionId;
    }

    public function isRecent(?int $timestamp): bool
    {
        return $timestamp !== null && $timestamp >= now()->subDays($this->recentDays)->getTimestamp();
    }
}
