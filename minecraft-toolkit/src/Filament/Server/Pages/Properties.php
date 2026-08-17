<?php

namespace Catualus\MinecraftToolkit\Filament\Server\Pages;

use BackedEnum;
use Catualus\MinecraftToolkit\Services\PropertiesService;
use Filament\Notifications\Notification;

class Properties extends MinecraftPage
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-settings';

    protected static ?int $navigationSort = 30;

    protected string $view = 'minecraft-toolkit::properties';

    /** @var list<array<string, mixed>> */
    public array $fields = [];

    /** @var array<string, mixed> */
    public array $values = [];

    public bool $writable = false;

    public string $search = '';

    public function mount(): void
    {
        $this->writable = $this->canWriteFiles();
        $this->load();
    }

    public function load(): void
    {
        $this->fields = app(PropertiesService::class)->fields($this->server());

        $this->values = [];

        foreach ($this->fields as $field) {
            $this->values[$field['key']] = $field['type'] === 'boolean'
                ? $field['value'] === 'true'
                : $field['value'];
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

    /**
     * @return list<array<string, mixed>>
     */
    public function visibleFields(): array
    {
        $needle = mb_strtolower(trim($this->search));

        if ($needle === '') {
            return $this->fields;
        }

        return array_values(array_filter(
            $this->fields,
            fn (array $field): bool => str_contains(mb_strtolower($field['key']), $needle),
        ));
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
        return $this->fields === [] ? null : count($this->fields) . ' settings';
    }
}
