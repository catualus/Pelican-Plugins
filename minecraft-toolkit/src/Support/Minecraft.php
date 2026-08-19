<?php

namespace Catualus\MinecraftToolkit\Support;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Detects Minecraft servers and provides the shared file access the pages need.
 * Scoped binding, so listings are fetched at most once per request.
 */
class Minecraft
{
    public const FLAVOUR_MODS = 'mods';

    public const FLAVOUR_PLUGINS = 'plugins';

    /** @var array<string, bool> */
    private array $detected = [];

    /** @var array<string, string|null> */
    private array $flavours = [];

    /** @var array<string, list<array<string, mixed>>|null> */
    private array $listings = [];

    /** @var array<string, int> per-server cache generation, see forget() */
    private array $generations = [];

    public function detect(Server $server): bool
    {
        return $this->detected[$server->uuid] ??= $this->probe($server);
    }

    private function probe(Server $server): bool
    {
        $server->loadMissing('egg');

        $tags = array_map('strtolower', (array) ($server->egg?->tags ?? []));
        $features = array_map('strtolower', (array) ($server->egg?->features ?? []));

        if (in_array('minecraft', $tags, true) || in_array('eula', $features, true)) {
            return true;
        }

        // Some Java eggs carry neither, so fall back to the file every Minecraft
        // server has and nothing else does.
        return str_contains(strtolower($server->image ?? ''), 'java')
            && $this->exists($server, 'server.properties');
    }

    /**
     * Whether this server loads mods/ (Forge, NeoForge, Fabric) or plugins/
     * (Paper, Spigot, Bukkit). Null when neither directory exists yet.
     */
    public function flavour(Server $server): ?string
    {
        return $this->flavours[$server->uuid] ??= match (true) {
            $this->exists($server, self::FLAVOUR_MODS) => self::FLAVOUR_MODS,
            $this->exists($server, self::FLAVOUR_PLUGINS) => self::FLAVOUR_PLUGINS,
            default => null,
        };
    }

    /**
     * @return list<array<string, mixed>>|null  null when the path is missing or the node is down
     */
    public function directory(Server $server, string $path): ?array
    {
        $key = $server->uuid . ':' . $path;

        if (array_key_exists($key, $this->listings)) {
            return $this->listings[$key];
        }

        $ttl = (int) config('minecraft-toolkit.cache_ttl', 60);
        $cacheKey = $this->cacheKey($server, $path);

        if ($ttl > 0 && is_array($cached = Cache::get($cacheKey))) {
            return $this->listings[$key] = $cached;
        }

        try {
            $entries = app(DaemonFileRepository::class)
                ->setServer($server)
                ->getDirectory('/' . trim($path, '/'));
        } catch (Throwable) {
            return $this->listings[$key] = null;
        }

        if ($ttl > 0) {
            Cache::put($cacheKey, $entries, $ttl);
        }

        return $this->listings[$key] = $entries;
    }

    public function contents(Server $server, string $path): ?string
    {
        try {
            return app(DaemonFileRepository::class)
                ->setServer($server)
                ->getContent('/' . ltrim($path, '/'), (int) config('minecraft-toolkit.max_read_bytes', 8388608));
        } catch (Throwable) {
            return null;
        }
    }

    public function put(Server $server, string $path, string $contents): bool
    {
        try {
            app(DaemonFileRepository::class)
                ->setServer($server)
                ->putContent('/' . ltrim($path, '/'), $contents);
        } catch (Throwable) {
            return false;
        }

        $this->forget($server);

        return true;
    }

    public function rename(Server $server, string $root, string $from, string $to): bool
    {
        try {
            app(DaemonFileRepository::class)
                ->setServer($server)
                ->renameFiles('/' . trim($root, '/'), [['from' => $from, 'to' => $to]]);
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

    /**
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
     * Invalidates every cached listing for this server.
     *
     * Bumps a per-server generation number that forms part of each listing's cache
     * key, rather than deleting the keys this object happens to know about. The
     * in-memory map only holds what the current request read, so deleting from it
     * could never clear an entry cached by an earlier request - and anything that
     * writes across more than one request would then go on reading the directory as
     * it was up to cache_ttl seconds ago.
     */
    public function forget(Server $server): void
    {
        $generation = $this->generation($server) + 1;

        $this->generations[$server->uuid] = $generation;

        Cache::put($this->generationKey($server), $generation, 86400);

        foreach (array_keys($this->listings) as $key) {
            if (str_starts_with($key, $server->uuid . ':')) {
                unset($this->listings[$key]);
            }
        }

        unset($this->flavours[$server->uuid]);
    }

    private function cacheKey(Server $server, string $path): string
    {
        return 'minecraft-toolkit:dir:' . $this->generation($server) . ':' . $server->uuid . ':' . $path;
    }

    private function generation(Server $server): int
    {
        return $this->generations[$server->uuid] ??= (int) Cache::get($this->generationKey($server), 0);
    }

    private function generationKey(Server $server): string
    {
        return 'minecraft-toolkit:gen:' . $server->uuid;
    }
}
