<?php

namespace Catualus\MinecraftToolkit\Services;

use App\Models\Server;
use Catualus\MinecraftToolkit\Support\Minecraft;
use Catualus\MinecraftToolkit\Support\PropertiesFile;

/**
 * server.properties as a typed form.
 *
 * Field types come from an explicit list for enums and secrets, and are inferred
 * otherwise - so keys added by Paper, mods or a future Minecraft version still get
 * a sensible control instead of being dropped.
 */
class PropertiesService
{
    public const FILE = 'server.properties';

    public function __construct(private readonly Minecraft $minecraft) {}

    /**
     * @return list<array{key: string, value: string, type: string, options: list<string>, help: ?string}>
     */
    public function fields(Server $server): array
    {
        $raw = $this->minecraft->contents($server, self::FILE);

        if ($raw === null) {
            return [];
        }

        $enums = (array) config('minecraft-toolkit.property_enums', []);
        $secrets = (array) config('minecraft-toolkit.property_secrets', []);
        $help = (array) config('minecraft-toolkit.property_help', []);

        $groups = $this->groupIndex();

        $fields = [];

        foreach (PropertiesFile::parse($raw)->all() as $key => $value) {
            $options = $enums[$key] ?? [];

            $fields[] = [
                'key' => $key,
                'value' => $value,
                'type' => match (true) {
                    in_array($key, $secrets, true) => 'secret',
                    $options !== [] => 'enum',
                    $value === 'true' || $value === 'false' => 'boolean',
                    $value !== '' && ctype_digit(ltrim($value, '-')) => 'integer',
                    default => 'text',
                },
                // An enum whose current value is not in the known set still needs to
                // be selectable, or saving would silently change it.
                'options' => $options !== [] && !in_array($value, $options, true) ? [...$options, $value] : $options,
                'help' => $help[$key] ?? null,
                // Anything the config does not place lands in Other rather than
                // disappearing - mods and new Minecraft versions add keys.
                'group' => $groups[$key] ?? 'Other',
            ];
        }

        return $fields;
    }

    /**
     * The configured groups in order, plus Other, but only those that actually have
     * a field in this file - an empty RCON tab on a server with no RCON keys is a
     * tab that wastes a click.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<string>
     */
    public function groups(array $fields): array
    {
        $present = array_unique(array_column($fields, 'group'));
        $ordered = [...array_keys((array) config('minecraft-toolkit.property_groups', [])), 'Other'];

        return array_values(array_filter($ordered, static fn (string $group): bool => in_array($group, $present, true)));
    }

    /**
     * @return array<string, string>  property key => group label
     */
    private function groupIndex(): array
    {
        $index = [];

        foreach ((array) config('minecraft-toolkit.property_groups', []) as $group => $keys) {
            foreach ((array) $keys as $key) {
                $index[$key] = $group;
            }
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{ok: bool, message: string}
     */
    public function save(Server $server, array $values): array
    {
        $raw = $this->minecraft->contents($server, self::FILE);

        if ($raw === null) {
            return ['ok' => false, 'message' => 'Could not read server.properties.'];
        }

        $normalised = [];

        foreach ($values as $key => $value) {
            $normalised[$key] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => '',
                default => (string) $value,
            };
        }

        $updated = PropertiesFile::parse($raw)->merge($normalised)->render();

        if ($updated === $raw) {
            return ['ok' => true, 'message' => 'No changes to save.'];
        }

        if (!$this->minecraft->put($server, self::FILE, $updated)) {
            return ['ok' => false, 'message' => 'Could not write server.properties.'];
        }

        return ['ok' => true, 'message' => 'Saved. Restart the server for the changes to take effect.'];
    }
}
