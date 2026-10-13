<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Argument\ParameterBinding;
use Firefly\Orchestration\Core\Callback\CallbackDefinition;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\MethodInvocation;
use Firefly\Orchestration\Core\StepOptions;
use Firefly\Orchestration\Core\TriggerMode;
use Firefly\Orchestration\Manifest\OrchestrationManifest;
use Firefly\Orchestration\Scanner\OrchestrationScanner;
use Firefly\Orchestration\Tests\ScannerFixtures\Good\ApprovalWorkflow;
use Firefly\Orchestration\Tests\ScannerFixtures\Good\DebitParticipant;
use Firefly\Orchestration\Tests\ScannerFixtures\Good\InventoryService;
use Firefly\Orchestration\Tests\ScannerFixtures\Good\OrderSaga;
use Firefly\Orchestration\Workflow\Registry\WaitDefinition;

function orchestrationScanGood(): OrchestrationManifest
{
    return (new OrchestrationScanner)->scan(['Firefly\\Orchestration\\Tests\\ScannerFixtures\\Good\\' => dirname(__DIR__).'/ScannerFixtures/Good']);
}

it('compiles a saga: steps, external steps, compensations, parameter plans, events and callbacks', function (): void {
    $saga = orchestrationScanGood()->sagas()[0];

    expect($saga->name)->toBe('order')
        ->and($saga->triggerEventType)->toBe('orders.placed')
        ->and($saga->stepIds())->toBe(['reserve', 'charge', 'notify'])
        ->and($saga->layers)->toBe([['reserve'], ['charge', 'notify']])
        ->and($saga->step('reserve')->options)->toEqual(new StepOptions(2, 5, 1000, false, 0.5, 'reserve-{input.orderId}'))
        ->and($saga->step('reserve')->invocation)->toEqual(new MethodInvocation(OrderSaga::class, 'reserve', [
            new ParameterBinding('orderId', ParameterBinding::INPUT_KEY, 'orderId'),
            new ParameterBinding('ctx', ParameterBinding::CONTEXT),
        ]))
        ->and($saga->step('reserve')->compensation)->toEqual(new MethodInvocation(OrderSaga::class, 'release', [
            new ParameterBinding('hold', ParameterBinding::IMPLICIT),
            new ParameterBinding('input', ParameterBinding::INPUT),
        ]))
        ->and($saga->step('charge')->invocation)->toEqual(new MethodInvocation(OrderSaga::class, 'charge', [
            new ParameterBinding('hold', ParameterBinding::FROM_STEP, 'reserve'),
            new ParameterBinding('tenant', ParameterBinding::HEADER, 'tenant'),
            new ParameterBinding('limit', ParameterBinding::VARIABLE, 'limit', required: true),
        ], 'receipt'))
        ->and($saga->step('charge')->compensationCritical)->toBeTrue()
        ->and($saga->step('charge')->event?->toArray())->toBe(['topic' => 'payments', 'type' => 'payment.captured', 'key' => 'orderId'])
        ->and($saga->step('notify')->invocation)->toEqual(new MethodInvocation(InventoryService::class, 'notify', [new ParameterBinding('id', ParameterBinding::CORRELATION_ID)]))
        ->and($saga->step('notify')->compensation)->toEqual(new MethodInvocation(InventoryService::class, 'unnotify'))
        ->and($saga->onComplete)->toEqual([new CallbackDefinition(OrderSaga::class, 'done', [CallbackDefinition::RESULT, CallbackDefinition::CONTEXT], priority: 5)])
        ->and($saga->onError)->toEqual([new CallbackDefinition(OrderSaga::class, 'failed', [CallbackDefinition::ERROR], errorTypes: [RuntimeException::class])]);
});

