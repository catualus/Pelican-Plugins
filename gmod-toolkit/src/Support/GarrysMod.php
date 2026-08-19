<?php

namespace Catualus\GmodToolkit\Support;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
     * @param  bool  $fresh  skip both caches and ask the daemon
     * @return list<array<string, mixed>>|null  null when the daemon cannot be reached
     */
    public function directory(Server $server, string $path, bool $fresh = false): ?array
    {
        $key = $server->uuid . ':' . $path;

        if (!$fresh && array_key_exists($key, $this->listings)) {
            return $this->listings[$key];
        }

        $ttl = (int) config('gmod-toolkit.cache_ttl', 60);
        $cacheKey = $this->cacheKey($server, $path);

        if (!$fresh && $ttl > 0 && is_array($cached = Cache::get($cacheKey))) {
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
     * Writes a file, creating it when absent.
     */
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
     * Fetches a remote file and writes it onto the server.
     *
     * The daemon has its own remote-download endpoint, and using it would be the
     * obvious choice - but Wings refuses any URL whose response carries no
     * Content-Length ("downloader: request is missing ContentLength"), because it
     * checks the size against the server's disk quota before writing. GitHub builds
     * archives on demand and streams them chunked, so none of its archive URLs ever
     * send one, and that endpoint can never fetch them.
     *
     * So the panel does the download and hands the daemon the bytes. The catalogue's
     * archives are single-digit megabytes, and anything larger than max_download_bytes
     * is refused rather than held in memory.
     *
     * @return array{ok: bool, message: string}
     */
    public function fetchToServer(Server $server, string $url, string $directory, string $filename): array
    {
        $limit = (int) config('gmod-toolkit.max_download_bytes', 33554432);

        try {
            // Streamed and counted as it arrives. Checking the size after the fact
            // would mean the whole archive was already in memory by the time we
            // decided it was too big - and Content-Length cannot be checked up front
            // here, because GitHub generating these archives on demand is exactly why
            // it does not send one.
            $response = Http::timeout((int) config('gmod-toolkit.download_timeout', 20))
                ->withOptions(['stream' => true])
                ->get($url);

            if (!$response->successful()) {
                return ['ok' => false, 'message' => 'The download returned HTTP ' . $response->status() . '.'];
            }

            $stream = $response->toPsrResponse()->getBody();
            $body = '';

            while (!$stream->eof()) {
                $body .= $stream->read(262144);

                if (strlen($body) > $limit) {
                    $stream->close();

                    return [
                        'ok' => false,
                        'message' => sprintf(
                            'The archive is larger than the %s limit, so the download was stopped. Raise max_download_bytes if you want to allow it.',
                            Bytes::human($limit),
                        ),
                    ];
                }
            }

            $stream->close();
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'The download did not finish: ' . $this->reason($e)];
        }

        $size = strlen($body);

        if ($size === 0) {
            return ['ok' => false, 'message' => 'The download was empty.'];
        }

        // A zip that does not start with the local file header signature is not a zip -
        // usually an HTML error page served with a 200.
        if (!str_starts_with($body, "PK\x03\x04")) {
            return ['ok' => false, 'message' => 'What was downloaded is not a zip archive.'];
        }

        if (!$this->put($server, trim($directory, '/') . '/' . $filename, $body)) {
            return ['ok' => false, 'message' => 'Downloaded, but the daemon would not write it to disk.'];
        }

        return ['ok' => true, 'message' => 'Downloaded ' . Bytes::human($size) . '.'];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function decompress(Server $server, string $root, string $file): array
    {
        try {
            app(DaemonFileRepository::class)
                ->setServer($server)
                ->decompressFile('/' . trim($root, '/'), $file);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $this->reason($e)];
        }

        $this->forget($server);

        return ['ok' => true, 'message' => 'Extracted.'];
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

    /**
     * @param  list<string>  $files
     */
    public function delete(Server $server, string $root, array $files): bool
    {
        try {
            app(DaemonFileRepository::class)
                ->setServer($server)
                ->deleteFiles('/' . trim($root, '/'), $files);
        } catch (Throwable) {
            return false;
        }

        $this->forget($server);

        return true;
    }

    public function createDirectory(Server $server, string $name, string $path): bool
    {
        try {
            app(DaemonFileRepository::class)
                ->setServer($server)
                ->createDirectory($name, '/' . trim($path, '/'));
        } catch (Throwable) {
            return false;
        }

        $this->forget($server);

        return true;
    }

    /**
     * Directory names directly under a path, which is all the installer needs to
     * work out which folder an archive just created.
     *
     * @param  bool  $fresh  ask the daemon rather than trusting any cache
     * @return list<string>
     */
    public function directoryNames(Server $server, string $path, bool $fresh = false): array
    {
        $names = [];

        foreach ($this->directory($server, $path, $fresh) ?? [] as $entry) {
            if (($entry['directory'] ?? false) === true && is_string($entry['name'] ?? null)) {
                $names[] = $entry['name'];
            }
        }

        return $names;
    }

    /**
     * Daemon failures arrive as HTTP exceptions whose messages are long and full of
     * URLs. The first line is the useful part.
     */
    private function reason(Throwable $e): string
    {
        $message = trim(strtok($e->getMessage(), "\n") ?: '');

        return $message === '' ? 'The daemon rejected the request.' : $message;
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

    /**
     * Invalidates every cached listing for this server.
     *
     * This bumps a per-server generation number that is part of each listing's cache
     * key, rather than deleting the keys it knows about. The difference matters: the
     * in-memory map only holds what *this* request happened to read, so deleting from
     * it could never clear a listing cached by an earlier request. Anything that runs
     * as a sequence of requests - the addon installer, the disk scan - would then keep
     * reading a directory as it was up to cache_ttl seconds ago, and act on it.
     *
     * That is not hypothetical. It is what made the installer rename a folder into
     * place correctly and then report that the folder was not there.
     */
    public function forget(Server $server): void
    {
        $generation = $this->generation($server) + 1;

        $this->generations[$server->uuid] = $generation;

        // A day is far longer than any listing TTL, so the counter always outlives
        // the entries it invalidates.
        Cache::put($this->generationKey($server), $generation, 86400);

        foreach (array_keys($this->listings) as $key) {
            if (str_starts_with($key, $server->uuid . ':')) {
                unset($this->listings[$key]);
            }
        }
    }

    private function cacheKey(Server $server, string $path): string
    {
        return 'gmod-toolkit:dir:' . $this->generation($server) . ':' . $server->uuid . ':' . $path;
    }

    private function generation(Server $server): int
    {
        return $this->generations[$server->uuid] ??= (int) Cache::get($this->generationKey($server), 0);
    }

    private function generationKey(Server $server): string
    {
        return 'gmod-toolkit:gen:' . $server->uuid;
    }
}
