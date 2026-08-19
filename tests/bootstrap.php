<?php

declare(strict_types=1);

/**
 * These plugins ship no composer.json - Pelican loads them through its own plugin
 * autoloader, and adding a vendor directory purely for tests would put one inside
 * every distributed zip. So the handful of classes worth testing are required here
 * by hand.
 *
 * Only classes with no panel dependencies belong in this list. Anything that touches
 * the daemon, Filament or Eloquent cannot be tested without the panel itself, and is
 * checked by hand in a browser instead - see the AI disclosure in the README.
 */

$root = dirname(__DIR__);

foreach ([
    'gmod-toolkit/src/Support/LuaErrorParser.php',
    'gmod-toolkit/src/Support/Bytes.php',
    'app-toolkit/src/Support/DotEnvFile.php',
    'minecraft-toolkit/src/Support/PropertiesFile.php',
] as $file) {
    require_once $root . '/' . $file;
}
