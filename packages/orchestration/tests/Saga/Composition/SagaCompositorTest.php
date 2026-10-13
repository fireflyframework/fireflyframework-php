<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Exception\CompensationException;
use Firefly\Orchestration\Core\Exception\DefinitionNotFoundException;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Saga\Builder\SagaBuilder;
use Firefly\Orchestration\Saga\Composition\SagaComposition;
use Firefly\Orchestration\Saga\Composition\SagaCompositionBuilder;
use Firefly\Orchestration\Tests\Support\TestEngines;
use Firefly\Orchestration\Tests\Support\UnreadableExecutionPersistence;

/** @param list<string> $log */
function orchestrationCheckoutEngines(array &$log, ?TestEngines $engines = null): TestEngines
{
    $engines ??= TestEngines::create();
    $engines->sagaRegistry->register(SagaBuilder::saga('reserve-stock')
        ->step('reserve')->handler(static function (array $input) use (&$log): array {
            $log[] = 'reserve in '.(is_string($input['warehouse'] ?? null) ? $input['warehouse'] : '?');

            return ['reservationId' => 'r-1'];
        })->compensation(static function () use (&$log): void {
            $log[] = 'release stock';
        })->add()->build());
    $engines->sagaRegistry->register(SagaBuilder::saga('charge-card')
        ->step('charge')->handler(static function (array $input) use (&$log): string {
            $log[] = 'charge for '.(is_string($input['reservationId'] ?? null) ? $input['reservationId'] : '?');
            if (($input['amount'] ?? 0) > 100) {
                throw new RuntimeException('limit exceeded');
            }

            return 'paid';
        })->add()->build());
    $engines->sagaRegistry->register(SagaBuilder::saga('notify-customer')
        ->step('send')->handler(static function () use (&$log): string {
            $log[] = 'notify';

            return 'sent';
        })->add()->build());

    return $engines;
}

function orchestrationCheckout(): SagaComposition
{
    return SagaCompositionBuilder::composition('checkout')
        ->saga('stock')->sagaName('reserve-stock')->input('warehouse', 'eu-1')->add()
        ->saga('charge')->sagaName('charge-card')->dependsOn('stock')->add()
        ->saga('notify')->sagaName('notify-customer')->dependsOn('charge')->condition('input["notify"] == true')->add()
        ->dataFlow('stock', 'reservationId', 'charge', 'reservationId')
        ->build();
}

it('runs members layer by layer, flows data between them and skips a member whose condition is false', function (): void {
    $log = [];
    $engines = orchestrationCheckoutEngines($log);

    $result = $engines->compositor->compose(orchestrationCheckout(), ['amount' => 40, 'notify' => false]);

    expect($result->success)->toBeTrue()
        ->and($log)->toBe(['reserve in eu-1', 'charge for r-1'])
        ->and($result->skipped)->toBe(['notify'])
        ->and(array_keys($result->sagaResults))->toBe(['stock', 'charge'])
        ->and($result->sagaResults['charge']->headers['x-firefly-composition'] ?? null)->toBe($result->correlationId)
        ->and($engines->events->hooks())->toContain('onCompositionStarted')
        ->and(array_slice($engines->events->hooks(), -1))->toBe(['onCompositionCompleted']);
});

it('compensates the members that completed, in reverse, when a required member fails', function (): void {
    $log = [];
    $engines = orchestrationCheckoutEngines($log);

    $result = $engines->compositor->compose(orchestrationCheckout(), ['amount' => 500, 'notify' => true]);

    expect($result->success)->toBeFalse()
        ->and($result->error?->getMessage())->toBe('limit exceeded')
        ->and($result->compensated)->toBe(['stock'])
        ->and($result->compensationFailures)->toBe([])
        ->and($log)->toBe(['reserve in eu-1', 'charge for r-1', 'release stock'])
        ->and($engines->persistence->findById($result->sagaResults['stock']->correlationId)?->status)->toBe(ExecutionStatus::Cancelled);
});

