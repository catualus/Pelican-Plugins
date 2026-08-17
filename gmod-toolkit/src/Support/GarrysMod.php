<?php

namespace Catualus\GmodToolkit\Support;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Detects Garry's Mod servers and provides the shared file access every page here
 * needs. Scoped binding, so directory listings are fetched once per request.
 */
class GarrysMod
{
    /** @var array<string, bool> */
    private array $detected = [];

    /** @var array<string, list<array<string, mixed>>|null> */
    private array $listings = [];

    public function detect(Server $server): bool
    {
        return $this->detected[$server->uuid] ??= $this->probe($server);
    }

    private function probe(Server $server): bool
    {
        $server->loadMissing('egg');

        $tags = array_map('strtolower', (array) ($server->egg?->tags ?? []));
        $features = array_map('strtolower', (array) ($server->egg?->features ?? []));
        $image = strtolower($server->image ?? '');

        if (array_intersect($tags, ['gmod', 'garrysmod', 'garrys-mod'])) {
            return true;
        }

        // Every Source egg shares the same features, so narrow to Source first and
        // then confirm with the garrysmod/ directory only GMod ships.
        $maybeSource = (bool) array_intersect($tags, ['source', 'source-engine', 'srcds'])
            || (bool) array_intersect($features, ['steam_disk_space', 'gsl_token'])
            || str_contains($image, 'games:source');

        return $maybeSource && $this->directory($server, 'garrysmod') !== null;
    }

    /**
     * @return list<array<string, mixed>>|null  null when the daemon cannot be reached
     */
    public function directory(Server $server, string $path): ?array
    {
        $key = $server->uuid . ':' . $path;

        if (array_key_exists($key, $this->listings)) {
            return $this->listings[$key];
        }

        $ttl = (int) config('gmod-toolkit.cache_ttl', 60);
        $cacheKey = 'gmod-toolkit:dir:' . $key;

        if ($ttl > 0 && is_array($cached = Cache::get($cacheKey))) {
            return $this->listings[$key] = $cached;
        }

        try {
            $entries = app(DaemonFileRepository::class)
                ->setServer($server)
                ->getDirectory('/' . trim($path, '/'));
        } catch (Throwable) {
            // Missing directory, server installing, or node down. Never cached, so it
            // recovers as soon as the server does.
            return $this->listings[$key] = null;
        }

        if ($ttl > 0) {
            Cache::put($cacheKey, $entries, $ttl);
        }

        return $this->listings[$key] = $entries;
    }

    /**
     * Reads a file, returning null rather than throwing when it is absent or the
     * node is unreachable - callers render an explanatory empty state instead.
     */
    public function contents(Server $server, string $path): ?string
    {
        try {
            return app(DaemonFileRepository::class)
                ->setServer($server)
                ->getContent('/' . ltrim($path, '/'), (int) config('gmod-toolkit.max_log_bytes', 16777216));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A file's listing entry, or null when it does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function entry(Server $server, string $path): ?array
    {
        $path = trim($path, '/');
        $parent = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';

        foreach ($this->directory($server, $parent) ?? [] as $entry) {
            if (($entry['name'] ?? null) === basename($path)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The mtime of a file, read from its parent's listing.
     */
    public function modifiedAt(Server $server, string $path): ?Carbon
    {
        $modified = $this->entry($server, $path)['modified'] ?? null;

        if (!is_string($modified) || $modified === '') {
            return null;
        }

        try {
            return Carbon::parse($modified);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Empties a file without deleting it - Garry's Mod keeps appending to the same
     * handle, so removing it outright would stop new errors being recorded.
     */
    public function truncate(Server $server, string $path): bool
    {
        try {
            app(DaemonFileRepository::class)
                ->setServer($server)
                ->putContent('/' . ltrim($path, '/'), '');
        } catch (Throwable) {
            return false;
        }

        $this->forget($server);

        return true;
    }

    public function exists(Server $server, string $path): bool
    {
        return $this->entry($server, $path) !== null;
    }

    public function forget(Server $server): void
    {
        foreach (array_keys($this->listings) as $key) {
            if (str_starts_with($key, $server->uuid . ':')) {
                Cache::forget('gmod-toolkit:dir:' . $key);
                unset($this->listings[$key]);
            }
        }
    }
}
