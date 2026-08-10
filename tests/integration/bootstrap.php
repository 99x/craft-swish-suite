<?php

declare(strict_types=1);

/**
 * Bootstraps a real Craft + Commerce application (against a throwaway database)
 * for tests that need genuine Craft::$app behavior (URL generation, sites,
 * i18n, ActiveRecord persistence) rather than hand-mocked stand-ins.
 *
 * Env vars (CRAFT_DB_*) default to the local ddev MySQL instance so this can
 * run unchanged in CI, as long as the workflow sets the same variables.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

define('CRAFT_BASE_PATH', __DIR__ . '/../craft');
define('CRAFT_VENDOR_PATH', __DIR__ . '/../../vendor');

$defaults = [
    'CRAFT_DB_DRIVER' => 'mysql',
    'CRAFT_DB_SERVER' => '127.0.0.1',
    'CRAFT_DB_PORT' => '3306',
    'CRAFT_DB_DATABASE' => 'swish_suite_test',
    'CRAFT_DB_USER' => 'root',
    'CRAFT_DB_PASSWORD' => '',
    'CRAFT_ENVIRONMENT' => 'test',
    'CRAFT_SECURITY_KEY' => 'test-only-security-key-not-for-production-use',
    'CRAFT_APP_ID' => 'CraftCMS--swish-suite-tests',
];

foreach ($defaults as $name => $value) {
    if (getenv($name) === false) {
        putenv("$name=$value");
    }
}

/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

// Required directly by path rather than relying on the autoloader: the
// namespace segment `Integration` doesn't match this directory's actual case
// (`tests/integration`), same pre-existing quirk as `Tests\Unit` vs `tests/unit`.
require_once __DIR__ . '/TestBootstrap.php';

NinetyNineX\SwishSuite\Tests\Integration\TestBootstrap::ensureInstalled();
