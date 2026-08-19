<?php

declare(strict_types=1);

/**
 * Structural checks that need no panel and no autoloader.
 *
 * These catch the class of mistake that produces no syntax error and no test
 * failure, and instead shows up in the panel as a page that has silently vanished:
 *
 *   - a namespace that does not match its directory, so Pelican's autoloader never
 *     finds the class
 *   - an import of a class in the same plugin that does not exist, usually a file
 *     renamed without its references
 *   - an import nothing uses, which is harmless but is nearly always the leftover
 *     of a half-finished change
 *
 *   php tools/check-structure.php
 */

$root = realpath($argv[1] ?? dirname(__DIR__));

if ($root === false) {
    fwrite(STDERR, "No such directory.\n");
    exit(2);
}

// realpath hands back backslashes on Windows; every path below is compared as a
// forward-slash string, so normalise once here rather than at each comparison.
$root = rtrim(str_replace('\\', '/', $root), '/');

/** @var array<string, string> plugin namespace => absolute src directory */
$plugins = [];

foreach (glob($root . '/*/plugin.json') ?: [] as $manifest) {
    $json = json_decode((string) file_get_contents($manifest), true);

    if (is_array($json) && isset($json['namespace'])) {
        $plugins[$json['namespace']] = str_replace('\\', '/', dirname($manifest)) . '/src';
    }
}

if ($plugins === []) {
    fwrite(STDERR, "No plugin manifests found. Run this from the repository root.\n");
    exit(2);
}

$problems = 0;

$report = static function (string $type, string $file, string $detail) use (&$problems, $root): void {
    $problems++;
    $relative = ltrim(str_replace($root, '', $file), '/');

    // GitHub picks these up and annotates the file in the diff.
    echo "::error file={$relative}::{$type}: {$detail}\n";
    echo "{$type}  {$relative}\n  {$detail}\n";
};

/**
 * Blade compiles {{ }} echoes inside a component's attributes, but NOT @directives.
 *
 * So `<x-filament::button wire:click="go(@js($id))">` ships the literal text
 * "@js($id)" to the browser. Livewire cannot parse the call and Alpine throws a
 * SyntaxError that aborts initialisation for the rest of that subtree - which is
 * how one bad attribute silently kills every control on a page.
 *
 * It produces no PHP error, no Blade error and no test failure, and it is nearly
 * invisible in review, so it is checked for here. Use {{ \Illuminate\Support\Js::from($x) }}
 * instead, which compiles to exactly what @js() would have.
 */
$checkBladeDirectives = static function (string $path, string $source) use ($report): void {
    // Each opening component tag, including a multi-line one.
    if (!preg_match_all('/<x-[a-z0-9:._-]+((?:[^>"\']|"[^"]*"|\'[^\']*\')*?)\/?>/is', $source, $tags, PREG_SET_ORDER)) {
        return;
    }

    foreach ($tags as $tag) {
        if (preg_match_all('/@(js|json|class|style|checked|selected|disabled|readonly|required)\b/', $tag[1], $directives)) {
            foreach (array_unique($directives[0]) as $directive) {
                $report(
                    'BLADE',
                    $path,
                    "{$directive}() inside a component attribute is not compiled and reaches the browser as text"
                        . ($directive === '@js' ? ' - use {{ \\Illuminate\\Support\\Js::from(...) }}' : ''),
                );
            }
        }
    }
};

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

foreach ($files as $file) {
    $path = str_replace('\\', '/', $file->getPathname());

    if ($file->isDir()
        || !str_ends_with($path, '.php')
        || str_contains($path, '/.git/')
        || str_contains($path, '/vendor/')
        || str_contains($path, '/dist/')
    ) {
        continue;
    }

    if (str_ends_with($path, '.blade.php')) {
        $checkBladeDirectives($path, (string) file_get_contents($path));

        continue;
    }

    $source = (string) file_get_contents($path);

    if (!preg_match('/^namespace\s+([^;]+);/m', $source, $match)) {
        continue;
    }

    $namespace = trim($match[1]);

    foreach ($plugins as $base => $srcDir) {
        if (!str_starts_with($path, $srcDir . '/')) {
            continue;
        }

        $directory = dirname(substr($path, strlen($srcDir) + 1));
        $expected = $directory === '.' ? $base : $base . '\\' . str_replace('/', '\\', $directory);

        if ($namespace !== $expected) {
            $report('NAMESPACE', $path, "declared '{$namespace}' but its directory means '{$expected}'");
        }
    }

    // Strip the use block before looking for usages, or every import would look used
    // by its own import line. Anchored per line so it cannot run past one statement.
    $body = (string) preg_replace('/^use\s+[^;\r\n]+;\r?$/m', '', $source);

    preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?;/m', $source, $imports, PREG_SET_ORDER);

    foreach ($imports as $import) {
        $fqcn = $import[1];

        // strrpos returns false for a root-namespace import such as `use Throwable;`,
        // and false + 1 would quietly chop the first character off the name.
        $separator = strrpos($fqcn, '\\');
        $alias = $import[2] ?? ($separator === false ? $fqcn : substr($fqcn, $separator + 1));

        if (!preg_match('/\b' . preg_quote($alias, '/') . '\b/', $body)) {
            $report('UNUSED', $path, "imports {$fqcn} but never uses it");
        }

        foreach ($plugins as $base => $srcDir) {
            if (!str_starts_with($fqcn, $base . '\\')) {
                continue;
            }

            $target = $srcDir . '/' . str_replace('\\', '/', substr($fqcn, strlen($base) + 1)) . '.php';

            if (!file_exists($target)) {
                $report('MISSING', $path, "imports {$fqcn}, but {$target} does not exist");
            }
        }
    }
}

echo $problems === 0
    ? "Structure is consistent.\n"
    : "{$problems} problem(s) found.\n";

exit($problems === 0 ? 0 : 1);
