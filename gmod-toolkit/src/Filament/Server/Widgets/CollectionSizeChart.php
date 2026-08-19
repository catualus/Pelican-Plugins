<?php

namespace Catualus\GmodToolkit\Filament\Server\Widgets;

use Catualus\GmodToolkit\Services\WorkshopService;
use Catualus\GmodToolkit\Support\Bytes;

/**
 * Which addons in the collection are actually large.
 *
 * A collection page lists two hundred names and gives no sense of which of them is
 * the 400 MB one. Sorted bars answer that in a glance, and the answer is nearly
 * always that a handful of entries are most of the download.
 */
class CollectionSizeChart extends GmodChart
{
    protected ?string $heading = 'Largest addons';

    protected ?string $maxHeight = '26rem';

    protected int|string|array $columnSpan = 'full';

    private const LIMIT = 15;

    protected function getType(): string
    {
        return 'bar';
    }

    public function getDescription(): ?string
    {
        return 'The biggest entries in the collection, by download size.';
    }

    public function getEmptyStateHeading(): string
    {
        return 'No collection to measure';
    }

    public function getEmptyStateDescription(): ?string
    {
        return 'Set a WORKSHOP_ID on the Startup page and the collection will be sized here.';
    }

    public function getEmptyStateIcon(): string
    {
        return 'tabler-chart-bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $workshop = app(WorkshopService::class);
        $collection = $workshop->collectionId($this->server());

        $addons = $collection === null ? [] : $workshop->addons($collection);

        $addons = array_values(array_filter($addons, static fn (array $a): bool => $a['size'] > 0));

        if ($addons === []) {
            return [];
        }

        usort($addons, static fn (array $a, array $b): int => $b['size'] <=> $a['size']);

        $top = array_slice($addons, 0, self::LIMIT);

        return [
            'datasets' => [[
                'label' => 'Megabytes',
                'data' => array_map(static fn (array $a): float => Bytes::toMegabytes($a['size']), $top),
                'borderRadius' => 3,
            ]],
            'labels' => array_map(fn (array $a): string => $this->truncate($a['title']), $top),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            // Horizontal, because addon names are long and vertical bars would turn
            // every label 45 degrees and make them unreadable.
            'indexAxis' => 'y',
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'x' => [
                    'beginAtZero' => true,
                    'title' => ['display' => true, 'text' => 'MB'],
                ],
            ],
        ];
    }
}
