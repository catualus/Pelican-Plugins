<?php

namespace Catualus\AppToolkit\Support;

use App\Models\EggVariable;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Detects plain Node/Python application servers - bots, backends, workers - and
 * provides the shared file access the page needs. Scoped binding.
 */
class AppServer
{
    public const RUNTIME_NODE = 'node';

    public const RUNTIME_PYTHON = 'python';

    /** @var array<string, string|null> */
    private array $runtimes = [];

    /** @var array<string, list<array<string, mixed>>|null> */
    private array $listings = [];

    /** @var array<string, int> per-server cache generation, see forget() */
    private array $generations = [];

    public function runtime(Server $server): ?string
    {
        return $this->runtimes[$server->uuid] ??= $this->probe($server);
    }

    public function detect(Server $server): bool
    {
        return $this->runtime($server) !== null;
    }

    private function probe(Server $server): ?string
    {
        $server->loadMissing('egg');

        $image = strtolower($server->image ?? '');
        $tags = array_map('strtolower', (array) ($server->egg?->tags ?? []));

        // Game eggs are never app servers, even when they happen to ship a runtime.
        if (array_intersect($tags, ['minecraft', 'source', 'gmod', 'steam'])) {
            return null;
        }

        return match (true) {
            str_contains($image, 'nodejs'), str_contains($image, 'node_'), in_array('nodejs', $tags, true) => self::RUNTIME_NODE,
            str_contains($image, 'python'), in_array('python', $tags, true) => self::RUNTIME_PYTHON,
            default => null,
        };
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function directory(Server $server, string $path = ''): ?array
    {
        $key = $server->uuid . ':' . $path;

        if (array_key_exists($key, $this->listings)) {
            return $this->listings[$key];
        }

        $ttl = (int) config('app-toolkit.cache_ttl', 30);
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

    public function exists(Server $server, string $path): bool
    {
        $path = trim($path, '/');
        $parent = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';

        foreach ($this->directory($server, $parent) ?? [] as $entry) {
            if (($entry['name'] ?? null) === basename($path)) {
                return true;
            }
        }

        return false;
    }

    public function contents(Server $server, string $path): ?string
    {
        try {
            return app(DaemonFileRepository::class)
                ->setServer($server)
                ->getContent('/' . ltrim($path, '/'), (int) config('app-toolkit.max_read_bytes', 2097152));
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

    /**
     * Startup variable values for this server, keyed by env_variable name.
     *
     * Two queries for any number of names, rather than two per name. Goes through
     * serverVariables() rather than variables(): the latter's leftJoin closure
     * captures $this->id, and Laravel builds eager-loaded relations from a fresh
     * empty model, which silently nulls every server_value.
     *
     * @param  list<string>  $names
     * @return array<string, string|null>
     */
    public function variables(Server $server, array $names): array
    {
        $eggVariables = EggVariable::query()
            ->where('egg_id', $server->egg_id)
            ->whereIn('env_variable', $names)
            ->get(['id', 'env_variable', 'default_value']);

        $overrides = $server->serverVariables()
            ->whereIn('variable_id', $eggVariables->pluck('id'))
            ->pluck('variable_value', 'variable_id');

        $values = array_fill_keys($names, null);

        foreach ($eggVariables as $variable) {
            // An override row exists but may be blank, in which case the egg default
            // is what the startup command actually sees.
            $value = trim((string) ($overrides[$variable->id] ?? ''));

            if ($value === '') {
                $value = trim((string) ($variable->default_value ?? ''));
            }

            $values[$variable->env_variable] = $value === '' ? null : $value;
        }

        return $values;
    }

    public function variable(Server $server, string $name): ?string
    {
        return $this->variables($server, [$name])[$name] ?? null;
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
    }

    private function cacheKey(Server $server, string $path): string
    {
        return 'app-toolkit:dir:' . $this->generation($server) . ':' . $server->uuid . ':' . $path;
    }

    private function generation(Server $server): int
    {
        return $this->generations[$server->uuid] ??= (int) Cache::get($this->generationKey($server), 0);
    }

    private function generationKey(Server $server): string
    {
        return 'app-toolkit:gen:' . $server->uuid;
    }
}
