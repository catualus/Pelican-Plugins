<?php

namespace Catualus\GmodToolkit\Filament\Server\Widgets;

use App\Enums\SubuserPermission;
use App\Models\Server;
use Catualus\GmodToolkit\Support\GarrysMod;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/**
 * Shared base for the charts on these pages.
 *
 * Filament's chart widget already resolves grid and label colours from the panel's
 * own theme, so a single-series chart that sets no colours of its own follows light
 * and dark mode for free. Only charts whose series mean different things set colours
 * explicitly - and those come from PALETTE, which is chosen to stay legible on both
 * a white and a near-black surface.
 */
abstract class GmodChart extends ChartWidget
{
    /**
     * Categorical colours, in the order they should be used.
     *
     * These are mid-range hues rather than the pale or deep ends of a ramp, because
     * the same swatch has to read against both backgrounds - a light-mode palette
     * inverted for dark mode would need two sets and two chances to get it wrong.
     */
    protected const PALETTE = [
        '#3b82f6', // blue
        '#f59e0b', // amber
        '#10b981', // emerald
        '#a855f7', // purple
        '#ef4444', // red
        '#06b6d4', // cyan
        '#ec4899', // pink
        '#84cc16', // lime
        '#f97316', // orange
        '#6366f1', // indigo
    ];

    /** Everything outside the top N of a composition chart. */
    protected const REMAINDER_COLOR = '#94a3b8';

    protected const DANGER = '#ef4444';

    protected const MUTED = '#94a3b8';

    public static function canView(): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server
            && user()?->can(SubuserPermission::FileRead, $server)
            && app(GarrysMod::class)->detect($server);
    }

    protected function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    /**
     * @return list<string>
     */
    protected function colors(int $count): array
    {
        $colors = [];

        for ($i = 0; $i < $count; $i++) {
            $colors[] = self::PALETTE[$i % count(self::PALETTE)];
        }

        return $colors;
    }

    /**
     * A long addon title makes the y axis eat the plot area, so labels are clipped
     * with the full name left to the tooltip.
     */
    protected function truncate(string $label, int $length = 34): string
    {
        return mb_strlen($label) <= $length
            ? $label
            : mb_substr($label, 0, $length - 1) . '…';
    }
}
