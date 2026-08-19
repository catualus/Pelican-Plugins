<?php

namespace Catualus\GmodToolkit\Filament\Server\Widgets;

use Catualus\GmodToolkit\Services\WorkshopService;

/**
 * How recently the collection's addons were last updated.
 *
 * Two things fall out of this that a flat list hides: a tall "this week" bar right
 * after something broke overnight names the likely cause, and a tall "over a year"
 * bar is a collection quietly rotting - abandoned addons are how a server ends up
 * broken by a Garry's Mod update nobody could have predicted.
 */
class CollectionFreshnessChart extends GmodChart
{
    protected ?string $heading = 'Last updated';

    protected ?string $maxHeight = '18rem';

    protected function getType(): string
    {
        return 'bar';
    }

    public function getEmptyStateHeading(): string
    {
        return 'Nothing to date';
    }

    public function getEmptyStateIcon(): string
    {
        return 'tabler-calendar-stats';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $workshop = app(WorkshopService::class);
        $collection = $workshop->collectionId($this->server());

        $addons = $collection === null ? [] : $workshop->addons($collection);

        if ($addons === []) {
            return [];
        }

        $days = (int) config('gmod-toolkit.recent_update_days', 7);
        $summary = $workshop->summarise($addons, $days);
        $freshness = $summary['freshness'];

        $buckets = [
            'recent' => ['label' => "Last {$days} days", 'color' => '#f59e0b'],
            'month' => ['label' => 'Last 30 days', 'color' => '#10b981'],
            'year' => ['label' => 'Last year', 'color' => '#3b82f6'],
            'stale' => ['label' => 'Over a year', 'color' => '#a855f7'],
            'unknown' => ['label' => 'No date', 'color' => self::MUTED],
        ];

        $labels = [];
        $values = [];
        $colors = [];

        foreach ($buckets as $key => $bucket) {
            // A bucket nobody falls into is noise on the axis.
            if (($freshness[$key] ?? 0) === 0) {
                continue;
            }

            $labels[] = $bucket['label'];
            $values[] = $freshness[$key];
            $colors[] = $bucket['color'];
        }

        return [
            'datasets' => [[
                'label' => 'Addons',
                'data' => $values,
                'backgroundColor' => $colors,
                'borderRadius' => 3,
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
            'plugins' => ['legend' => ['display' => false]],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    // Addon counts are whole numbers; a 0.5 gridline is meaningless.
                    'ticks' => ['precision' => 0],
                ],
            ],
        ];
    }
}
