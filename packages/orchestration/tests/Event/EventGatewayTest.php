<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Event\EventGateway;

it('routes a trigger event to the registered executor with its payload', function (): void {
    $gateway = new EventGateway;
    $gateway->register('orders.placed', 'order-saga', ExecutionPattern::Saga, static fn (array $payload): string => 'started '.json_encode($payload));

    expect($gateway->route('orders.placed', ['id' => 7]))->toBe('started {"id":7}')
        ->and($gateway->route('orders.cancelled', []))->toBeNull()
        ->and($gateway->has('orders.placed'))->toBeTrue()
        ->and($gateway->count())->toBe(1)
        ->and($gateway->eventTypes())->toBe(['orders.placed'])
        ->and($gateway->describe())->toBe([['eventType' => 'orders.placed', 'target' => 'order-saga', 'pattern' => 'SAGA']]);
});

it('refuses two definitions triggered by the same event type', function (): void {
    $gateway = new EventGateway;
    $gateway->register('orders.placed', 'a', ExecutionPattern::Saga, static fn (array $payload): null => null);
    $gateway->register('orders.placed', 'b', ExecutionPattern::Workflow, static fn (array $payload): null => null);
})->throws(ConfigurationException::class, 'orders.placed');

it('hands the executor the headers and the correlation id of the delivery', function (): void {
    $gateway = new EventGateway;
    $gateway->register('orders.placed', 'order-saga', ExecutionPattern::Saga, static fn (array $payload, array $headers, ?string $correlationId): array => [$headers, $correlationId]);

    expect($gateway->route('orders.placed', [], ['eventId' => 'e-1'], 'c-1'))->toBe([['eventId' => 'e-1'], 'c-1']);
});

it('derives a stable, column-sized correlation id from the triggering event', function (): void {
    $id = EventGateway::correlationIdFor('orders.placed', 'e-1');

    expect($id)->toBe(EventGateway::correlationIdFor('orders.placed', 'e-1'))
        ->and($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($id)->not->toBe(EventGateway::correlationIdFor('orders.placed', 'e-2'))
        ->and($id)->not->toBe(EventGateway::correlationIdFor('orders.cancelled', 'e-1'))
        ->and(strlen(EventGateway::correlationIdFor(str_repeat('very.long.event.type.', 20), str_repeat('x', 500))))->toBe(36);
});
