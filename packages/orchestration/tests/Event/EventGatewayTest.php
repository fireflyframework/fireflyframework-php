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
