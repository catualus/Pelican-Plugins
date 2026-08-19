<?php

namespace Catualus\MinecraftToolkit\Services;

use App\Models\Server;
use Catualus\MinecraftToolkit\Support\Minecraft;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Recent crash reports, with the part of each one that actually says what happened.
 *
 * A list of filenames is only marginally more useful than the folder itself. What a
 * server owner wants from a crash report is the two lines near the top - the
 * description and the exception - because those usually name the mod, and naming the
 * mod is the whole task.
 *
 * Reports are immutable once written, so a parsed head is cached against the
 * filename and never re-read.
 */
class CrashReportService
{
    public const DIRECTORY = 'crash-reports';

    public function __construct(private readonly Minecraft $minecraft) {}

    /**
     * @return list<array{name: string, path: string, modified: ?Carbon, description: ?string, exception: ?string, culprit: ?string}>
     */
    public function recent(Server $server): array
    {
        $entries = $this->minecraft->directory($server, self::DIRECTORY);

        if ($entries === null) {
            return [];
        }

        $reports = [];

        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');

            if ($name === '' || ($entry['directory'] ?? false) === true) {
                continue;
            }

            $reports[] = [
                'name' => $name,
                'path' => self::DIRECTORY . '/' . $name,
                'modified' => $this->parseDate($entry['modified'] ?? null),
            ];
        }

        // Crash report filenames are timestamped, so name order is time order.
        usort($reports, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        $limit = (int) config('minecraft-toolkit.crash_report_limit', 5);

        return array_map(
            fn (array $report): array => [...$report, ...$this->summarise($server, $report['path'])],
            array_slice($reports, 0, $limit),
        );
    }

    /**
     * @return array{description: ?string, exception: ?string, culprit: ?string}
     */
    private function summarise(Server $server, string $path): array
    {
        $key = 'minecraft-toolkit:crash:' . $server->uuid . ':' . md5($path);

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $contents = $this->minecraft->contents($server, $path);

        $summary = $contents === null
            ? ['description' => null, 'exception' => null, 'culprit' => null]
            : $this->parse($contents);

        // A crash report never changes after it is written, so this can be kept for
        // as long as the cache will hold it.
        Cache::put($key, $summary, 604800);

        return $summary;
    }

    /**
     * Minecraft's crash reports open with a joke, a timestamp, then:
     *
     *   Description: Exception in server tick loop
     *   java.lang.NullPointerException: Cannot invoke "..." because ...
     *       at somemod.Whatever.tick(Whatever.java:42)
     *
     * The description says which subsystem died, the next non-blank line is the
     * exception, and the first stack frame that is not Minecraft's own code is the
     * best guess at whose fault it is.
     *
     * @return array{description: ?string, exception: ?string, culprit: ?string}
     */
    private function parse(string $contents): array
    {
        $lines = preg_split('/\R/', $contents) ?: [];

        $description = null;
        $exception = null;
        $culprit = null;

        foreach (array_slice($lines, 0, 200) as $line) {
            $trimmed = trim($line);

            if ($description === null && str_starts_with($trimmed, 'Description:')) {
                $description = trim(substr($trimmed, 12));

                continue;
            }

            // The exception is the first non-blank line after the description.
            if ($description !== null && $exception === null && $trimmed !== '' && !str_starts_with($trimmed, 'at ')) {
                $exception = $trimmed;

                continue;
            }

            if ($exception !== null && $culprit === null && str_starts_with($trimmed, 'at ')) {
                $frame = trim(substr($trimmed, 3));

                if (!$this->isVanilla($frame)) {
                    $culprit = $frame;
                }
            }
        }

        return [
            'description' => $description,
            'exception' => $exception === null ? null : mb_substr($exception, 0, 300),
            'culprit' => $culprit === null ? null : mb_substr($culprit, 0, 200),
        ];
    }

    /**
     * Frames inside Minecraft, Java or the mod loader itself are where the crash
     * surfaced, not where it came from - the interesting frame is the first one that
     * belongs to somebody's mod.
     */
    private function isVanilla(string $frame): bool
    {
        foreach (['java.', 'javax.', 'jdk.', 'sun.', 'net.minecraft.', 'com.mojang.', 'net.minecraftforge.', 'net.neoforged.', 'net.fabricmc.', 'cpw.mods.', 'org.spongepowered.'] as $prefix) {
            if (str_starts_with($frame, $prefix)) {
                return true;
            }
        }

        return false;
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
