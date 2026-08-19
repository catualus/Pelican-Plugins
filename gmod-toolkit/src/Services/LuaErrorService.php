<?php

namespace Catualus\GmodToolkit\Services;

use App\Models\Server;
use Catualus\GmodToolkit\Support\GarrysMod;
use Catualus\GmodToolkit\Support\LuaErrorParser;
use Illuminate\Support\Facades\Cache;

/**
 * Reads whichever Lua error logs exist on the server and digests them per addon.
 *
 * Two sources, both optional:
 *   garrysmod/console.log            server-side errors, only written with -condebug
 *   garrysmod/clientside_errors.txt   errors players' clients reported back
 */
class LuaErrorService
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $cache = [];

    public function __construct(private readonly GarrysMod $gmod) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function sources(Server $server): array
    {
        return $this->cache[$server->uuid] ??= $this->read($server);
    }

    /**
     * Every group across every source, merged by addon - used by the addon browser
     * to show an error count per addon.
     *
     * @return list<array<string, mixed>>
     */
    public function digest(Server $server): array
    {
        $merged = [];

        foreach ($this->sources($server) as $source) {
            foreach ($source['groups'] as $group) {
                $key = mb_strtolower($group['addon']);

                if (!isset($merged[$key])) {
                    $merged[$key] = $group;
                    $merged[$key]['sources'] = [$source['label']];

                    continue;
                }

                $merged[$key]['count'] += $group['count'];
                $merged[$key]['reporters'] += $group['reporters'];
                $merged[$key]['recent'] += $group['recent'];
                $merged[$key]['path'] ??= $group['path'];
                $merged[$key]['sources'][] = $source['label'];
                $merged[$key]['variants'] = $this->mergeVariants(
                    $merged[$key]['variants'] ?? [],
                    $group['variants'] ?? [],
                );
                // Indices are per-source, so the meaningful merge is the later one.
                $merged[$key]['last_index'] = max($merged[$key]['last_index'], $group['last_index']);
            }
        }

        $digest = array_values($merged);
        usort($digest, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $digest;
    }

    /**
     * Totals across every source, for the overview stat tiles.
     *
     * @return array{errors: int, addons: int, active: int, players: int}
     */
    public function totals(Server $server): array
    {
        $digest = $this->digest($server);

        return [
            'errors' => array_sum(array_column($digest, 'count')),
            'addons' => count($digest),
            'active' => count(array_filter($digest, static fn (array $g): bool => $g['recent'] > 0)),
            'players' => array_sum(array_column($digest, 'reporters')),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $a
     * @param  list<array<string, mixed>>  $b
     * @return list<array<string, mixed>>
     */
    private function mergeVariants(array $a, array $b): array
    {
        $merged = [];

        foreach ([...$a, ...$b] as $variant) {
            $key = $variant['message'] . "\0" . ($variant['location'] ?? '');

            if (isset($merged[$key])) {
                $merged[$key]['count'] += $variant['count'];

                continue;
            }

            $merged[$key] = $variant;
        }

        $variants = array_values($merged);
        usort($variants, static fn (array $x, array $y): int => $y['count'] <=> $x['count']);

        return $variants;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(Server $server): array
    {
        $sources = [];
        $window = (int) config('gmod-toolkit.recent_window', 100);
        $buckets = (int) config('gmod-toolkit.timeline_buckets', 40);

        foreach (config('gmod-toolkit.error_logs', []) as $key => $source) {
            $entry = $this->gmod->entry($server, $source['path']);

            $analysis = $entry === null
                ? ['groups' => [], 'total' => 0, 'buckets' => []]
                : $this->analyseFile($server, $source['path'], $entry, $window, $buckets);

            $sources[] = [
                'key' => (string) $key,
                'label' => $source['label'],
                'path' => $source['path'],
                'present' => $entry !== null,
                'hint' => $entry === null ? ($source['hint'] ?? null) : null,
                'total' => $analysis['total'],
                'window' => $window,
                'buckets' => $analysis['buckets'],
                'size' => (int) ($entry['size'] ?? 0),
                // The only wall-clock signal available: these logs have no timestamps,
                // so the file's own mtime is when the newest error in it was written.
                'modified' => $this->gmod->modifiedAt($server, $source['path']),
                'groups' => $analysis['groups'],
            ];
        }

        return $sources;
    }

    /**
     * Digests are cached against the file's own mtime and size rather than a plain
     * TTL. An unchanged log is never re-downloaded or re-parsed - which matters,
     * because a busy server's clientside_errors.txt runs to hundreds of kilobytes
     * and this runs on both the errors page and the addon browser. A log that grows
     * by one line changes its fingerprint and is re-read immediately, so the cache
     * can never serve stale counts.
     *
     * @param  array<string, mixed>  $entry
     * @return array{groups: list<array<string, mixed>>, total: int, buckets: list<int>}
     */
    private function analyseFile(Server $server, string $path, array $entry, int $window, int $buckets): array
    {
        $ttl = (int) config('gmod-toolkit.digest_cache_ttl', 3600);

        $fingerprint = md5(implode('|', [
            $path,
            (string) ($entry['modified'] ?? ''),
            (string) ($entry['size'] ?? ''),
            (string) $window,
            (string) $buckets,
        ]));

        // v2 because the cached shape changed; an old entry must not be read back.
        $cacheKey = 'gmod-toolkit:digest:v2:' . $server->uuid . ':' . $fingerprint;

        if ($ttl > 0 && is_array($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $contents = $this->gmod->contents($server, $path);

        $analysis = $contents === null
            ? ['groups' => [], 'total' => 0, 'buckets' => []]
            : LuaErrorParser::analyse($contents, $window, $buckets);

        if ($ttl > 0) {
            Cache::put($cacheKey, $analysis, $ttl);
        }

        return $analysis;
    }

    /**
     * Truncates a log so the digest reflects only what happens from now on.
     */
    public function clear(Server $server, string $key): bool
    {
        $source = config('gmod-toolkit.error_logs.' . $key);

        if ($source === null || !$this->gmod->truncate($server, $source['path'])) {
            return false;
        }

        unset($this->cache[$server->uuid]);

        return true;
    }

    /**
     * The raw log, for the "download before clearing" action.
     */
    public function contents(Server $server, string $key): ?string
    {
        $source = config('gmod-toolkit.error_logs.' . $key);

        return $source === null ? null : $this->gmod->contents($server, $source['path']);
    }
}
