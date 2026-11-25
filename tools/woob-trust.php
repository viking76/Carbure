<?php

/**
 * woob-trust.php
 *
 * Trust the keyring of the official woob repository when it is exactly the one pinned
 * by Carbure (WoobRepository::OFFICIAL_KEYRINGS), then update the bank modules, so that
 * the banks can be connected without an administrator confirming the fingerprint (#471).
 * Run at each start of the Docker container; does nothing once a keyring is trusted or
 * for another repository (woob_repository).
 *
 * Works before the installation (no data/conf/<env>.ini yet): woob is then the one the
 * installation would configure (CARBURE_WOOB_PATH, else data/woob).
 *
 *   php tools/woob-trust.php
 *
 * Exit code: 0 trusted (or nothing to do), 1 not trusted (an administrator must confirm
 * the fingerprint from the Accounts page), 2 failure. The lines of woob go to stderr.
 */

use Carbure\Lib\ClassLoader;
use Carbure\Lib\Config;
use Carbure\Lib\Setup;
use Carbure\Lib\WoobRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/src/lib/ClassLoader.php';
ClassLoader::register();

try {
    Config::load(getenv('APP_ENV') ?: 'prod');
} catch (Exception $e) {
    // Not installed yet: the settings the installation would write
    Config::set('data_dir', $root . '/data');
    Config::set('log_level', 'INFO');
    Config::set('woob_path', Setup::defaultWoobPath($root));
}

try {
    $result = WoobRepository::trustOfficial(fn(string $line) => fwrite(STDERR, "$line\n"));
} catch (Throwable $e) {
    fwrite(STDERR, "Carbure: woob repository not trusted: " . $e->getMessage() . "\n");
    exit(2);
}

if ($result === null) {
    exit(0);
}
if ($result['updated']) {
    fwrite(STDERR, "Carbure: official keyring of the woob repository trusted, bank modules updated\n");
    exit(0);
}
fwrite(STDERR, "Carbure: woob repository not trusted (" . $result['message'] . "): an administrator must "
    . "confirm its fingerprint from the Accounts page of the portal (update the bank modules)\n");
exit($result['refused'] ? 1 : 2);
