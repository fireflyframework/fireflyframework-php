<?php

declare(strict_types=1);

namespace Firefly\Callbacks;

use Firefly\Callbacks\Subscription\DatabaseSubscriptionRegistry;
use Firefly\Callbacks\Subscription\InMemorySubscriptionRegistry;
use Firefly\Callbacks\Subscription\SubscriptionRegistry;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * The callbacks beans, every one behind firefly.callbacks.enabled (default false). Each backs off
 * #[ConditionalOnMissingBean], so an application can bring its own registry, log or sender.
 */
#[Configuration]
#[Order(1000)]
final class CallbacksAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.callbacks.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(CallbacksSettings::class)]
    public function callbacksSettings(Config $config): CallbacksSettings
    {
        return CallbacksSettings::fromConfig($config);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.callbacks.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(SubscriptionRegistry::class)]
    public function subscriptionRegistry(CallbacksSettings $settings, Container $container): SubscriptionRegistry
    {
        return $settings->registryStore === 'database'
            ? new DatabaseSubscriptionRegistry($this->connection($container, $settings->connection))
            : new InMemorySubscriptionRegistry($settings->subscriptions);
    }

    private function connection(Container $container, ?string $name): ConnectionInterface
    {
        $databases = $container->bound('db') ? $container->make('db') : null;
        if (! $databases instanceof ConnectionResolverInterface) {
            throw new ConfigurationException('firefly.callbacks uses a database store but no database connection resolver (db) is bound.');
        }

        return $databases->connection($name);
    }
}
