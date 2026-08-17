<?php

namespace Catualus\AppToolkit\Services;

use App\Models\Server;
use Catualus\AppToolkit\Support\AppServer;

/**
 * Read-only view of what the app pins, so you can see the dependency set without
 * opening the editor.
 */
class DependencyService
{
    public function __construct(private readonly AppServer $app) {}

    /**
     * @return array{file: ?string, groups: list<array{label: string, packages: list<array{name: string, version: string}>}>}
     */
    public function read(Server $server): array
    {
        if ($this->app->exists($server, 'package.json')) {
            return $this->fromPackageJson($server);
        }

        foreach ((array) config('app-toolkit.requirements_files', ['requirements.txt']) as $file) {
            if ($this->app->exists($server, $file)) {
                return $this->fromRequirements($server, (string) $file);
            }
        }

        return ['file' => null, 'groups' => []];
    }

    /**
     * @return array{file: ?string, groups: list<array<string, mixed>>}
     */
    private function fromPackageJson(Server $server): array
    {
        $raw = $this->app->contents($server, 'package.json');
        $decoded = $raw === null ? null : json_decode($raw, true);

        if (!is_array($decoded)) {
            return ['file' => 'package.json', 'groups' => []];
        }

        $groups = [];

        foreach (['dependencies' => 'Dependencies', 'devDependencies' => 'Dev dependencies', 'peerDependencies' => 'Peer dependencies'] as $key => $label) {
            $packages = [];

            foreach ((array) ($decoded[$key] ?? []) as $name => $version) {
                $packages[] = ['name' => (string) $name, 'version' => (string) $version];
            }

            if ($packages !== []) {
                usort($packages, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
                $groups[] = ['label' => $label, 'packages' => $packages];
            }
        }

        return ['file' => 'package.json', 'groups' => $groups];
    }

    /**
     * @return array{file: ?string, groups: list<array<string, mixed>>}
     */
    private function fromRequirements(Server $server, string $file): array
    {
        $raw = $this->app->contents($server, $file);

        if ($raw === null) {
            return ['file' => $file, 'groups' => []];
        }

        $packages = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);

            // Comments, blanks, and pip flags like -r other.txt are not packages.
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '-')) {
                continue;
            }

            // Strip inline comments and environment markers before splitting.
            $line = trim(preg_split('/\s+#/', $line)[0] ?? $line);
            $line = trim(explode(';', $line)[0]);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^(?<name>[A-Za-z0-9._\-\[\]]+)\s*(?<spec>[<>=!~].*)?$/', $line, $m)) {
                $packages[] = [
                    'name' => $m['name'],
                    'version' => trim($m['spec'] ?? '') ?: 'any',
                ];
            }
        }

        usort($packages, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return [
            'file' => $file,
            'groups' => $packages === [] ? [] : [['label' => 'Requirements', 'packages' => $packages]],
        ];
    }
}
