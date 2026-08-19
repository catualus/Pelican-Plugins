<?php

namespace Catualus\GmodToolkit\Services;

use App\Models\EggVariable;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads a server's workshop collection through Steam's public endpoints.
 *
 * GetCollectionDetails and GetPublishedFileDetails both accept unauthenticated
 * POSTs, so no Steam API key is needed or asked for.
 */
class WorkshopService
{
    private const COLLECTION = 'https://api.steampowered.com/ISteamRemoteStorage/GetCollectionDetails/v1/';

    private const DETAILS = 'https://api.steampowered.com/ISteamRemoteStorage/GetPublishedFileDetails/v1/';

    /**
     * Steam accepts a form-encoded array of ids, but a collection of several hundred
     * makes a request large enough that Steam answers with an empty body rather than
     * an error. Batching keeps every request comfortably small.
     */
    private const BATCH = 100;

    /** Garry's Mod. An item from any other game in the collection is a mistake. */
    public const GMOD_APP_ID = 4000;

    /**
     * The collection id comes from the WORKSHOP_ID startup variable the Garry's Mod
     * egg already defines.
     *
     * Deliberately avoids Server::variables(): that relation's leftJoin closure
     * captures $this->id, and Laravel builds eager-loaded relations from a fresh
     * empty model, so eager loading it silently nulls every server_value.
     * serverVariables() is a plain hasMany with no such captured state.
     */
    public function collectionId(Server $server): ?string
    {
        foreach ((array) config('gmod-toolkit.workshop_variables', ['WORKSHOP_ID']) as $name) {
            $id = trim((string) ($this->serverValue($server, $name) ?? $this->eggDefault($server, $name) ?? ''));

            // Steam only accepts numeric ids; anything else is a misconfiguration.
            if ($id !== '' && ctype_digit($id)) {
                return $id;
            }
        }

        return null;
    }

    private function serverValue(Server $server, string $name): ?string
    {
        return $server->serverVariables()
            ->whereHas('variable', fn ($query) => $query->where('env_variable', $name))
            ->value('variable_value');
    }

    /**
     * Used when the server has no override row of its own.
     */
    private function eggDefault(Server $server, string $name): ?string
    {
        return EggVariable::query()
            ->where('egg_id', $server->egg_id)
            ->where('env_variable', $name)
            ->value('default_value');
    }

    /**
     * @return list<array{id: string, title: string, size: int, updated: ?int, created: ?int, subscriptions: int, favourited: int, app_id: int, foreign: bool, banned: bool, ban_reason: ?string, preview: ?string, url: string}>
     */
    public function addons(string $collectionId): array
    {
        $ttl = (int) config('gmod-toolkit.workshop_cache_ttl', 900);
        $key = 'gmod-toolkit:workshop:' . $collectionId;

        if ($ttl > 0 && is_array($cached = Cache::get($key))) {
            return $cached;
        }

        try {
            $ids = $this->children($collectionId);

            $addons = $ids === [] ? [] : $this->details($ids);
        } catch (Throwable) {
            // Steam being unreachable is not worth an error page - the caller shows
            // an empty state, and nothing is cached so the next load retries.
            return [];
        }

        if ($ttl > 0 && $addons !== []) {
            Cache::put($key, $addons, $ttl);
        }

        return $addons;
    }

