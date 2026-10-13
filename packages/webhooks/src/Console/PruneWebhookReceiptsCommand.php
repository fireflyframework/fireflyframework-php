<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Console;

use Firefly\Webhooks\Idempotency\DatabaseWebhookIdempotencyStore;
use Firefly\Webhooks\Idempotency\WebhookIdempotencyStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;

/**
 * Deletes expired webhook receipts from firefly_webhook_receipts. Only the database store keeps rows past their TTL
 * (the cache and memory stores expire on their own), so on those the command says so and does nothing. Schedule it
 * daily with Laravel's scheduler.
 */
final class PruneWebhookReceiptsCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:webhooks:prune';

    /** @var string */
    protected $description = 'Delete expired webhook idempotency receipts from the database store.';

    public function handle(Container $container): int
    {
        if (! $container->bound(WebhookIdempotencyStore::class)) {
            $this->error('firefly/webhooks is not enabled (firefly.webhooks.enabled is false).');

            return self::FAILURE;
        }

        $store = $container->make(WebhookIdempotencyStore::class);
        if (! $store instanceof DatabaseWebhookIdempotencyStore) {
            $this->info('The webhook idempotency store is not the database store; its receipts expire on their own.');

            return self::SUCCESS;
        }

        $this->info('Pruned '.$store->prune().' expired webhook receipt(s).');

        return self::SUCCESS;
    }
}
