<?php

declare(strict_types=1);

use Firefly\Eda\Consumer\EventConsumer;
use Firefly\Eda\Rabbitmq\EdaRabbitmqServiceProvider;
use Firefly\Eda\Rabbitmq\RabbitMqEventConsumer;

/**
 * Real-boot-pipeline gate (bootFireflyApp() drives ConditionPassTwoPass over the committed manifests, not a
 * hand-wired container — the RabbitMqHealthIndicatorGatingTest precedent) proving `firefly:eda:consume`
 * (packages/eda/src/Console/ConsumeEventsCommand.php) resolves RabbitMqEventConsumer as the EventConsumer
 * exactly when firefly.eda.provider=rabbitmq, and stays fully inert otherwise.
 */
it('does not register EventConsumer when firefly.eda.provider is not rabbitmq', function () {
    $context = bootFireflyApp(['firefly' => ['eda' => ['provider' => 'memory']]], [EdaRabbitmqServiceProvider::class]);

    expect($context->has(EventConsumer::class))->toBeFalse();
});

it('does not register EventConsumer when firefly.eda.provider is absent', function () {
    $context = bootFireflyApp(['firefly' => ['eda' => []]], [EdaRabbitmqServiceProvider::class]);

    expect($context->has(EventConsumer::class))->toBeFalse();
});

it('binds RabbitMqEventConsumer as the EventConsumer when firefly.eda.provider=rabbitmq', function () {
    $context = bootFireflyApp(['firefly' => ['eda' => ['provider' => 'rabbitmq']]], [EdaRabbitmqServiceProvider::class]);

    expect($context->has(EventConsumer::class))->toBeTrue()
        ->and($context->get(EventConsumer::class))->toBeInstanceOf(RabbitMqEventConsumer::class);
});
