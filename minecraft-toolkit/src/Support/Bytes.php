<?php

namespace Catualus\MinecraftToolkit\Support;

/**
 * Byte formatting shared by the tables, charts and stat tiles.
 *
 * Deliberately local rather than leaning on the panel's convert_bytes_to_readable():
 * the charts need the raw number in a chosen unit as well as a label, and the two
 * have to agree or a bar will disagree with the row beside it.
 */
final class Bytes
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    /**
     * "1.4 GB". Picks the largest unit that leaves a number above 1.
     */
    public static function human(int|float $bytes, int $precision = 1): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $power = min((int) floor(log($bytes, 1024)), count(self::UNITS) - 1);
        $value = $bytes / (1024 ** $power);

        // Bytes are never fractional, and a "1.0 KB" reads worse than "1 KB".
        $decimals = $power === 0 ? 0 : ($value >= 100 ? 0 : $precision);

        return number_format($value, $decimals) . ' ' . self::UNITS[$power];
    }

    public static function toMegabytes(int|float $bytes, int $precision = 1): float
    {
        return round($bytes / 1048576, $precision);
    }

    /**
     * How long this many bytes takes to download, in seconds, at a line speed given
     * in megabits. Used for the "a new player downloads this much" estimate, which is
     * the number that actually decides whether a collection is too big.
     */
    public static function downloadSeconds(int|float $bytes, float $megabitsPerSecond): float
    {
        if ($megabitsPerSecond <= 0) {
            return 0.0;
        }

        return ($bytes * 8) / ($megabitsPerSecond * 1_000_000);
    }

    /**
     * "4 min 12 s" - deliberately coarse, because the estimate itself is coarse.
     */
    public static function humanDuration(float $seconds): string
    {
        if ($seconds < 1) {
            return 'under a second';
        }

        if ($seconds < 60) {
            return round($seconds) . ' s';
        }

        $minutes = (int) floor($seconds / 60);

        if ($minutes < 60) {
            return $minutes . ' min ' . round($seconds - $minutes * 60) . ' s';
        }

        $hours = (int) floor($minutes / 60);

        return $hours . ' h ' . ($minutes - $hours * 60) . ' min';
    }
}
