<?php

namespace Catualus\GmodToolkit\Filament\Server\Widgets;

use Catualus\GmodToolkit\Services\WorkshopService;
use Catualus\GmodToolkit\Support\Bytes;

/**
 * The shape of the collection: how much of the download a few addons account for.
 *
 * Deliberately paired with the size bars rather than replacing them - the bars say
 * which addons are large, this says whether the collection is a few heavy entries or
 * a long tail, which is a different question and changes what you would do about it.
 */
class CollectionCompositionChart extends GmodChart
{
    protected ?string $heading = 'What the download is made of';

    protected ?string $maxHeight = '20rem';

    private const LIMIT = 10;

    protected function getType(): string
    {
        return 'doughnut';
    }

    public function getEmptyStateHeading(): string
    {
        return 'Nothing to break down';
    }

    public function getEmptyStateIcon(): string
    {
        return 'tabler-chart-pie';
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
        $rest = array_slice($addons, self::LIMIT);

        $labels = array_map(fn (array $a): string => $this->truncate($a['title'], 28), $top);
        $values = array_map(static fn (array $a): float => Bytes::toMegabytes($a['size']), $top);
        $colors = $this->colors(count($top));

        if ($rest !== []) {
            $labels[] = count($rest) . ' others';
            $values[] = Bytes::toMegabytes(array_sum(array_column($rest, 'size')));
            $colors[] = self::REMAINDER_COLOR;
        }

        return [
            'datasets' => [[
                'label' => 'Megabytes',
                'data' => $values,
                'backgroundColor' => $colors,
                'borderWidth' => 0,
            ]],
            'labels' => $labels,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'position' => 'right',
                    'labels' => ['boxWidth' => 12, 'boxHeight' => 12],
                ],
            ],
        ];
    }
}
