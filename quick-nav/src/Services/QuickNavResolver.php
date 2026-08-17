<?php

namespace Catualus\QuickNav\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Catualus\QuickNav\Enums\ServerFamily;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Works out which Quick Access shortcuts to offer for a server.
 *
 * These shortcuts render in the sidebar on every server-panel page, so listings are
 * cached twice over: in-memory for the current request (scoped binding), and in the
 * application cache for quick-nav.cache_ttl seconds across requests.
 */
class QuickNavResolver
{
    /** @var array<string, array<string, bool>|null> cache key => (entry name => is directory) */
    private array $listings = [];

    /** @var array<string, ServerFamily> */
    private array $families = [];

    /** @var array<string, list<array{label: string, path: string, icon: string}>> */
    private array $shortcuts = [];

    /**
     * Memoised because every registered shortcut action asks for this during a single
     * render, and the toolbar dropdown asks again to decide whether to show at all.
     *
     * @return list<array{label: string, path: string, icon: string}>
     */
    public function shortcuts(Server $server): array
    {
        return $this->shortcuts[$server->uuid] ??= array_values(array_filter(
            config('quick-nav.presets.' . $this->family($server)->value, []),
            fn (array $preset) => $this->exists($server, $preset['path']),
        ));
    }

    public function family(Server $server): ServerFamily
    {
        return $this->families[$server->uuid] ??= $this->detectFamily($server);
    }

    private function detectFamily(Server $server): ServerFamily
    {
        $family = ServerFamily::fromServer($server);

        // Egg metadata cannot separate Garry's Mod from any other Source game, so
        // fall back to the garrysmod/ directory every GMod install ships with. When
        // the daemon cannot answer, stay on Source rather than guessing GMod.
        if ($family === ServerFamily::Source && $this->exists($server, 'garrysmod', whenUnknown: false)) {
            return ServerFamily::GarrysMod;
        }

        return $family;
    }

    /**
     * @param  bool  $whenUnknown  what to answer when the daemon cannot be reached
     */
    private function exists(Server $server, string $path, bool $whenUnknown = true): bool
    {
        $path = trim($path, '/');

        if ($path === '') {
            return true;
        }

        $parent = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';

        // Never probe inside a directory already known to be missing - the daemon
        // would 404 and that failure is indistinguishable from it being offline.
        if ($parent !== '' && ! $this->exists($server, $parent, $whenUnknown)) {
            return false;
        }

        $listing = $this->listing($server, $parent);

        if ($listing === null) {
            return $whenUnknown;
        }

        return ($listing[basename($path)] ?? false) === true;
    }

    /**
     * @return array<string, bool>|null  entry name => is directory, null when unreachable
     */
    private function listing(Server $server, string $path): ?array
    {
        $key = $server->uuid . ':' . $path;

        if (array_key_exists($key, $this->listings)) {
            return $this->listings[$key];
        }

        $ttl = (int) config('quick-nav.cache_ttl', 600);
        $cacheKey = 'quick-nav:listing:' . $key;

        if ($ttl > 0) {
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                return $this->listings[$key] = $cached;
            }
        }

        try {
            $entries = app(DaemonFileRepository::class)
                ->setServer($server)
                ->getDirectory('/' . $path);
        } catch (Throwable) {
            // Server is installing or suspended, or the node is down. Deliberately not
            // cached, so shortcuts come back as soon as the node does. Callers decide
            // whether this means "show everything" or "show nothing".
            return $this->listings[$key] = null;
        }

        $listing = [];

        foreach ($entries as $entry) {
            $name = $entry['name'] ?? null;

            if (is_string($name) && $name !== '') {
                $listing[$name] = (bool) ($entry['directory'] ?? false);
            }
        }

        if ($ttl > 0) {
            Cache::put($cacheKey, $listing, $ttl);
        }

        return $this->listings[$key] = $listing;
    }
}
