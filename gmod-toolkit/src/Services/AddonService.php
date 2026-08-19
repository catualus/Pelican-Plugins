<?php

namespace Catualus\GmodToolkit\Services;

use App\Models\Server;
use Catualus\GmodToolkit\Support\GarrysMod;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads garrysmod/addons, and toggles addons by moving them in and out of the
 * addons/__disabled folder - the convention already in use on this server, and the
 * only one Garry's Mod itself respects (it loads every direct child of addons/).
 */
class AddonService
{
    public const DISABLED_DIR = '__disabled';

    public function __construct(
        private readonly GarrysMod $gmod,
        private readonly LuaErrorService $errors,
        private readonly DiskUsageService $disk,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(Server $server): array
    {
        $errors = $this->errorCounts($server);

        // Sizes only exist once someone has run the scan; until then the column is
        // simply empty rather than the page pretending it knows.
        $sizes = $this->disk->cached($server)['addons'] ?? [];

        $addons = [
            ...$this->read($server, 'garrysmod/addons', true, $errors, $sizes),
            ...$this->read($server, 'garrysmod/addons/' . self::DISABLED_DIR, false, $errors, $sizes),
        ];

        // Live problems first, then worst overall, then alphabetical - an addon that
        // threw 200 errors last month matters less than one throwing them now.
        usort($addons, fn (array $a, array $b): int => [$b['recent'], $b['errors'], mb_strtolower($a['name'])]
            <=> [$a['recent'], $a['errors'], mb_strtolower($b['name'])]);

        return $addons;
    }

    /**
     * @param  array<string, array{count: int, recent: int}>  $errors
     * @param  array<string, int>  $sizes
     * @return list<array<string, mixed>>
     */
    private function read(Server $server, string $directory, bool $enabled, array $errors, array $sizes): array
    {
        $addons = [];

        foreach ($this->gmod->directory($server, $directory) ?? [] as $entry) {
            $name = (string) ($entry['name'] ?? '');

            // Only directories are addons; __disabled is the holding pen, not one.
            if ($name === '' || $name === self::DISABLED_DIR || ($entry['directory'] ?? false) !== true) {
                continue;
            }

            $addons[] = [
                // Table records are keyed on this; the folder name is already unique
                // within addons/ and survives a re-render, which is what Livewire needs.
                '__key' => $name,
                'name' => $name,
                'enabled' => $enabled,
                'path' => $directory . '/' . $name,
                'modified' => $this->parseDate($entry['modified'] ?? null),
                'size' => $sizes[$name] ?? null,
                'errors' => $errors[mb_strtolower($name)]['count'] ?? 0,
                // Errors in the tail of the log - an addon with none of these is
                // carrying historical noise, not a live problem.
                'recent' => $errors[mb_strtolower($name)]['recent'] ?? 0,
            ];
        }

        return $addons;
    }

    /**
     * Error counts per addon folder, so the browser can surface which addons are
     * actually misbehaving rather than listing 56 names alphabetically.
     *
     * @return array<string, array{count: int, recent: int}>
     */
    private function errorCounts(Server $server): array
    {
        $counts = [];

        foreach ($this->errors->digest($server) as $group) {
            if ($group['path'] === null || !str_starts_with($group['path'], 'addons/')) {
                continue;
            }

            $folder = mb_strtolower(basename($group['path']));

            $counts[$folder] = [
                'count' => ($counts[$folder]['count'] ?? 0) + $group['count'],
                'recent' => ($counts[$folder]['recent'] ?? 0) + $group['recent'],
            ];
        }

        return $counts;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function toggle(Server $server, string $name, bool $enable): array
    {
        $name = basename(trim($name, '/'));

        if ($name === '' || $name === self::DISABLED_DIR) {
            return ['ok' => false, 'message' => 'Invalid addon name.'];
        }

        $from = $enable ? 'addons/' . self::DISABLED_DIR . '/' . $name : 'addons/' . $name;
        $to = $enable ? 'addons/' . $name : 'addons/' . self::DISABLED_DIR . '/' . $name;

        if (!$enable && !$this->gmod->exists($server, 'garrysmod/addons/' . self::DISABLED_DIR)) {
            $this->gmod->createDirectory($server, self::DISABLED_DIR, 'garrysmod/addons');
        }

        if (!$this->gmod->rename($server, 'garrysmod', $from, $to)) {
            return ['ok' => false, 'message' => "Could not move {$name}."];
        }

        return [
            'ok' => true,
            'message' => $enable
                ? "Enabled {$name}. Restart the server to load it."
                : "Disabled {$name}. Restart the server to unload it.",
        ];
    }

    /**
     * Toggles a set of addons in one go, reporting the tally rather than one
     * notification per addon - selecting forty and getting forty toasts is worse
     * than selecting forty and getting one honest summary.
     *
     * @param  list<string>  $names
     * @return array{ok: bool, message: string}
     */
    public function toggleMany(Server $server, array $names, bool $enable): array
    {
        $done = 0;
        $failed = [];

        foreach ($names as $name) {
            $result = $this->toggle($server, $name, $enable);

            if ($result['ok']) {
                $done++;

                continue;
            }

            $failed[] = basename(trim($name, '/'));
        }

        $verb = $enable ? 'Enabled' : 'Disabled';

        if ($failed === []) {
            return [
                'ok' => true,
                'message' => "{$verb} {$done} addon" . ($done === 1 ? '' : 's') . '. Restart the server to apply.',
            ];
        }

        return [
            'ok' => $done > 0,
            'message' => "{$verb} {$done}, but could not move: " . implode(', ', $failed) . '.',
        ];
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
