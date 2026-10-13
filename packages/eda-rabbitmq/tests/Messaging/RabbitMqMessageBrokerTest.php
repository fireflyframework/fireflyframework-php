<?php

declare(strict_types=1);

use Firefly\Eda\Rabbitmq\Messaging\RabbitMqMessageBroker;
use Firefly\Eda\Rabbitmq\Tests\Fixtures\FakeAmqpMessagingChannel;
use Firefly\Messaging\Container\LinearBackOff;
use Firefly\Messaging\Container\ListenerContainerBroker;
use Firefly\Messaging\Container\ListenerInvoker;
use Firefly\Messaging\Container\ListenerOptions;
use Firefly\Messaging\Container\RetryPolicy;
use Firefly\Messaging\Exception\MessagingException;
use Firefly\Messaging\Message;

function w4RabbitBroker(FakeAmqpMessagingChannel $channel, int $maxAttempts = 1, bool $deadLetters = true): RabbitMqMessageBroker
{
    return new RabbitMqMessageBroker($channel, new RetryPolicy($maxAttempts, new LinearBackOff(0.0)), new ListenerInvoker(static function (float $s): void {}), deadLetterEnabled: $deadLetters);
}

it('publishes to the durable direct exchange, carrying the key in a header', function () {
    $channel = new FakeAmqpMessagingChannel;
    $broker = w4RabbitBroker($channel);

    $broker->publish('orders', 'bytes', 'k-1', ['x-trace' => 't']);
    $broker->publish('orders', 'more');

    expect($broker)->toBeInstanceOf(ListenerContainerBroker::class)
        ->and($channel->exchanges)->toBe(['firefly.messages' => 'direct'])
        ->and($channel->published[0])->toBe(['exchange' => 'firefly.messages', 'routingKey' => 'orders', 'body' => 'bytes', 'headers' => ['x-trace' => 't', 'x-message-key' => 'k-1']])
        ->and($channel->published[1]['headers'])->toBe([]);
});

it('declares a queue per subscription, its dead-letter route and its .dlq on the first poll', function () {
    $channel = new FakeAmqpMessagingChannel;
    $broker = w4RabbitBroker($channel);
    $broker->subscribeListener('orders', static function (): void {}, null, new ListenerOptions);
    $broker->subscribeListener('invoices', static function (): void {}, 'billing', new ListenerOptions);

    expect($broker->poll(10))->toBeFalse()
        ->and($channel->exchanges)->toBe(['firefly.messages' => 'direct', 'firefly.messages.dlx' => 'direct'])
        ->and($channel->queues)->toBe([
            'firefly.orders' => ['x-dead-letter-exchange' => 'firefly.messages.dlx', 'x-dead-letter-routing-key' => 'firefly.orders'],
            'firefly.orders.dlq' => [],
            'billing' => ['x-dead-letter-exchange' => 'firefly.messages.dlx', 'x-dead-letter-routing-key' => 'billing'],
            'billing.dlq' => [],
        ])
        ->and($channel->bindings)->toBe([
            ['firefly.orders', 'firefly.messages', 'orders'],
            ['firefly.orders.dlq', 'firefly.messages.dlx', 'firefly.orders'],
            ['billing', 'firefly.messages', 'invoices'],
            ['billing.dlq', 'firefly.messages.dlx', 'billing'],
        ])
        ->and($channel->prefetch)->toBe(10)
        ->and(fn () => $broker->subscribeListener('late', static function (): void {}, null, new ListenerOptions))->toThrow(MessagingException::class, 'already consuming');
});

it('refuses two topics on one queue', function () {
    $broker = w4RabbitBroker(new FakeAmqpMessagingChannel);
    $broker->subscribeListener('a', static function (): void {}, 'shared', new ListenerOptions);

    expect(fn () => $broker->subscribeListener('b', static function (): void {}, 'shared', new ListenerOptions))->toThrow(MessagingException::class, 'one topic');
});

it('delivers to the listeners of the queue and acks', function () {
    $channel = new FakeAmqpMessagingChannel;
    $broker = w4RabbitBroker($channel);
    $seen = [];
    $broker->subscribeListener('orders', function (Message $message) use (&$seen): void {
        $seen[] = [$message->topic, $message->value, $message->key, $message->headers];
    }, null, new ListenerOptions);
    $channel->deliver('firefly.orders', 'orders', 'v', ['x-message-key' => 'k', 'h' => '1'], 5);

    expect($broker->poll(10))->toBeTrue()
        ->and($seen)->toBe([['orders', 'v', 'k', ['h' => '1']]])
        ->and($channel->acked)->toBe([5])
        ->and($channel->nacked)->toBe([]);
});

