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
     * @return list<array{key: string, label: string, path: string, present: bool, hint: ?string, total: int, groups: list<array<string, mixed>>}>
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

                    continue;
                }

                $merged[$key]['count'] += $group['count'];
                $merged[$key]['reporters'] += $group['reporters'];
                $merged[$key]['recent'] += $group['recent'];
                $merged[$key]['path'] ??= $group['path'];
                // Indices are per-source, so the meaningful merge is the later one.
                $merged[$key]['last_index'] = max($merged[$key]['last_index'], $group['last_index']);
            }
        }

        $digest = array_values($merged);
        usort($digest, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $digest;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(Server $server): array
    {
        $sources = [];
        $window = (int) config('gmod-toolkit.recent_window', 100);

        foreach (config('gmod-toolkit.error_logs', []) as $key => $source) {
            $entry = $this->gmod->entry($server, $source['path']);
            $groups = $entry === null ? [] : $this->digestFile($server, $source['path'], $entry, $window);

            $sources[] = [
                'key' => (string) $key,
                'label' => $source['label'],
                'path' => $source['path'],
                'present' => $entry !== null,
                'hint' => $entry === null ? ($source['hint'] ?? null) : null,
                'total' => array_sum(array_column($groups, 'count')),
                'window' => $window,
                // The only wall-clock signal available: these logs have no timestamps,
                // so the file's own mtime is when the newest error in it was written.
                'modified' => $this->gmod->modifiedAt($server, $source['path']),
                'groups' => $groups,
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
     * @return list<array<string, mixed>>
     */
    private function digestFile(Server $server, string $path, array $entry, int $window): array
    {
        $ttl = (int) config('gmod-toolkit.digest_cache_ttl', 3600);

        $fingerprint = md5(implode('|', [
            $path,
            (string) ($entry['modified'] ?? ''),
            (string) ($entry['size'] ?? ''),
            (string) $window,
        ]));

        $cacheKey = 'gmod-toolkit:digest:' . $server->uuid . ':' . $fingerprint;

        if ($ttl > 0 && is_array($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $contents = $this->gmod->contents($server, $path);
        $groups = $contents === null ? [] : LuaErrorParser::digest($contents, $window);

        if ($ttl > 0) {
            Cache::put($cacheKey, $groups, $ttl);
        }

        return $groups;
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
}
