<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags;

use Firefly\Config\Config;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\FeatureFlags\Server\FlagdSyncRouteRegistrar;

/**
 * The boot-pass half of firefly/feature-flags: route gating, the sync route, migrations, Blade directives and
 * the console command are added here by the lanes that own them. Nothing is bound here — every collaborator
 * is a #[Bean] or a #[Component] described by the package manifests.
 */
final class FeatureFlagsWiringProvider extends FireflyServiceProvider
{
    public const string MIGRATION = '2026_10_01_000000_create_firefly_feature_flags_tables.php';

    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new FlagdSyncRouteRegistrar];
    }

    public function boot(): void
    {
        $migrations = dirname(__DIR__).'/database/migrations';
        $this->publishes([$migrations.'/'.self::MIGRATION => $this->app->databasePath('migrations/'.self::MIGRATION)], 'firefly-feature-flags-migrations');

        $config = new Config($this->app->make('config'));
        if ($config->bool('firefly.feature-flags.enabled', false)
            && $config->bool('firefly.feature-flags.sources.store.enabled', false)
            && $config->string('firefly.feature-flags.sources.store.driver', 'database') === 'database') {
            $this->loadMigrationsFrom($migrations);
        }
    }
}
