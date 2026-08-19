<?php

namespace Catualus\GmodToolkit\Filament\Server\Widgets;

use Catualus\GmodToolkit\Services\LuaErrorService;

/**
 * Error volume across the length of the log.
 *
 * These logs carry no timestamps at all, so a real time axis is not recoverable.
 * What is recoverable is order: the file is append-only, so position is monotonic
 * with time even though the spacing is not. Slicing it into equal-width buckets and
 * plotting the count per slice is therefore a genuine shape - a wall of errors at
 * the right-hand end is a problem happening now, and a spike in the middle with a
 * flat tail is something that broke once and stopped.
 *
 * The x axis is deliberately labelled "oldest -> newest" rather than with numbers,
 * because a bucket index is not a quantity anyone should read as one.
 */
class ErrorTimelineChart extends GmodChart
{
    protected ?string $heading = 'When the errors happened';

    protected ?string $maxHeight = '16rem';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'line';
    }

    public function getDescription(): ?string
    {
        return 'These logs have no timestamps, so this is position in the file - left is oldest, right is newest. A spike on the right is happening now.';
    }

    public function getEmptyStateHeading(): string
    {
        return 'No errors to plot';
    }

    public function getEmptyStateIcon(): string
    {
        return 'tabler-timeline';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $sources = app(LuaErrorService::class)->sources($this->server());

        $datasets = [];
        $length = 0;
        $index = 0;

        foreach ($sources as $source) {
            if ($source['total'] === 0 || $source['buckets'] === []) {
                continue;
            }

            $color = self::PALETTE[$index % count(self::PALETTE)];
            $length = max($length, count($source['buckets']));

            $datasets[] = [
                'label' => $source['label'],
                'data' => array_values($source['buckets']),
                'borderColor' => $color,
                'backgroundColor' => $color . '33',
                'fill' => true,
                'tension' => 0.3,
                'pointRadius' => 0,
                'borderWidth' => 2,
            ];

            $index++;
        }

        if ($datasets === []) {
            return [];
        }

        // Each source is sliced into the same number of buckets, so the two curves are
        // comparable in shape even though their logs are different lengths.
        $labels = array_fill(0, $length, '');
        $labels[0] = 'oldest';
        $labels[$length - 1] = 'newest';

        return ['datasets' => $datasets, 'labels' => $labels];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['position' => 'bottom'],
                'tooltip' => ['intersect' => false, 'mode' => 'index'],
            ],
            'scales' => [
                'x' => ['grid' => ['display' => false]],
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }
}
