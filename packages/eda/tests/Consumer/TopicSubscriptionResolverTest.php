<?php

declare(strict_types=1);

use Firefly\Eda\Consumer\TopicSubscriptionResolver;
use Firefly\Eda\Listener\EventListenerDescriptor;
use Firefly\Eda\Listener\EventListenerManifest;

/**
 * THE ROUTING CONTRACT. A broker routes by DESTINATION (the first argument of
 * EventPublisher::publish($destination, $eventType, ...)): Kafka produces to topic=$destination, RabbitMQ splits
 * it into exchange/routingKey, Postgres stores it on the outbox row. An #[EventListener] pattern is an
 * EVENT-TYPE glob, matched in-process by SubscriberRegistry against EventEnvelope::$eventType. They are two
 * different namespaces and nothing derives one from the other.
 *
 * This resolver used to return the manifest's event-type patterns and hand them to EventConsumer::subscribe(),
 * i.e. it bound `order.*` as a Kafka topic regex / an AMQP routing key while publishers were producing to a
 * topic called `orders`. Nothing errored — the worker just sat there receiving nothing. These cases pin that
 * the resolver never again treats a pattern as a destination.
 */
it('never treats an #[EventListener] event-type pattern as a broker destination', function () {
    $manifest = new EventListenerManifest([
        new EventListenerDescriptor('A', 'on', ['user.*', 'order.created'], 0),
        new EventListenerDescriptor('B', 'on', ['user.*'], 0),
    ]);

    $destinations = (new TopicSubscriptionResolver)->resolve($manifest);

    expect($destinations)->toBe([TopicSubscriptionResolver::CATCH_ALL])
        ->and($destinations)->not->toContain('user.*')
        ->and($destinations)->not->toContain('order.created');
});

it('falls back to the catch-all when the manifest is empty', function () {
    expect((new TopicSubscriptionResolver)->resolve(new EventListenerManifest([])))
        ->toBe([TopicSubscriptionResolver::CATCH_ALL]);
});

it('returns the operator-configured destinations verbatim, deduplicated, when they are given', function () {
    $manifest = new EventListenerManifest([
        new EventListenerDescriptor('A', 'on', ['user.*'], 0, ['ignored.topic']),
    ]);

    expect((new TopicSubscriptionResolver)->resolve($manifest, ['orders', 'users', 'orders']))
        ->toBe(['orders', 'users']);
});

it('narrows to the union of declared destinations when every listener declares at least one', function () {
    $manifest = new EventListenerManifest([
        new EventListenerDescriptor('A', 'on', ['user.*'], 0, ['users', 'orders']),
        new EventListenerDescriptor('B', 'on', ['order.*'], 0, ['orders']),
    ]);

    expect((new TopicSubscriptionResolver)->resolve($manifest))->toBe(['users', 'orders']);
});

/**
 * The safety rule that makes automatic narrowing usable at all: narrowing is only correct when EVERY listener
 * has told us which destination it needs. One listener that declares none is a listener whose destination we
 * cannot know, and binding only the destinations the others declared would drop its events silently — the exact
 * failure mode this whole fix exists to remove. So a single undeclared listener re-widens the subscription to
 * the catch-all, and SubscriberRegistry's fnmatch does the fine filter after receipt.
 */
it('re-widens to the catch-all when any listener declares no destination', function () {
    $manifest = new EventListenerManifest([
        new EventListenerDescriptor('A', 'on', ['user.*'], 0, ['users']),
        new EventListenerDescriptor('B', 'on', ['order.*'], 0),
    ]);

    expect((new TopicSubscriptionResolver)->resolve($manifest))->toBe([TopicSubscriptionResolver::CATCH_ALL]);
});
