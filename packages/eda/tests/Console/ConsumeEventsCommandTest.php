<?php

declare(strict_types=1);

use Firefly\Eda\Consumer\EventConsumer;
use Firefly\Eda\Consumer\TopicSubscriptionResolver;
use Firefly\Eda\Tests\Support\EdaConsumeCommandTestCase;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

uses(EdaConsumeCommandTestCase::class);

/**
 * THE ROUTING CONTRACT, at the command. firefly:eda:consume is the process that turns the compiled manifest into
 * a live broker subscription, and it used to hand EventConsumer::subscribe() the manifest's EVENT-TYPE patterns.
 * Every publisher, meanwhile, routes by DESTINATION: KafkaEventPublisher produces to topic=$destination,
 * RabbitMqEventPublisher parses $destination into exchange/routingKey, PostgresEventPublisher writes it to the
 * outbox row. So a perfectly ordinary app publishing to a topic `orders` with event type `order.created` got a
 * worker bound to a route literally named `order.*` — no error, no warning, just a consumer that never received
 * anything. The command must subscribe by destination.
 */
it('subscribes the consumer to broker destinations, never to #[EventListener] event-type patterns', function () {
    /** @var EdaConsumeCommandTestCase $this */
    expect($this->runConsume())->toBe(0)
        ->and($this->consumer->subscribed)->toBe([[TopicSubscriptionResolver::CATCH_ALL]])
        ->and($this->consumer->subscribed[0])->not->toContain('order.*');
});

it('subscribes to firefly.eda.destinations when the operator has configured them', function () {
    /** @var EdaConsumeCommandTestCase $this */
    $this->setDestinationConfig(['orders', 'users']);

    expect($this->runConsume())->toBe(0)
        ->and($this->consumer->subscribed)->toBe([['orders', 'users']]);
});

/**
 * --destination beats config so an operator can shard one manifest across several workers (one destination each)
 * without editing config per deployment — the `queue:work --queue=` idiom.
 */
it('lets --destination override the configured destinations', function () {
    /** @var EdaConsumeCommandTestCase $this */
    $this->setDestinationConfig(['orders']);

    expect($this->runConsume(['--destination' => ['users']]))->toBe(0)
        ->and($this->consumer->subscribed)->toBe([['users']]);
});

/**
 * A destination dropped for being the wrong type would be an invisible subscription gap: the worker starts,
 * reports success, and simply never receives that route's events. Fail at startup instead of filtering.
 */
it('fails loud when firefly.eda.destinations holds a non-string entry', function () {
    /** @var EdaConsumeCommandTestCase $this */
    $this->setDestinationConfig(['orders', 42]);

    $this->runConsume();
})->throws(ConfigurationException::class);

it('exits FAILURE without subscribing when no broker EventConsumer is bound', function () {
    /** @var EdaConsumeCommandTestCase $this */
    $this->app()->forgetInstance(EventConsumer::class);

    expect($this->runConsume())->toBe(1)
        ->and($this->consumer->subscribed)->toBe([]);
});
