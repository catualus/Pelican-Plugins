<?php

namespace Catualus\GmodToolkit\Filament\Server\Widgets;

use Catualus\GmodToolkit\Services\LuaErrorService;

/**
 * Which addons are throwing errors, split by whether they still are.
 *
 * The table's "Active" badge is a yes or no. Stacking still-happening errors against
 * historical ones instead shows proportion: an addon with 3,000 errors of which four
 * are recent has been fixed and left its mess behind, and one with 40 errors that are
 * all recent is the thing to look at now. Those two look identical in a flat count.
 */
class ErrorsByAddonChart extends GmodChart
{
    protected ?string $heading = 'Errors by addon';

    protected ?string $maxHeight = '26rem';

    protected int|string|array $columnSpan = 'full';

    private const LIMIT = 12;

    protected function getType(): string
    {
        return 'bar';
    }

    public function getDescription(): ?string
    {
        $window = (int) config('gmod-toolkit.recent_window', 100);

        return "Still happening means the addon appears in the last {$window} errors of the log.";
    }

    public function getEmptyStateHeading(): string
    {
        return 'No Lua errors';
    }

    public function getEmptyStateDescription(): ?string
    {
        return 'Nothing has been reported, or the logs are not being written.';
    }

    public function getEmptyStateIcon(): string
    {
        return 'tabler-mood-smile';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $digest = app(LuaErrorService::class)->digest($this->server());

        if ($digest === []) {
            return [];
        }

        // Sorted by what is live first, so the chart and the table agree on which
        // addon is the current problem.
        usort($digest, static fn (array $a, array $b): int => [$b['recent'], $b['count']] <=> [$a['recent'], $a['count']]);

        $top = array_slice($digest, 0, self::LIMIT);

        return [
            'datasets' => [
                [
                    'label' => 'Still happening',
                    'data' => array_map(static fn (array $g): int => $g['recent'], $top),
                    'backgroundColor' => self::DANGER,
                    'borderRadius' => 3,
                ],
                [
                    'label' => 'Historical',
                    'data' => array_map(static fn (array $g): int => max(0, $g['count'] - $g['recent']), $top),
                    'backgroundColor' => self::MUTED,
                    'borderRadius' => 3,
                ],
            ],
            'labels' => array_map(fn (array $g): string => $this->truncate($g['addon']), $top),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'scales' => [
                'x' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
                'y' => ['stacked' => true],
            ],
            'plugins' => [
                'legend' => ['position' => 'bottom'],
            ],
        ];
    }
}