it('retries, then rejects without requeue so the broker routes the message to the .dlq', function () {
    $channel = new FakeAmqpMessagingChannel;
    $broker = w4RabbitBroker($channel, maxAttempts: 3);
    $calls = 0;
    $broker->subscribeListener('orders', function () use (&$calls): void {
        $calls++;

        throw new RuntimeException('down');
    }, null, new ListenerOptions);
    $channel->deliver('firefly.orders', 'orders', 'v', [], 9);

    $broker->poll(10);

    expect($calls)->toBe(3)->and($channel->nacked)->toBe([[9, false]])->and($channel->acked)->toBe([]);
});

it("republishes to the listener's own dead-letter topic with the diagnostic headers, then acks", function () {
    $channel = new FakeAmqpMessagingChannel;
    $broker = w4RabbitBroker($channel);
    $broker->subscribeListener('orders', static function (): void {
        throw new DomainException('bad order');
    }, null, new ListenerOptions(1, null, 'orders.parked'));
    $channel->deliver('firefly.orders', 'orders', 'v', ['x-message-key' => 'k'], 3);

    $broker->poll(10);

    expect($channel->published)->toHaveCount(1)
        ->and($channel->published[0]['routingKey'])->toBe('orders.parked')
        ->and($channel->published[0]['headers'])->toMatchArray([
            'x-message-key' => 'k',
            'x-original-topic' => 'orders',
            'x-exception' => 'DomainException',
            'x-exception-message' => 'bad order',
            'x-delivery-attempts' => '1',
        ])
        ->and($channel->acked)->toBe([3]);
});

it("gives a listener's dead-letter topic a bound parking queue, so the direct exchange cannot drop the dead letter", function () {
    $channel = new FakeAmqpMessagingChannel;
    $broker = w4RabbitBroker($channel);
    $broker->subscribeListener('orders', static function (): void {}, null, new ListenerOptions(1, null, 'orders.parked'));
    $broker->subscribeListener('invoices', static function (): void {}, 'billing', new ListenerOptions(1, null, 'orders.parked'));

    $broker->poll(10);

    expect($channel->queues)->toHaveKey('firefly.orders.parked')
        ->and($channel->queues['firefly.orders.parked'])->toBe(['x-dead-letter-exchange' => 'firefly.messages.dlx', 'x-dead-letter-routing-key' => 'firefly.orders.parked'])
        ->and($channel->queues['firefly.orders.parked.dlq'])->toBe([])
        ->and($channel->bindings)->toContain(['firefly.orders.parked', 'firefly.messages', 'orders.parked'])
        ->and($channel->bindings)->toContain(['firefly.orders.parked.dlq', 'firefly.messages.dlx', 'firefly.orders.parked'])
        ->and(array_filter($channel->bindings, static fn (array $binding): bool => $binding[0] === 'firefly.orders.parked'))->toHaveCount(1);
});

it('declares no parking queue for a dead-letter topic a subscription already consumes', function () {
    $channel = new FakeAmqpMessagingChannel;
    $broker = w4RabbitBroker($channel);
    $broker->subscribeListener('orders', static function (): void {}, null, new ListenerOptions(1, null, 'orders.parked'));
    $broker->subscribeListener('orders.parked', static function (): void {}, 'parking', new ListenerOptions);

    $broker->poll(10);

    expect($channel->queues)->not->toHaveKey('firefly.orders.parked')
        ->and($channel->bindings)->toContain(['parking', 'firefly.messages', 'orders.parked']);
});

it('acks and logs after the last attempt when dead letters are off', function () {
    $channel = new FakeAmqpMessagingChannel;
    $broker = w4RabbitBroker($channel, deadLetters: false);
    $broker->subscribeListener('orders', static function (): void {
        throw new RuntimeException('no');
    }, null, new ListenerOptions);
    $channel->deliver('firefly.orders', 'orders', 'v', [], 4);

    $broker->poll(10);

    expect($channel->acked)->toBe([4])->and($channel->nacked)->toBe([]);
});

it('closes the channel on stop', function () {
    $channel = new FakeAmqpMessagingChannel;
    w4RabbitBroker($channel)->stop();

    expect($channel->closed)->toBeTrue()
        ->and(RabbitMqMessageBroker::queueNameOf('orders', null))->toBe('firefly.orders')
        ->and(RabbitMqMessageBroker::queueNameOf('orders', 'billing'))->toBe('billing');
});
