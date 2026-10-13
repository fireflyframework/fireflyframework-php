<?php

declare(strict_types=1);

namespace Firefly\Callbacks;

use Firefly\Config\Config;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;

/**
 * The boot-pass half of firefly/callbacks: the event subscription, the two-table migration (publishable with
 * --tag=firefly-callbacks-migrations, loaded automatically only when callbacks are enabled with a database
 * registry or log) and the list/replay commands.
 */
final class CallbacksWiringProvider extends FireflyServiceProvider
{
    public const string MIGRATION = '2026_10_12_000001_create_firefly_callbacks_tables.php';

    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [];
    }

    public function boot(): void
    {
        $migrations = dirname(__DIR__).'/database/migrations';
        $this->publishes([$migrations.'/'.self::MIGRATION => $this->app->databasePath('migrations/'.self::MIGRATION)], 'firefly-callbacks-migrations');

        $config = new Config($this->app->make('config'));
        $database = $config->string('firefly.callbacks.registry.store', 'memory') === 'database'
            || $config->string('firefly.callbacks.log.store', 'memory') === 'database';
        if ($config->bool('firefly.callbacks.enabled', false) && $database) {
            $this->loadMigrationsFrom($migrations);
        }
    }
}
