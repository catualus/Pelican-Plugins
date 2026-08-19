<?php

namespace Catualus\GmodToolkit\Support;

/**
 * Turns a Garry's Mod Lua error log into a per-addon digest.
 *
 * Handles the three line shapes that actually occur in these logs:
 *
 *   [pixel-ui] addons/pixel-ui/lua/x.lua:12: attempt to index a nil value
 *   [PIXEL UI] PIXEL.DrawImgur is nil after load.
 *   [ERROR] Couldn't include file 'gmtoolbox\...' - File not found or is empty
 *
 * Records in clientside_errors.txt are introduced by a reporter line
 * ("Name|2|STEAM_0:1:123"); console.log has no such line, so the reporter is
 * simply left unknown there.
 */
final class LuaErrorParser
{
    private const REPORTER = '/^(?<name>.*)\|\d+\|(?<steamid>STEAM_[0-9]:[0-9]:[0-9]+)\s*$/';

    private const TAGGED = '/^\[(?<tag>[^\]]+)\]\s+(?<rest>\S.*)$/';

    private const LOCATED = '/^(?<file>\S.*?\.lua):(?<line>\d+):\s*(?<message>.*)$/';

    private const FRAME = '/^\s+\d+\.\s+\S.*?\s+-\s+(?<location>\S.*)$/';

    // Gamemodes matter as much as addons here - DarkRP reports plenty of its own.
    private const ADDON_DIR = '#(?:^|/)(?<kind>addons|gamemodes)/(?<name>[^/]+)/#';

    /** Distinct messages kept per addon. Beyond this the tail is noise. */
    private const MAX_VARIANTS = 25;

    /**
     * These logs carry no timestamps of any kind, so "when did this last happen" is
     * not recoverable. They are append-only and chronological though, so an error's
     * position is a real recency signal: last_index is where a group last appeared,
     * and recent counts how many of its errors fall in the tail of the log.
     *
     * Position is also what makes the timeline chart possible - slicing the log into
     * equal-width buckets shows bursts and quiet stretches without a single timestamp.
     *
     * @param  int  $recentWindow  how many trailing errors count as still happening
     * @param  int  $bucketCount  slices the log is divided into for the timeline
     * @return array{groups: list<array<string, mixed>>, total: int, buckets: list<int>}
     */
    public static function analyse(string $contents, int $recentWindow = 100, int $bucketCount = 40): array
    {
        $groups = [];
        $reporter = null;
        $current = null;
        $index = 0;

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match(self::REPORTER, $line, $m)) {
                $reporter = $m['steamid'];
                $current = null;

                continue;
            }

            // Stack frames only ever refine the error above them.
            if (preg_match(self::FRAME, $line, $m)) {
                if ($current !== null && $groups[$current]['path'] === null) {
                    $groups[$current]['path'] = self::addonPath($m['location']);
                }

                continue;
            }

            $error = self::parseErrorLine($line);

            if ($error === null) {
                $current = null;

                continue;
            }

            $key = mb_strtolower($error['addon']);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'addon' => $error['addon'],
                    'path' => $error['path'],
                    'count' => 0,
                    'reporters' => [],
                    'message' => $error['message'],
                    'location' => $error['location'],
                    'indices' => [],
                    'variants' => [],
                ];
            }

            $groups[$key]['count']++;
            $groups[$key]['path'] ??= $error['path'];
            $groups[$key]['indices'][] = $index;

            // Later entries are more recent, so the newest message wins.
            $groups[$key]['message'] = $error['message'];
            $groups[$key]['location'] = $error['location'] ?? $groups[$key]['location'];

            // Distinct message+location pairs, so an addon failing in three different
            // places is not reported as one problem that happened 900 times.
            $variant = $error['message'] . "\0" . ($error['location'] ?? '');

            if (isset($groups[$key]['variants'][$variant])) {
                $groups[$key]['variants'][$variant]['count']++;
            } elseif (count($groups[$key]['variants']) < self::MAX_VARIANTS) {
                $groups[$key]['variants'][$variant] = [
                    'message' => $error['message'],
                    'location' => $error['location'],
                    'count' => 1,
                ];
            }

            if ($reporter !== null) {
                $groups[$key]['reporters'][$reporter] = true;
            }

            $current = $key;
            $index++;
        }

        $threshold = $index - max($recentWindow, 1);

        $digest = array_map(function (array $g) use ($threshold): array {
            $variants = array_values($g['variants']);
            usort($variants, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

            return [
                'addon' => $g['addon'],
                'path' => $g['path'],
                'count' => $g['count'],
                'reporters' => count($g['reporters']),
                'message' => $g['message'],
                'location' => $g['location'],
                'last_index' => $g['indices'] === [] ? 0 : max($g['indices']),
                'first_index' => $g['indices'] === [] ? 0 : min($g['indices']),
                'recent' => count(array_filter($g['indices'], static fn (int $i): bool => $i >= $threshold)),
                'variants' => $variants,
            ];
        }, array_values($groups));

        usort($digest, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'groups' => $digest,
            'total' => $index,
            'buckets' => self::bucket($groups, $index, $bucketCount),
        ];
    }

    /**
     * Error volume per equal-width slice of the log. The x axis is position, not
     * time - but because the log is append-only, "further right" does mean "later",
     * and a spike at the right-hand end is a problem happening now.
     *
     * @param  array<string, array<string, mixed>>  $groups
     * @return list<int>
     */
    private static function bucket(array $groups, int $total, int $bucketCount): array
    {
        $bucketCount = max($bucketCount, 1);

        if ($total === 0) {
            return array_fill(0, $bucketCount, 0);
        }

        $buckets = array_fill(0, $bucketCount, 0);

        foreach ($groups as $group) {
            foreach ($group['indices'] as $index) {
                $slot = (int) floor(($index / $total) * $bucketCount);
                $buckets[min($slot, $bucketCount - 1)]++;
            }
        }

        return $buckets;
    }

    /**
     * @return array{addon: string, path: ?string, message: string, location: ?string}|null
     */
    private static function parseErrorLine(string $line): ?array
    {
        $tag = null;
        $body = $line;

        if (preg_match(self::TAGGED, $line, $m)) {
            $tag = trim($m['tag']);
            $body = $m['rest'];
        }

        if (preg_match(self::LOCATED, $body, $m)) {
            $path = self::addonPath($m['file']);

            return [
                // [ERROR] is Garry's Mod's generic prefix, not an addon name - prefer
                // the folder the failing file actually lives in.
                'addon' => self::isGenericTag($tag) ? ($path === null ? 'Unattributed' : basename($path)) : $tag,
                'path' => $path,
                'message' => trim($m['message']),
                'location' => $m['file'] . ':' . $m['line'],
            ];
        }

        // A tagged line with no file:line is still a real reported problem.
        if ($tag !== null && !self::isGenericTag($tag)) {
            return [
                'addon' => $tag,
                'path' => null,
                'message' => trim($body),
                'location' => null,
            ];
        }

        if ($tag !== null) {
            return [
                'addon' => 'Unattributed',
                'path' => null,
                'message' => trim($body),
                'location' => null,
            ];
        }

        return null;
    }

    private static function isGenericTag(?string $tag): bool
    {
        return $tag === null || in_array(mb_strtolower($tag), ['error', 'warning'], true);
    }

    private static function addonPath(string $location): ?string
    {
        // Lua reports mixed separators; normalise before matching.
        $location = str_replace('\\', '/', $location);

        return preg_match(self::ADDON_DIR, $location, $m) ? $m['kind'] . '/' . $m['name'] : null;
    }
}
