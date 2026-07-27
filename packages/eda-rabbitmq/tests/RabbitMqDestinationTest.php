<?php

declare(strict_types=1);

use Firefly\Eda\Rabbitmq\RabbitMqEventPublisher;

it('parses "exchange/routingKey" destinations', function () {
    expect(RabbitMqEventPublisher::parseDestination('firefly.events/user.created', 'default.ex'))
        ->toBe(['firefly.events', 'user.created']);
});

it('falls back to the configured exchange when no slash is present', function () {
    expect(RabbitMqEventPublisher::parseDestination('user.created', 'firefly.events'))
        ->toBe(['firefly.events', 'user.created']);
});
