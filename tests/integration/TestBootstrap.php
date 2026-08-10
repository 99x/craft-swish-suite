<?php

declare(strict_types=1);

namespace NinetyNineX\SwishSuite\Tests\Integration;

use Craft;
use craft\migrations\Install as CraftInstall;
use craft\models\Site;
use NinetyNineX\SwishSuite\migrations\Install as SwishInstall;

/**
 * Installs Craft's core schema and the plugin's own schema into the throwaway
 * integration-test database, once per test run. Mirrors what `craft install`
 * does interactively, non-interactively, with a fixed admin/site.
 */
class TestBootstrap
{
    public static function ensureInstalled(): void
    {
        if (!Craft::$app->getIsInstalled(true)) {
            $site = new Site([
                'handle' => 'default',
                'name' => 'Swish Suite Tests',
                'baseUrl' => 'https://swish-suite-tests.test',
                'language' => 'en-US',
                'hasUrls' => true,
            ]);

            $migration = new CraftInstall([
                'username' => 'admin',
                'password' => 'Test-Password-12345!',
                'email' => 'admin@swish-suite-tests.test',
                'site' => $site,
                'applyProjectConfigYaml' => false,
            ]);

            $migrator = Craft::$app->getMigrator();
            $migrator->migrateUp($migration);

            foreach ($migrator->getNewMigrations() as $name) {
                $migrator->addMigrationHistory($name);
            }
        }

        // Order element construction reaches into Commerce::getInstance()->getStores(),
        // so Commerce itself needs to be a genuinely installed & enabled plugin here —
        // unlike swish-suite (the root package under test), Commerce is a normal
        // composer dependency Craft can discover and install through the real service.
        //
        // This bare bootstrap script never goes through a real request lifecycle, so
        // nothing ever flushes the "commerce is enabled" fact to persistent project
        // config storage — without an explicit save, it only lives in this process's
        // memory and Commerce silently isn't loaded again on the next test run.
        $plugins = Craft::$app->getPlugins();
        if (!$plugins->isPluginInstalled('commerce')) {
            $plugins->installPlugin('commerce');
        } elseif (!$plugins->isPluginEnabled('commerce')) {
            $plugins->enablePlugin('commerce');
        }
        Craft::$app->getProjectConfig()->saveModifiedConfigData();

        (new SwishInstall())->safeUp();
    }
}
