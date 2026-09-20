<?php

declare(strict_types=1);

use Firefly\Eda\Bus\InMemoryEventBus;
use Firefly\Eda\Bus\QueueEventBus;
use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Consumer\SubscriberRegistrySink;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Eda\Tracing\NoOpEdaTracing;
use Illuminate\Container\Container;

/**
 * An EdaTracing that stamps a marker header on publish and logs every call, so the tests can see that the
 * envelope the subscriber received is the one the seam stamped, and that consume wrapped delivery.
 *
 * @return EdaTracing&object{calls: list<string>}
 */
function recordingEdaTracing(): EdaTracing
{
    return new class implements EdaTracing
    {
        /** @var list<string> */
        public array $calls = [];

        public function tracePublish(string $destination, string $eventType, array $headers, callable $send): void
        {
            $this->calls[] = "publish {$destination} {$eventType}";
            $send([...$headers, 'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);
        }

        public function traceConsume(EventEnvelope $envelope, callable $deliver): void
        {
            $this->calls[] = "process {$envelope->destination} ".($envelope->headers['traceparent'] ?? '-');
            $deliver($envelope);
        }
    };
}

it('routes an in-memory publish through the seam: stamped headers reach the subscriber inside a consume wrap', function () {
    $tracing = recordingEdaTracing();
    $bus = new InMemoryEventBus(new SubscriberRegistry, $tracing);
    $received = null;
    $bus->subscribe('order.*', function (EventEnvelope $envelope) use (&$received): void {
        $received = $envelope;
    });

    $bus->publish('orders', 'order.created', ['id' => 7], ['x-correlation-id' => 'c1']);

    expect($received)->toBeInstanceOf(EventEnvelope::class)
        ->and($received?->headers)->toBe(['x-correlation-id' => 'c1', 'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'])
        ->and($tracing->calls)->toBe(['publish orders order.created', 'process orders 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);
});

it('wraps the worker-side deliver() of the queue bus and the broker consumer sink in a consume span', function () {
    $tracing = recordingEdaTracing();
    $registry = new SubscriberRegistry;
    $seen = [];
    $registry->subscribe('*', function (EventEnvelope $envelope) use (&$seen): void {
        $seen[] = $envelope->eventType;
    });
    $envelope = new EventEnvelope('order.paid', 'orders', [], ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);

    (new QueueEventBus($registry, new Container, null, null, $tracing))->deliver($envelope);
    (new SubscriberRegistrySink($registry, $tracing))->handle($envelope);

    expect($seen)->toBe(['order.paid', 'order.paid'])
        ->and($tracing->calls)->toBe([
            'process orders 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            'process orders 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        ]);
});

it('defaults to the NoOp seam, which changes nothing about the envelope', function () {
    $bus = new InMemoryEventBus(new SubscriberRegistry);
    $received = null;
    $bus->subscribe('*', function (EventEnvelope $envelope) use (&$received): void {
        $received = $envelope;
    });

    $bus->publish('orders', 'order.created', [], ['k' => 'v']);

    $noop = new NoOpEdaTracing;
    $sent = null;
    $noop->tracePublish('d', 't', ['a' => 'b'], function (array $headers) use (&$sent): void {
        $sent = $headers;
    });

    expect($received?->headers)->toBe(['k' => 'v'])->and($sent)->toBe(['a' => 'b']);
});