it('tolerates an optional member failure and skips what depended on it', function (): void {
    $log = [];
    $engines = orchestrationCheckoutEngines($log);
    $composition = SagaCompositionBuilder::composition('soft')
        ->saga('charge')->sagaName('charge-card')->optional()->add()
        ->saga('notify')->sagaName('notify-customer')->dependsOn('charge')->add()
        ->build();

    $result = $engines->compositor->compose($composition, ['amount' => 500]);

    expect($result->success)->toBeTrue()
        ->and($result->sagaResults['charge']->isSuccess())->toBeFalse()
        ->and(array_map(static fn (Throwable $error): string => $error->getMessage(), $result->optionalFailures))->toBe(['charge' => 'limit exceeded'])
        ->and($result->skipped)->toBe(['notify'])
        ->and($log)->toBe(['charge for ?']);
});

it('compensates the members that completed when a later member cannot even start', function (): void {
    $log = [];
    $engines = orchestrationCheckoutEngines($log);
    $composition = SagaCompositionBuilder::composition('broken')
        ->saga('stock')->sagaName('reserve-stock')->add()
        ->saga('ghost')->sagaName('no-such-saga')->dependsOn('stock')->add()
        ->build();

    $result = $engines->compositor->compose($composition);

    expect($result->success)->toBeFalse()
        ->and($result->error?->getMessage())->toContain('no-such-saga')
        ->and($result->compensated)->toBe(['stock'])
        ->and($log)->toBe(['reserve in ?', 'release stock'])
        ->and($engines->persistence->findById($result->sagaResults['stock']->correlationId)?->status)->toBe(ExecutionStatus::Cancelled)
        ->and(array_slice($engines->events->calls, -1))->toBe([['hook' => 'onCompositionCompleted', 'args' => ['broken', $result->correlationId, false]]]);
});

it('compensates the members that completed when a condition cannot be evaluated', function (): void {
    $log = [];
    $engines = orchestrationCheckoutEngines($log);
    $composition = SagaCompositionBuilder::composition('bad-condition')
        ->saga('stock')->sagaName('reserve-stock')->add()
        ->saga('notify')->sagaName('notify-customer')->dependsOn('stock')->condition('input.missing.deeper == 1')->add()
        ->build();

    $result = $engines->compositor->compose($composition);

    expect($result->success)->toBeFalse()
        ->and($result->error?->getMessage())->toContain('could not be evaluated')
        ->and($result->compensated)->toBe(['stock'])
        ->and($log)->toBe(['reserve in ?', 'release stock']);
});

it('stamps the composition headers over a caller header of the same name', function (): void {
    $log = [];
    $engines = orchestrationCheckoutEngines($log);
    $composition = SagaCompositionBuilder::composition('headers')->saga('stock')->sagaName('reserve-stock')->add()->build();

    $result = $engines->compositor->compose($composition, [], ['x-firefly-composition' => 'forged', 'x-tenant' => 'acme']);

    expect($result->sagaResults['stock']->headers)->toMatchArray([
        'x-firefly-composition' => $result->correlationId,
        'x-firefly-composition-alias' => 'stock',
        'x-tenant' => 'acme',
    ]);
});

it('tolerates an optional member that cannot even start', function (): void {
    $log = [];
    $engines = orchestrationCheckoutEngines($log);
    $composition = SagaCompositionBuilder::composition('soft-start')
        ->saga('stock')->sagaName('reserve-stock')->add()
        ->saga('ghost')->sagaName('no-such-saga')->dependsOn('stock')->optional()->add()
        ->saga('after-ghost')->sagaName('notify-customer')->dependsOn('ghost')->add()
        ->saga('notify')->sagaName('notify-customer')->dependsOn('stock')->add()
        ->build();

    $result = $engines->compositor->compose($composition);

    expect($result->success)->toBeTrue()
        ->and($result->compensated)->toBe([])
        ->and(array_keys($result->optionalFailures))->toBe(['ghost'])
        ->and($result->optionalFailures['ghost'])->toBeInstanceOf(DefinitionNotFoundException::class)
        ->and($result->toArray()['optionalFailures'])->toBe(['ghost' => $result->optionalFailures['ghost']->getMessage()])
        ->and($result->skipped)->toBe(['after-ghost'])
        ->and($log)->toBe(['reserve in ?', 'notify'])
        ->and($engines->persistence->findById($result->sagaResults['stock']->correlationId)?->status)->toBe(ExecutionStatus::Completed);
});

