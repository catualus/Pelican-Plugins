<?php

namespace Catualus\MinecraftToolkit\Services;

use App\Models\Server;
use Catualus\MinecraftToolkit\Support\Minecraft;
use Illuminate\Support\Carbon;

/**
 * Lists and toggles mods or plugins.
 *
 * Disabling renames foo.jar to foo.jar.disabled - both Forge/NeoForge/Fabric and
 * Bukkit-family servers only load files ending in .jar, so the rename is what
 * actually stops it loading. This deliberately covers CurseForge-installed mods,
 * which the minecraft-modrinth plugin cannot manage.
 */
class ModService
{
    public const DISABLED_SUFFIX = '.disabled';

    public function __construct(private readonly Minecraft $minecraft) {}

    /**
     * @return list<array{name: string, file: string, enabled: bool, path: string, size: int, modified: ?Carbon}>
     */
    public function all(Server $server): array
    {
        $directory = $this->minecraft->flavour($server);

        if ($directory === null) {
            return [];
        }

        $items = [];

        foreach ($this->minecraft->directory($server, $directory) ?? [] as $entry) {
            $file = (string) ($entry['name'] ?? '');

            if (($entry['directory'] ?? false) === true || !$this->isJar($file)) {
                continue;
            }

            $enabled = str_ends_with($file, '.jar');

            $items[] = [
                'name' => $this->displayName($file),
                'file' => $file,
                'enabled' => $enabled,
                'path' => $directory . '/' . $file,
                'size' => (int) ($entry['size'] ?? 0),
                'modified' => $this->parseDate($entry['modified'] ?? null),
            ];
        }

        usort($items, fn (array $a, array $b): int => [$a['enabled'] ? 1 : 0, mb_strtolower($b['name'])]
            <=> [$b['enabled'] ? 1 : 0, mb_strtolower($a['name'])]);

        return $items;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function toggle(Server $server, string $file, bool $enable): array
    {
        $file = basename(trim($file, '/'));
        $directory = $this->minecraft->flavour($server);

        if ($file === '' || $directory === null || !$this->isJar($file)) {
            return ['ok' => false, 'message' => 'That is not a mod file.'];
        }

        $target = $enable
            ? preg_replace('/' . preg_quote(self::DISABLED_SUFFIX, '/') . '$/', '', $file)
            : $file . self::DISABLED_SUFFIX;

        if ($target === $file || $target === null || $target === '') {
            return ['ok' => false, 'message' => 'Nothing to change.'];
        }

        if (!$this->minecraft->rename($server, $directory, $file, $target)) {
            return ['ok' => false, 'message' => "Could not rename {$file}."];
        }

        $label = $this->displayName($file);

        return [
            'ok' => true,
            'message' => $enable
                ? "Enabled {$label}. Restart the server to load it."
                : "Disabled {$label}. Restart the server to unload it.",
        ];
    }

    private function isJar(string $file): bool
    {
        return str_ends_with($file, '.jar') || str_ends_with($file, '.jar' . self::DISABLED_SUFFIX);
    }

    /**
     * Strips the .jar/.jar.disabled tail and the trailing version so a 400-mod list
     * stays scannable. The full filename is still shown alongside.
     */
    private function displayName(string $file): string
    {
        $name = preg_replace('/\.jar(' . preg_quote(self::DISABLED_SUFFIX, '/') . ')?$/', '', $file) ?? $file;

        return str_replace(['_', '+'], ' ', $name);
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
