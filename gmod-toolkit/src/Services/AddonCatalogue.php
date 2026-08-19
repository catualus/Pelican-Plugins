<?php

namespace Catualus\GmodToolkit\Services;

use App\Models\Server;
use Catualus\GmodToolkit\Support\GarrysMod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The curated list of installable addons, matched against what is already on disk.
 *
 * Entries live in config, not in code, so a server owner can add their own without
 * touching the plugin. Every shipped entry names a fixed owner/repo - there is no
 * path from the interface to an arbitrary URL, which is the whole point: the panel
 * is downloading and extracting code onto a live game server, and the set of places
 * it will do that from has to be fixed in advance.
 */
class AddonCatalogue
{
    /** Written into addons/ so an install can be traced back afterwards. */
    public const MANIFEST = 'garrysmod/addons/.gmod-toolkit-installed.json';

    /** @var array<string, array<string, mixed>> */
    private array $manifests = [];

    public function __construct(private readonly GarrysMod $gmod) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(Server $server): array
    {
        $installed = $this->installedFolders($server);
        $manifest = $this->manifest($server);

        $entries = [];

        foreach ((array) config('gmod-toolkit.catalogue', []) as $entry) {
            $entries[] = $this->describe($entry, $installed, $manifest);
        }

        // Not installed first - the page's job is to offer things, and an already
        // installed addon is a status line rather than an action.
        usort($entries, static fn (array $a, array $b): int => [$a['installed'], mb_strtolower($a['name'])]
            <=> [$b['installed'], mb_strtolower($b['name'])]);

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(Server $server, string $id): ?array
    {
        foreach ($this->all($server) as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function presets(Server $server): array
    {
        $catalogue = collect($this->all($server))->keyBy('id');
        $presets = [];

        foreach ((array) config('gmod-toolkit.presets', []) as $preset) {
            $members = [];

            foreach ((array) ($preset['addons'] ?? []) as $id) {
                if ($entry = $catalogue->get($id)) {
                    $members[] = $entry;
                }
            }

            if ($members === []) {
                continue;
            }

            $pending = array_values(array_filter($members, static fn (array $m): bool => !$m['installed']));

            $presets[] = [
                'id' => (string) ($preset['id'] ?? ''),
                'name' => (string) ($preset['name'] ?? ''),
                'description' => (string) ($preset['description'] ?? ''),
                'members' => $members,
                'pending' => $pending,
                'size' => null,
                'complete' => $pending === [],
            ];
        }

        return $presets;
    }

    /**
     * Resolves an entry's dependencies into the order they must be installed in.
     * Shallow on purpose - the catalogue's dependency graph is one level deep and a
     * general solver would be more machinery than the problem deserves.
     *
     * @return list<array<string, mixed>>
     */
    public function withDependencies(Server $server, string $id): array
    {
        $entry = $this->find($server, $id);

        if ($entry === null) {
            return [];
        }

        $ordered = [];

        foreach ($entry['requires'] as $dependency) {
            $resolved = $this->find($server, $dependency);

            if ($resolved !== null && !$resolved['installed']) {
                $ordered[] = $resolved;
            }
        }

        $ordered[] = $entry;

        return $ordered;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, bool>  $installed  folder name => enabled
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function describe(array $entry, array $installed, array $manifest): array
    {
        $id = (string) ($entry['id'] ?? '');
        $folder = (string) ($entry['folder'] ?? $id);
        $source = (string) ($entry['source'] ?? 'github');
        $repo = $entry['repo'] ?? null;
        $ref = (string) ($entry['ref'] ?? 'master');
        $target = trim((string) ($entry['target'] ?? 'addons'), '/');

        // A folder under any of the detect aliases counts as installed, so an addon
        // put there by hand is recognised rather than offered again.
        $aliases = array_values(array_unique([$folder, ...(array) ($entry['detect'] ?? [])]));
        $match = null;

        foreach ($aliases as $alias) {
            if (array_key_exists(mb_strtolower($alias), $installed)) {
                $match = $installed[mb_strtolower($alias)];

                break;
            }
        }

        $record = $manifest[$id] ?? null;
        $workshopId = isset($entry['workshop_id']) ? (string) $entry['workshop_id'] : null;
        $isGithub = $source === 'github' && is_string($repo);

        $url = match (true) {
            $isGithub => 'https://github.com/' . $repo,
            $workshopId !== null => 'https://steamcommunity.com/sharedfiles/filedetails/?id=' . $workshopId,
            default => null,
        };

        return [
            'id' => $id,
            'name' => (string) ($entry['name'] ?? $id),
            'description' => (string) ($entry['description'] ?? ''),
            'category' => (string) ($entry['category'] ?? 'Other'),
            'source' => $source,
            'repo' => is_string($repo) ? $repo : null,
            'ref' => $ref,
            'target' => $target,
            'folder' => $folder,
            'requires' => array_values((array) ($entry['requires'] ?? [])),
            'notes' => $entry['notes'] ?? null,
            'workshop_id' => $workshopId,
            'url' => $url,
            // codeload serves the archive directly, with no redirect and no auth, which
            // is what lets the daemon fetch it in one foreground request.
            'zip_url' => $isGithub
                ? sprintf('https://codeload.github.com/%s/zip/refs/heads/%s', $repo, $ref)
                : null,
            'installable' => $isGithub && $target !== '',
            'installed' => $match !== null,
            'installed_folder' => $match['name'] ?? null,
            'enabled' => $match['enabled'] ?? null,
            // Where it actually is, which is not always garrysmod/<target>/<folder>:
            // it may be under a detect alias, or sitting in addons/__disabled.
            'installed_root' => $match['root'] ?? null,
            'installed_path' => $match === null ? null : $match['root'] . '/' . $match['name'],
            'holds_config' => (bool) ($entry['holds_config'] ?? false),
            'installed_sha' => is_array($record) ? ($record['sha'] ?? null) : null,
            'installed_at' => is_array($record) && isset($record['installed_at'])
                ? $this->parseDate($record['installed_at'])
                : null,
            'installed_ref' => is_array($record) ? ($record['ref'] ?? null) : null,
        ];
    }

    /**
     * What is on disk, covering addons/, addons/__disabled and gamemodes/.
     *
     * Keyed on the lowercased folder name, but carrying the real name and the
     * directory it was found in - an addon can be installed under one of its detect
     * aliases, or sitting in __disabled, and anything that wants to remove it needs
     * to know which rather than assume the catalogue's own folder name.
     *
     * @return array<string, array{name: string, enabled: bool, root: string}>
     */
    private function installedFolders(Server $server): array
    {
        $folders = [];

        $roots = [
            ['root' => 'garrysmod/addons', 'enabled' => true],
            ['root' => 'garrysmod/addons/' . AddonService::DISABLED_DIR, 'enabled' => false],
            ['root' => 'garrysmod/gamemodes', 'enabled' => true],
        ];

        foreach ($roots as $source) {
            foreach ($this->gmod->directoryNames($server, $source['root']) as $name) {
                if ($name === AddonService::DISABLED_DIR) {
                    continue;
                }

                $folders[mb_strtolower($name)] = [
                    'name' => $name,
                    'enabled' => $source['enabled'],
                    'root' => $source['root'],
                ];
            }
        }

        return $folders;
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(Server $server): array
    {
        if (array_key_exists($server->uuid, $this->manifests)) {
            return $this->manifests[$server->uuid];
        }

        $raw = $this->gmod->exists($server, self::MANIFEST)
            ? $this->gmod->contents($server, self::MANIFEST)
            : null;

        $decoded = $raw === null ? null : json_decode($raw, true);

        return $this->manifests[$server->uuid] = is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function recordInstall(Server $server, string $id, array $record): void
    {
        $manifest = $this->manifest($server);
        $manifest[$id] = $record;

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json !== false && $this->gmod->put($server, self::MANIFEST, $json)) {
            $this->manifests[$server->uuid] = $manifest;
        }
    }

    /**
     * Drops an addon from the manifest, leaving the disk alone.
     *
     * Used both when uninstalling and when the record turns out to describe an addon
     * that is no longer there - a stale entry is worth removing on its own, because
     * a record that disagrees with the filesystem is how a failed install gets
     * mistaken for a working one.
     */
    public function forgetInstall(Server $server, string $id): bool
    {
        $manifest = $this->manifest($server);

        if (!array_key_exists($id, $manifest)) {
            return true;
        }

        unset($manifest[$id]);

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false || !$this->gmod->put($server, self::MANIFEST, $json)) {
            return false;
        }

        $this->manifests[$server->uuid] = $manifest;

        return true;
    }

    public function forgetManifest(Server $server): void
    {
        unset($this->manifests[$server->uuid]);
    }

    /**
     * The current head commit of an entry's branch, for the update check.
     *
     * Unauthenticated GitHub allows 60 requests an hour, so this is cached hard and
     * every failure degrades to "unknown" rather than surfacing an error - not
     * knowing whether an addon is out of date is a much smaller problem than a page
     * that will not load because GitHub rate-limited it.
     */
    public function headSha(string $repo, string $ref): ?string
    {
        $ttl = (int) config('gmod-toolkit.github_cache_ttl', 21600);
        $key = 'gmod-toolkit:sha:' . $repo . ':' . $ref;

        if ($ttl <= 0) {
            return $this->fetchHeadSha($repo, $ref);
        }

        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached === '' ? null : $cached;
        }

        $sha = $this->fetchHeadSha($repo, $ref);

        // A miss is cached too, briefly, so a rate-limited panel does not retry on
        // every single page load and dig itself deeper.
        Cache::put($key, $sha ?? '', $sha === null ? min($ttl, 900) : $ttl);

        return $sha;
    }

    private function fetchHeadSha(string $repo, string $ref): ?string
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get(sprintf('https://api.github.com/repos/%s/commits/%s', $repo, $ref));

            if (!$response->successful()) {
                return null;
            }

            $sha = $response->json('sha');
        } catch (Throwable) {
            return null;
        }

        return is_string($sha) && $sha !== '' ? $sha : null;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
