<?php

namespace Catualus\GmodToolkit\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Catualus\GmodToolkit\Support\GarrysMod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Measures how much disk each installed addon actually uses.
 *
 * There is no du endpoint on the daemon - a directory listing reports 0 for a
 * directory - so the only way to get this is to walk the tree, one HTTP request per
 * folder. A large addon collection is thousands of folders, which is why this is an
 * explicit action rather than something the page does on load, and why it runs under
 * a hard request budget and reports honestly when it hit it.
 *
 * The result is cached for a long time: addon sizes change when someone installs
 * something, which is a thing the user did and can re-scan after.
 */
class DiskUsageService
{
    public function __construct(private readonly GarrysMod $gmod) {}

    /**
     * @return array{addons: array<string, int>, total: int, measured_at: ?Carbon, partial: bool, requests: int, scanned: int}|null
     */
    public function cached(Server $server): ?array
    {
        $cached = Cache::get($this->key($server));

        if (!is_array($cached)) {
            return null;
        }

        $cached['measured_at'] = isset($cached['measured_at'])
            ? Carbon::parse($cached['measured_at'])
            : null;

        return $cached;
    }

    /**
     * The addon folders a scan will walk, in the order it will walk them.
     *
     * @return array<string, string>  addon name => path
     */
    public function roots(Server $server): array
    {
        $roots = [];

        foreach ($this->gmod->directoryNames($server, 'garrysmod/addons') as $name) {
            if ($name !== AddonService::DISABLED_DIR) {
                $roots[$name] = 'garrysmod/addons/' . $name;
            }
        }

        foreach ($this->gmod->directoryNames($server, 'garrysmod/addons/' . AddonService::DISABLED_DIR) as $name) {
            $roots[$name] = 'garrysmod/addons/' . AddonService::DISABLED_DIR . '/' . $name;
        }

        return $roots;
    }

    /**
     * Measures the next few addons and hands back what it did.
     *
     * A scan of a large addons folder is thousands of daemon requests and takes
     * long enough that doing it in one call means the page sits there saying
     * nothing. Doing it a few addons at a time lets the caller show which addon is
     * being measured and how far along it is, and keeps any single request short.
     *
     * @param  array<string, string>  $pending  addon name => path, still to measure
     * @return array{sizes: array<string, int>, done: list<string>, requests: int, partial: bool}
     */
    public function scanChunk(Server $server, array $pending, int $addonsPerChunk = 4): array
    {
        $budget = max(1, (int) config('gmod-toolkit.disk_scan_requests', 400));
        $deadline = microtime(true) + max(1, (int) config('gmod-toolkit.disk_scan_step_seconds', 10));

        $sizes = [];
        $done = [];
        $requests = 0;
        $partial = false;

        foreach ($pending as $name => $path) {
            if (count($done) >= $addonsPerChunk || microtime(true) >= $deadline) {
                break;
            }

            [$size, $used, $complete] = $this->measure($server, $path, $budget, $deadline);

            $sizes[$name] = $size;
            $done[] = $name;
            $requests += $used;

            if (!$complete) {
                $partial = true;
            }
        }

        return ['sizes' => $sizes, 'done' => $done, 'requests' => $requests, 'partial' => $partial];
    }

    /**
     * Stores a finished scan so the chart and the addon table can read it back.
     *
     * @param  array<string, int>  $sizes
     * @return array{addons: array<string, int>, total: int, measured_at: ?Carbon, partial: bool, requests: int, scanned: int}
     */
    public function store(Server $server, array $sizes, int $requests, bool $partial): array
    {
        arsort($sizes);

        $result = [
            'addons' => $sizes,
            'total' => array_sum($sizes),
            'measured_at' => now(),
            'partial' => $partial,
            'requests' => $requests,
            'scanned' => count($sizes),
        ];

        $ttl = (int) config('gmod-toolkit.disk_scan_cache_ttl', 86400);

        if ($ttl > 0) {
            Cache::put($this->key($server), [...$result, 'measured_at' => $result['measured_at']->toIso8601String()], $ttl);
        }

        return $result;
    }

    public function forget(Server $server): void
    {
        Cache::forget($this->key($server));
    }

    /**
     * Breadth-first so that a budget running out costs depth rather than losing whole
     * addons - the top levels of every folder are measured before any deep one is.
     *
     * @return array{0: int, 1: int, 2: bool}  bytes, requests used, whether it finished
     */
    private function measure(Server $server, string $root, int $budget, float $deadline): array
    {
        $queue = [$root];
        $bytes = 0;
        $used = 0;

        while ($queue !== []) {
            if ($used >= $budget || microtime(true) >= $deadline) {
                return [$bytes, $used, false];
            }

            $path = array_shift($queue);
            $used++;

            try {
                $entries = app(DaemonFileRepository::class)
                    ->setServer($server)
                    ->getDirectory('/' . trim($path, '/'));
            } catch (Throwable) {
                // A folder that cannot be read contributes nothing rather than
                // aborting the whole scan.
                continue;
            }

            foreach ($entries as $entry) {
                $name = $entry['name'] ?? null;

                if (!is_string($name) || $name === '') {
                    continue;
                }

                if (($entry['directory'] ?? false) === true) {
                    $queue[] = $path . '/' . $name;

                    continue;
                }

                $bytes += (int) ($entry['size'] ?? 0);
            }
        }

        return [$bytes, $used, true];
    }

    private function key(Server $server): string
    {
        return 'gmod-toolkit:disk:' . $server->uuid;
    }
}
