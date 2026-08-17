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
     * @return list<array{id: string, title: string, size: int, updated: ?int, created: ?int, subscriptions: int, banned: bool, ban_reason: ?string, preview: ?string, url: string}>
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

            $addons[] = [
                'id' => $id,
                'title' => (string) ($item['title'] ?? $id),
                'size' => (int) ($item['file_size'] ?? 0),
                'updated' => isset($item['time_updated']) ? (int) $item['time_updated'] : null,
                'created' => isset($item['time_created']) ? (int) $item['time_created'] : null,
                'subscriptions' => (int) ($item['subscriptions'] ?? 0),
                'banned' => (bool) ($item['banned'] ?? false),
                'ban_reason' => ($item['ban_reason'] ?? '') ?: null,
                'preview' => ($item['preview_url'] ?? '') ?: null,
                'url' => 'https://steamcommunity.com/sharedfiles/filedetails/?id=' . $id,
            ];
        }

        usort($addons, fn (array $a, array $b): int => ($b['updated'] ?? 0) <=> ($a['updated'] ?? 0));

        return $addons;
    }
}
