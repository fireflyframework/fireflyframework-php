<?php

declare(strict_types=1);

namespace Firefly\Webhooks;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Eda\EventPublisher;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Webhooks\Idempotency\CacheWebhookIdempotencyStore;
use Firefly\Webhooks\Idempotency\DatabaseWebhookIdempotencyStore;
use Firefly\Webhooks\Idempotency\InMemoryWebhookIdempotencyStore;
use Firefly\Webhooks\Idempotency\WebhookIdempotencyStore;
use Firefly\Webhooks\Signature\SignatureValidatorFactory;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Psr\Log\LoggerInterface;

/**
 * The webhooks beans, every one behind firefly.webhooks.enabled (default false): installing the package is inert
 * until an application opts in. Beans are eager singletons, so a misconfigured provider refuses the boot here.
 * Each backs off #[ConditionalOnMissingBean], so an application can bring its own store, validators or processor.
 */
#[Configuration]
#[Order(1000)]
final class WebhooksAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.webhooks.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(WebhooksSettings::class)]
    public function webhooksSettings(Config $config): WebhooksSettings
    {
        return WebhooksSettings::fromConfig($config);
    }

    /**
     * firefly.webhooks.idempotency.store: `cache` (the default, on firefly.webhooks.idempotency.cache-store or the
     * default store), `database` (firefly_webhook_receipts on firefly.webhooks.idempotency.connection or the
     * default connection), or `memory` (one process only).
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.webhooks.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(WebhookIdempotencyStore::class)]
    public function webhookIdempotencyStore(WebhooksSettings $settings, Container $container): WebhookIdempotencyStore
    {
        if ($settings->idempotencyStore === 'memory') {
            return new InMemoryWebhookIdempotencyStore;
        }

        if ($settings->idempotencyStore === 'database') {
            $databases = $container->bound('db') ? $container->make('db') : null;
            if (! $databases instanceof ConnectionResolverInterface) {
                throw new ConfigurationException('firefly.webhooks.idempotency.store is database but no database connection resolver (db) is bound.');
            }

            return new DatabaseWebhookIdempotencyStore($databases->connection($settings->idempotencyConnection));
        }

        /** @var CacheFactory $caches */
        $caches = $container->make('cache');

        return new CacheWebhookIdempotencyStore($caches->store($settings->idempotencyCacheStore === '' ? null : $settings->idempotencyCacheStore));
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.webhooks.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(SignatureValidatorFactory::class)]
    public function signatureValidatorFactory(): SignatureValidatorFactory
    {
        return new SignatureValidatorFactory(static fn (): int => time());
    }

    /**
     * The processor every endpoint delegates to: it publishes to whichever EventPublisher firefly/eda bound
     * (firefly.eda.provider), and logs ignored duplicates on the application logger when one is bound.
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.webhooks.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(WebhookProcessor::class)]
    public function webhookProcessor(WebhooksSettings $settings, SignatureValidatorFactory $validators, WebhookIdempotencyStore $idempotency, EventPublisher $publisher, Container $container): WebhookProcessor
    {
        $logger = $container->bound(LoggerInterface::class) ? $container->make(LoggerInterface::class) : null;

        return new WebhookProcessor($settings, $validators, $idempotency, $publisher, $logger instanceof LoggerInterface ? $logger : null);
    }
}