    /**
     * Everything the overview and the charts need about a collection, derived once
     * so a stat tile and the bar beside it can never disagree.
     *
     * @param  list<array<string, mixed>>  $addons
     * @return array{count: int, size: int, largest: ?array<string, mixed>, median: int, recent: int, banned: int, foreign: int, undated: int, freshness: array<string, int>}
     */
    public function summarise(array $addons, int $recentDays): array
    {
        $sizes = array_map(static fn (array $a): int => (int) $a['size'], $addons);
        sort($sizes);

        $recentCutoff = now()->subDays($recentDays)->getTimestamp();
        $monthCutoff = now()->subDays(30)->getTimestamp();
        $yearCutoff = now()->subDays(365)->getTimestamp();

        $freshness = ['recent' => 0, 'month' => 0, 'year' => 0, 'stale' => 0, 'unknown' => 0];

        foreach ($addons as $addon) {
            $updated = $addon['updated'] ?? null;

            $bucket = match (true) {
                $updated === null => 'unknown',
                $updated >= $recentCutoff => 'recent',
                $updated >= $monthCutoff => 'month',
                $updated >= $yearCutoff => 'year',
                default => 'stale',
            };

            $freshness[$bucket]++;
        }

        $largest = $addons === [] ? null : array_reduce(
            $addons,
            static fn (?array $carry, array $addon): array => ($carry === null || $addon['size'] > $carry['size']) ? $addon : $carry,
        );

        return [
            'count' => count($addons),
            'size' => array_sum($sizes),
            'largest' => $largest,
            'median' => $sizes === [] ? 0 : (int) $sizes[intdiv(count($sizes), 2)],
            'recent' => $freshness['recent'],
            'banned' => count(array_filter($addons, static fn (array $a): bool => (bool) $a['banned'])),
            'foreign' => count(array_filter($addons, static fn (array $a): bool => (bool) $a['foreign'])),
            'undated' => $freshness['unknown'],
            'freshness' => $freshness,
        ];
    }

    /**
     * @return list<string>
     */
    private function children(string $collectionId): array
    {
        $response = Http::asForm()
            ->timeout(15)
            ->post(self::COLLECTION, [
                'collectioncount' => 1,
                'publishedfileids' => [$collectionId],
            ])
            ->throw()
            ->json();

        $collection = $response['response']['collectiondetails'][0] ?? [];

        // result 1 is success; 9 means the collection id is not a collection.
        if ((int) ($collection['result'] ?? 0) !== 1) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (array $child): string => (string) ($child['publishedfileid'] ?? ''),
            $collection['children'] ?? [],
        )));
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function details(array $ids): array
    {
        $addons = [];

        foreach (array_chunk($ids, self::BATCH) as $batch) {
            foreach ($this->detailsBatch($batch) as $addon) {
                $addons[] = $addon;
            }
        }

        usort($addons, fn (array $a, array $b): int => ($b['updated'] ?? 0) <=> ($a['updated'] ?? 0));

        return $addons;
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function detailsBatch(array $ids): array
    {
        $response = Http::asForm()
            ->timeout(20)
            ->post(self::DETAILS, [
                'itemcount' => count($ids),
                'publishedfileids' => $ids,
            ])
            ->throw()
            ->json();

        $addons = [];

        foreach ($response['response']['publishedfiledetails'] ?? [] as $item) {
            $id = (string) ($item['publishedfileid'] ?? '');

            if ($id === '') {
                continue;
            }

            // Steam returns file_size as a string on some items and an int on others.
            $appId = (int) ($item['consumer_app_id'] ?? $item['creator_app_id'] ?? 0);

            $addons[] = [
                'id' => $id,
                'title' => (string) ($item['title'] ?? $id),
                'size' => (int) ($item['file_size'] ?? 0),
                'updated' => isset($item['time_updated']) ? (int) $item['time_updated'] : null,
                'created' => isset($item['time_created']) ? (int) $item['time_created'] : null,
                'subscriptions' => (int) ($item['subscriptions'] ?? 0),
                'favourited' => (int) ($item['favorited'] ?? 0),
                'app_id' => $appId,
                // An item from another game will never mount, and the collection page
                // gives no hint of it - so say so here.
                'foreign' => $appId !== 0 && $appId !== self::GMOD_APP_ID,
                'banned' => (bool) ($item['banned'] ?? false),
                'ban_reason' => ($item['ban_reason'] ?? '') ?: null,
                'preview' => ($item['preview_url'] ?? '') ?: null,
                'url' => 'https://steamcommunity.com/sharedfiles/filedetails/?id=' . $id,
            ];
        }

        return $addons;
    }
}