it('compiles a TCC from its participant classes, ordered, with per-phase options', function (): void {
    $tcc = orchestrationScanGood()->tccs()[0];

    expect($tcc->name)->toBe('transfer')
        ->and($tcc->participantIds())->toBe(['debit', 'credit'])
        ->and($tcc->participant('debit')->try->timeoutMs)->toBe(500)
        ->and($tcc->participant('debit')->confirm->invocation)->toEqual(new MethodInvocation(DebitParticipant::class, 'commit', [new ParameterBinding('hold', ParameterBinding::FROM_TRY)]))
        ->and($tcc->participant('debit')->cancel->invocation)->toEqual(new MethodInvocation(DebitParticipant::class, 'release', [new ParameterBinding('hold', ParameterBinding::FROM_TRY, 'debit')]))
        ->and($tcc->participant('debit')->cancel->retry)->toBe(2)
        ->and($tcc->participant('debit')->event?->type)->toBe('ledger.debited')
        ->and($tcc->participant('credit')->optional)->toBeTrue()
        ->and($tcc->maxRetries)->toBe(1)
        ->and($tcc->onComplete[0]->parameters)->toBe([CallbackDefinition::RESULT]);
});

it('compiles a workflow: inherited retries, waits, children, compensations, conditions, queries and callbacks', function (): void {
    $workflow = orchestrationScanGood()->workflows()[0];

    expect($workflow->id)->toBe('approval')
        ->and($workflow->triggerMode)->toBe(TriggerMode::Async)
        ->and($workflow->layers)->toBe([['submit'], ['decide', 'audit']])
        ->and($workflow->step('submit')->options)->toEqual(new StepOptions(1, 10))
        ->and($workflow->step('submit')->compensation)->toEqual(new MethodInvocation(ApprovalWorkflow::class, 'withdraw', [new ParameterBinding('result', ParameterBinding::IMPLICIT)]))
        ->and($workflow->step('decide')->options)->toEqual(new StepOptions(0, 10))
        ->and($workflow->step('decide')->wait?->kind)->toBe(WaitDefinition::ANY)
        ->and($workflow->step('decide')->wait?->signals[0]->timeoutMs)->toBe(86400000)
        ->and($workflow->step('decide')->wait?->timers[0]->id)->toBe('escalate')
        ->and($workflow->step('decide')->condition)->toBe('input["amount"] > 100')
        ->and($workflow->step('audit')->child?->workflowId)->toBe('audit-trail')
        ->and($workflow->query('status')->contextFirst)->toBeTrue()
        ->and($workflow->onError[0]->stepIds)->toBe(['decide'])
        ->and($workflow->onError[0]->suppressError)->toBeTrue();
});

it('compiles the schedules', function (): void {
    $schedules = orchestrationScanGood()->schedules();

    expect(array_map(static fn ($s): array => $s->toArray(), $schedules))->toBe([
        ['pattern' => 'SAGA', 'name' => 'order', 'cron' => '0 3 * * *', 'zone' => null, 'fixedRate' => null, 'fixedDelay' => null, 'input' => ['source' => 'nightly'], 'description' => 'nightly reconciliation'],
        ['pattern' => 'WORKFLOW', 'name' => 'approval', 'cron' => null, 'zone' => null, 'fixedRate' => '60000ms', 'fixedDelay' => null, 'input' => [], 'description' => ''],
    ])
        ->and($schedules[0]->lockName(0))->toBe('firefly:orchestration:scheduled:SAGA:order:0')
        ->and($schedules[0]->pattern)->toBe(ExecutionPattern::Saga);
});

it('refuses every invalid definition at scan time, naming it', function (string $case, string $message): void {
    expect(fn () => (new OrchestrationScanner)->scan(["Firefly\\Orchestration\\Tests\\ScannerFixtures\\Bad\\{$case}\\" => dirname(__DIR__)."/ScannerFixtures/Bad/{$case}"]))
        ->toThrow(TopologyValidationException::class, $message);
})->with([
    ['Cycle', 'Saga [cycle]: the steps form a cycle'],
    ['MissingCompensation', "names compensate: 'nope'"],
    ['TwoImplicit', 'cannot be bound'],
    ['HalfParticipant', 'needs exactly one #[TryMethod], #[ConfirmMethod] and #[CancelMethod]'],
    ['BadCondition', 'does not parse'],
    ['InitialDelay', 'initialDelay'],
    ['OrphanExternal', "no #[Saga(name: 'ghost')]"],
]);
