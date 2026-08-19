<?php

namespace Catualus\MinecraftToolkit\Services;

use App\Models\Server;
use Catualus\MinecraftToolkit\Support\Minecraft;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads and writes the JSON player lists Minecraft keeps at the server root:
 * ops.json, whitelist.json, banned-players.json and banned-ips.json.
 */
class PlayerListService
{
    public function __construct(private readonly Minecraft $minecraft) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function read(Server $server, string $list): array
    {
        $file = $this->file($list);

        if ($file === null) {
            return [];
        }

        $contents = $this->minecraft->contents($server, $file);

        if ($contents === null || trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function remove(Server $server, string $list, string $identifier): array
    {
        $key = $list === 'banned-ips' ? 'ip' : 'uuid';

        $entries = array_values(array_filter(
            $this->read($server, $list),
            fn (array $entry): bool => (string) ($entry[$key] ?? '') !== $identifier,
        ));

        return $this->write($server, $list, $entries, 'Removed.');
    }

    /**
     * Removes several entries in one write.
     *
     * Deliberately not a loop over remove(): each call re-reads and rewrites the
     * whole file, so removing twenty players one at a time would be twenty
     * read-modify-write round trips against a file the server may also be holding.
     *
     * @param  list<string>  $identifiers
     * @return array{ok: bool, message: string}
     */
    public function removeMany(Server $server, string $list, array $identifiers): array
    {
        if ($identifiers === []) {
            return ['ok' => true, 'message' => 'Nothing selected.'];
        }

        $key = $list === 'banned-ips' ? 'ip' : 'uuid';
        $wanted = array_flip($identifiers);

        $before = $this->read($server, $list);

        $entries = array_values(array_filter(
            $before,
            static fn (array $entry): bool => !isset($wanted[(string) ($entry[$key] ?? '')]),
        ));

        $removed = count($before) - count($entries);

        if ($removed === 0) {
            return ['ok' => true, 'message' => 'Nothing matched.'];
        }

        return $this->write(
            $server,
            $list,
            $entries,
            "Removed {$removed} " . ($removed === 1 ? 'entry.' : 'entries.'),
        );
    }

    /**
     * Adds a player by name, resolving the UUID through Mojang - Minecraft matches
     * these files on UUID, so an entry without one is silently ignored by the server.
     *
     * @return array{ok: bool, message: string}
     */
    public function add(Server $server, string $list, string $name, int $level = 4): array
    {
        $name = trim($name);

        if ($name === '') {
            return ['ok' => false, 'message' => 'Enter a username.'];
        }

        if ($list === 'banned-ips') {
            $entries = $this->read($server, $list);
            $entries[] = [
                'ip' => $name,
                'created' => now()->format('Y-m-d H:i:s O'),
                'source' => 'Pelican',
                'expires' => 'forever',
                'reason' => 'Banned by an operator.',
            ];

            return $this->write($server, $list, $entries, "Banned {$name}.");
        }

        $profile = $this->lookup($name);

        if ($profile === null) {
            return ['ok' => false, 'message' => "Mojang has no account called {$name}."];
        }

        $entries = $this->read($server, $list);

        foreach ($entries as $entry) {
            if (strcasecmp((string) ($entry['name'] ?? ''), $profile['name']) === 0) {
                return ['ok' => false, 'message' => "{$profile['name']} is already on that list."];
            }
        }

        $entries[] = match ($list) {
            'ops' => [
                'uuid' => $profile['uuid'],
                'name' => $profile['name'],
                'level' => max(1, min(4, $level)),
                'bypassesPlayerLimit' => false,
            ],
            'banned-players' => [
                'uuid' => $profile['uuid'],
                'name' => $profile['name'],
                'created' => now()->format('Y-m-d H:i:s O'),
                'source' => 'Pelican',
                'expires' => 'forever',
                'reason' => 'Banned by an operator.',
            ],
            default => [
                'uuid' => $profile['uuid'],
                'name' => $profile['name'],
            ],
        };

        return $this->write($server, $list, $entries, "Added {$profile['name']}.");
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array{ok: bool, message: string}
     */
    private function write(Server $server, string $list, array $entries, string $success): array
    {
        $file = $this->file($list);

        if ($file === null) {
            return ['ok' => false, 'message' => 'Unknown list.'];
        }

        $json = json_encode(array_values($entries), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false || !$this->minecraft->put($server, $file, $json)) {
            return ['ok' => false, 'message' => "Could not write {$file}."];
        }

        return ['ok' => true, 'message' => $success . ' Run /reload or restart for the server to pick it up.'];
    }

    /**
     * Mojang publishes the same lookup at two hosts.
     *
     * api.mojang.com is the long-standing one and rate limits aggressively;
     * api.minecraftservices.com is the current one. Both return the same
     * {id, name} shape, so trying each in turn costs nothing and means a rate limit
     * or an outage on one does not stop somebody being added to the whitelist.
     */
    private const LOOKUPS = [
        'https://api.minecraftservices.com/minecraft/profile/lookup/name/',
        'https://api.mojang.com/users/profiles/minecraft/',
    ];

    /**
     * @return array{uuid: string, name: string}|null
     */
    private function lookup(string $name): ?array
    {
        $id = '';
        $resolved = $name;

        foreach (self::LOOKUPS as $endpoint) {
            try {
                $response = Http::timeout(10)->get($endpoint . rawurlencode($name));
            } catch (Throwable) {
                continue;
            }

            // A 404 means Mojang has no such account, which is an answer - retrying
            // it against the other host would only be slower.
            if ($response->status() === 404) {
                return null;
            }

            if (!$response->successful()) {
                continue;
            }

            $id = (string) ($response->json('id') ?? '');
            $resolved = (string) ($response->json('name') ?? $name);

            if (strlen($id) === 32) {
                break;
            }
        }

        if (strlen($id) !== 32) {
            return null;
        }

        // Mojang returns the UUID undashed; these files need the dashed form.
        return [
            'uuid' => implode('-', [
                substr($id, 0, 8), substr($id, 8, 4), substr($id, 12, 4), substr($id, 16, 4), substr($id, 20),
            ]),
            'name' => $resolved,
        ];
    }

    private function file(string $list): ?string
    {
        return match ($list) {
            'ops' => 'ops.json',
            'whitelist' => 'whitelist.json',
            'banned-players' => 'banned-players.json',
            'banned-ips' => 'banned-ips.json',
            default => null,
        };
    }
}
