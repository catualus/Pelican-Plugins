<?php

namespace Catualus\GmodToolkit\Filament\Server\Widgets;

use Catualus\GmodToolkit\Services\DiskUsageService;
use Catualus\GmodToolkit\Support\Bytes;

/**
 * What each installed addon costs on disk.
 *
 * Only renders once a scan has been run - the daemon cannot report a directory's
 * size, so this data does not exist until someone has paid for it by walking the
 * tree. An empty state that says so is better than a chart of zeroes.
 */
class AddonDiskUsageChart extends GmodChart
{
    protected ?string $heading = 'Disk used per addon';

    protected ?string $maxHeight = '26rem';

    protected int|string|array $columnSpan = 'full';

    private const LIMIT = 15;

    protected function getType(): string
    {
        return 'bar';
    }

    public function getDescription(): ?string
    {
        $scan = app(DiskUsageService::class)->cached($this->server());

        if ($scan === null) {
            return null;
        }

        $when = $scan['measured_at']?->diffForHumans() ?? 'recently';
        $total = Bytes::human($scan['total']);

        return $scan['partial']
            ? "Measured {$when}: at least {$total} across {$scan['scanned']} addons. The scan hit its request budget, so deep folders are missing and these are floors, not totals."
            : "Measured {$when}: {$total} across {$scan['scanned']} addons.";
    }

    public function getEmptyStateHeading(): string
    {
        return 'Not measured yet';
    }

    public function getEmptyStateDescription(): ?string
    {
        return 'The daemon cannot report folder sizes, so this has to be walked one directory at a time. Use "Scan disk usage" above when you want the numbers.';
    }

    public function getEmptyStateIcon(): string
    {
        return 'tabler-ruler-measure';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $scan = app(DiskUsageService::class)->cached($this->server());

        if ($scan === null || $scan['addons'] === []) {
            return [];
        }

        $sizes = $scan['addons'];
        arsort($sizes);

        $top = array_slice($sizes, 0, self::LIMIT, preserve_keys: true);

        return [
            'datasets' => [[
                'label' => 'Megabytes',
                'data' => array_map(static fn (int $bytes): float => Bytes::toMegabytes($bytes), array_values($top)),
                'borderRadius' => 3,
            ]],
            'labels' => array_map(fn (string $name): string => $this->truncate($name), array_keys($top)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => [
                'x' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => 'MB']],
            ],
        ];
    }
}
