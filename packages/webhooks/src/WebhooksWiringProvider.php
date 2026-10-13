<?php

declare(strict_types=1);

namespace Firefly\Webhooks;

use Firefly\Config\Config;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Webhooks\Console\PruneWebhookReceiptsCommand;
use Firefly\Webhooks\Http\WebhookRouteRegistrar;

/**
 * The boot-pass half of firefly/webhooks: the endpoint registrar, the receipts migration (publishable with
 * --tag=firefly-webhooks-migrations, and loaded automatically only when webhooks are enabled on the database
 * store — an application that never chose it gets no table) and firefly:webhooks:prune.
 */
final class WebhooksWiringProvider extends FireflyServiceProvider
{
    public const string MIGRATION = '2026_10_12_000000_create_firefly_webhook_receipts_table.php';

    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new WebhookRouteRegistrar];
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneWebhookReceiptsCommand::class]);
        }

        $migrations = dirname(__DIR__).'/database/migrations';
        $this->publishes([$migrations.'/'.self::MIGRATION => $this->app->databasePath('migrations/'.self::MIGRATION)], 'firefly-webhooks-migrations');

        $config = new Config($this->app->make('config'));
        if ($config->bool('firefly.webhooks.enabled', false) && $config->string('firefly.webhooks.idempotency.store', 'cache') === 'database') {
            $this->loadMigrationsFrom($migrations);
        }
    }
}
