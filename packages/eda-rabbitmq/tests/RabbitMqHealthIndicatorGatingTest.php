<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Eda\Rabbitmq\EdaRabbitmqServiceProvider;
use Firefly\Eda\Rabbitmq\OpensConnection;
use Firefly\Eda\Rabbitmq\RabbitMqHealthIndicator;

/**
 * Regression gate for the "surprise /actuator/health 503" bug: installing firefly/eda-rabbitmq must stay fully
 * INERT — RabbitMqHealthIndicator AND the connection-factory beans it depends on must NOT be registered — unless
 * firefly.eda.provider=rabbitmq is the active provider. Without this, an app that installs the package to support
 * RabbitMQ in prod but runs a different provider (postgres/memory/queue) in an environment with no reachable
 * broker gets every /actuator/health call blocked ~3s (php-amqplib's AMQPStreamConnection default
 * connection_timeout) then aggregated to DOWN -> HTTP 503, for a component the app isn't using.
 *
 * Mirrors the DbHealthIndicator opt-in precedent (packages/actuator/src/Health/DbHealthIndicator.php) and
 * scheduling-postgres's LockProviderCoexistenceBootTest style: drives the REAL boot pipeline
 * (ConditionPassTwoPass over the committed manifests), not a hand-wired container — so this guards the actual
 * container-registration outcome, not just the #[ConditionalOnProperty] attribute's presence in source.
 */
function bootWithEdaProvider(?string $provider): ApplicationContext
{
    $eda = $provider === null ? [] : ['provider' => $provider];

    return bootFireflyApp(['firefly' => ['eda' => $eda]], [EdaRabbitmqServiceProvider::class]);
}

it('does not register RabbitMqHealthIndicator or its connection beans when provider = memory', function () {
    $context = bootWithEdaProvider('memory');

    expect($context->has(RabbitMqHealthIndicator::class))->toBeFalse()
        ->and($context->has(OpensConnection::class))->toBeFalse();
});

it('does not register RabbitMqHealthIndicator or its connection beans when firefly.eda.provider is absent', function () {
    $context = bootWithEdaProvider(null);

    expect($context->has(RabbitMqHealthIndicator::class))->toBeFalse()
        ->and($context->has(OpensConnection::class))->toBeFalse();
});

it('registers RabbitMqHealthIndicator and its connection beans when provider = rabbitmq', function () {
    $context = bootWithEdaProvider('rabbitmq');

    expect($context->has(RabbitMqHealthIndicator::class))->toBeTrue()
        ->and($context->get(RabbitMqHealthIndicator::class))->toBeInstanceOf(RabbitMqHealthIndicator::class)
        ->and($context->has(OpensConnection::class))->toBeTrue();
});