it('does not report a member as compensated when its own compensation fails', function (string $handler): void {
    $log = [];
    $engines = orchestrationCheckoutEngines($log, TestEngines::create(['saga' => ['compensation-error-handler' => $handler]]));
    $engines->sagaRegistry->register(SagaBuilder::saga('hold-seat')
        ->step('hold')->handler(static fn (): string => 'held')
        ->compensation(static function (): void {
            throw new RuntimeException('seat service is down');
        })->add()->build());
    $composition = SagaCompositionBuilder::composition('trip')
        ->saga('stock')->sagaName('reserve-stock')->add()
        ->saga('seat')->sagaName('hold-seat')->dependsOn('stock')->add()
        ->saga('charge')->sagaName('charge-card')->dependsOn('seat')->add()
        ->build();

    $result = $engines->compositor->compose($composition, ['amount' => 500]);

    expect($result->success)->toBeFalse()
        ->and($result->compensated)->toBe(['stock'])
        ->and(array_keys($result->compensationFailures))->toBe(['seat'])
        ->and($result->compensationFailures['seat'])->toBeInstanceOf(CompensationException::class)
        ->and($result->compensationFailures['seat']->getMessage())->toBe('Composition member [seat] left [hold] uncompensated: seat service is down')
        ->and($result->toArray()['compensationFailures'])->toBe(['seat' => 'Composition member [seat] left [hold] uncompensated: seat service is down'])
        ->and($log)->toBe(['reserve in ?', 'charge for ?', 'release stock'])
        ->and($engines->events->calls)->toContain(['hook' => 'onStepCompensationFailed', 'args' => ['trip', $result->correlationId, 'seat', $result->compensationFailures['seat']->getMessage()]]);
})->with(['default', 'fail-fast']);

it('keeps sweeping and reports the member compensate() refuses before it starts', function (): void {
    $log = [];
    $store = new UnreadableExecutionPersistence;
    $engines = orchestrationCheckoutEngines($log, TestEngines::create([], $store));
    $engines->sagaRegistry->register(SagaBuilder::saga('hold-seat')
        ->step('hold')->handler(static fn (): string => 'held')
        ->compensation(static function () use (&$log): void {
            $log[] = 'release seat';
        })->add()->build());
    $engines->sagaRegistry->register(SagaBuilder::saga('charge-and-lose-stock')
        ->step('charge')->handler(static function () use ($store): never {
            // The store stops answering for the stock member after it completed.
            $store->refuseLoadsOf('reserve-stock');

            throw new RuntimeException('card declined');
        })->add()->build());
    $composition = SagaCompositionBuilder::composition('trip')
        ->saga('stock')->sagaName('reserve-stock')->add()
        ->saga('seat')->sagaName('hold-seat')->dependsOn('stock')->add()
        ->saga('charge')->sagaName('charge-and-lose-stock')->dependsOn('seat')->add()
        ->build();

    $result = $engines->compositor->compose($composition);

    expect($result->success)->toBeFalse()
        ->and($result->error?->getMessage())->toBe('card declined')
        ->and($result->compensated)->toBe(['seat'])
        ->and(array_keys($result->compensationFailures))->toBe(['stock'])
        ->and($result->compensationFailures['stock']->getMessage())->toStartWith('cannot read [')
        ->and($log)->toBe(['reserve in ?', 'release seat'])
        // Refused before it checkpointed COMPENSATING: the member is still COMPLETED, invisible to recovery.
        ->and($store->peek($result->sagaResults['stock']->correlationId)?->status)->toBe(ExecutionStatus::Completed)
        ->and($store->peek($result->sagaResults['seat']->correlationId)?->status)->toBe(ExecutionStatus::Cancelled)
        ->and($engines->events->calls)->toContain(['hook' => 'onStepCompensationFailed', 'args' => ['trip', $result->correlationId, 'stock', $result->compensationFailures['stock']->getMessage()]]);
});
