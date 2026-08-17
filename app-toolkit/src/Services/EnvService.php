<?php

namespace Catualus\AppToolkit\Services;

use App\Models\Server;
use Catualus\AppToolkit\Support\AppServer;
use Catualus\AppToolkit\Support\DotEnvFile;

class EnvService
{
    public const FILE = '.env';

    public function __construct(private readonly AppServer $app) {}

    public function exists(Server $server): bool
    {
        return $this->app->exists($server, self::FILE);
    }

    /**
     * @return list<array{key: string, value: string, secret: bool}>
     */
    public function entries(Server $server): array
    {
        $raw = $this->app->contents($server, self::FILE);

        if ($raw === null) {
            return [];
        }

        $entries = [];

        foreach (DotEnvFile::parse($raw)->all() as $key => $value) {
            $entries[] = [
                'key' => $key,
                'value' => $value,
                'secret' => $this->looksSecret($key),
            ];
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{ok: bool, message: string}
     */
    public function save(Server $server, array $values): array
    {
        $raw = $this->app->contents($server, self::FILE);

        if ($raw === null) {
            return ['ok' => false, 'message' => 'Could not read .env.'];
        }

        $normalised = [];

        foreach ($values as $key => $value) {
            $normalised[$key] = $value === null ? '' : (string) $value;
        }

        $updated = DotEnvFile::parse($raw)->merge($normalised)->render();

        if ($updated === $raw) {
            return ['ok' => true, 'message' => 'No changes to save.'];
        }

        if (!$this->app->put($server, self::FILE, $updated)) {
            return ['ok' => false, 'message' => 'Could not write .env.'];
        }

        return ['ok' => true, 'message' => 'Saved. Restart the app for the changes to take effect.'];
    }

    /**
     * Decides which values start hidden. This is shoulder-surfing cover, not a
     * security boundary - anyone who can open this page can already read the raw
     * file in the file manager.
     */
    private function looksSecret(string $key): bool
    {
        foreach ((array) config('app-toolkit.secret_patterns', []) as $needle) {
            if (str_contains(mb_strtoupper($key), mb_strtoupper((string) $needle))) {
                return true;
            }
        }

        return false;
    }
}
