<?php

declare(strict_types=1);

namespace Firefly\Callbacks;

use Firefly\Callbacks\Delivery\CallbackDeliverer;
use Firefly\Callbacks\Delivery\CallbackDispatcher;
use Firefly\Callbacks\Delivery\CallbackSender;
use Firefly\Callbacks\Delivery\CallbackSigner;
use Firefly\Callbacks\Delivery\EndpointCircuitBreakers;
use Firefly\Callbacks\Delivery\LaravelHttpCallbackSender;
use Firefly\Callbacks\Execution\DatabaseExecutionLog;
use Firefly\Callbacks\Execution\ExecutionLog;
use Firefly\Callbacks\Execution\InMemoryExecutionLog;
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
use Firefly\Resilience\Store\ResilienceStore;
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

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.callbacks.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(ExecutionLog::class)]
    public function executionLog(CallbacksSettings $settings, Container $container): ExecutionLog
    {
        return $settings->logStore === 'database'
            ? new DatabaseExecutionLog($this->connection($container, $settings->connection))
            : new InMemoryExecutionLog;
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.callbacks.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(CallbackSender::class)]
    public function callbackSender(Container $container): CallbackSender
    {
        return new LaravelHttpCallbackSender($container);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.callbacks.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(CallbackSigner::class)]
    public function callbackSigner(CallbacksSettings $settings): CallbackSigner
    {
        return new CallbackSigner($settings->signatureHeader, $settings->timestampHeader);
    }

    /**
     * The breaker per endpoint rides on the application's ResilienceStore — the same store, with the same
     * lockless refusal, every attribute breaker uses — unless firefly.callbacks.circuit-breaker.enabled is false.
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.callbacks.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(CallbackDeliverer::class)]
    public function callbackDeliverer(CallbacksSettings $settings, CallbackSender $sender, CallbackSigner $signer, ResilienceStore $store): CallbackDeliverer
    {
        $breakers = $settings->breakerEnabled ? new EndpointCircuitBreakers($store, $settings->failureThreshold, $settings->waitDurationInOpen) : null;

        return new CallbackDeliverer($settings, $sender, $signer, $breakers);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.callbacks.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(CallbackDispatcher::class)]
    public function callbackDispatcher(SubscriptionRegistry $subscriptions, ExecutionLog $log, CallbackDeliverer $deliverer): CallbackDispatcher
    {
        return new CallbackDispatcher($subscriptions, $log, $deliverer);
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
