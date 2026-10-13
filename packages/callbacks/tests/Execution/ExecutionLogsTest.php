<?php

declare(strict_types=1);

use Firefly\Callbacks\CallbacksServiceProvider;
use Firefly\Callbacks\CallbacksWiringProvider;
use Firefly\Callbacks\Execution\CallbackExecution;
use Firefly\Callbacks\Execution\CallbackStatus;
use Firefly\Callbacks\Execution\DatabaseExecutionLog;
use Firefly\Callbacks\Execution\ExecutionLog;
use Firefly\Callbacks\Execution\InMemoryExecutionLog;
use Firefly\Callbacks\Schema\CallbackSchema;
use Firefly\Callbacks\Subscription\CallbackSubscription;
use Firefly\Eda\EventEnvelope;
use Illuminate\Database\Capsule\Manager as Capsule;

function w4ExecutionsSqlite(): DatabaseExecutionLog
{
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $connection = $capsule->getConnection();
    CallbackSchema::create($connection->getSchemaBuilder());

    return new DatabaseExecutionLog($connection);
}

dataset('w4 execution logs', [
    'memory' => [static fn (): ExecutionLog => new InMemoryExecutionLog],
    'database (sqlite)' => [static fn (): ExecutionLog => w4ExecutionsSqlite()],
]);

function w4Subscription(string $id = 'sub-1'): CallbackSubscription
{
    return new CallbackSubscription($id, 'orders', 'https://partner.example.com/hooks', ['order.*'], 's');
}

it('derives one execution per subscription and event, carrying the exact body to send', function () {
    $envelope = new EventEnvelope('order.created', 'orders', ['id' => 7, 'total' => 9.5], [], 'evt-1', new DateTimeImmutable('2026-10-12T10:00:00+00:00'));
    $execution = CallbackExecution::pending(w4Subscription(), $envelope, 100);

    expect($execution->id)->toBe(CallbackExecution::idFor('sub-1', 'evt-1'))
        ->and($execution->id)->toMatch('/^[0-9a-f]{64}$/')
        ->and($execution->status)->toBe(CallbackStatus::Pending)
        ->and($execution->attempts)->toBe(0)
        ->and($execution->createdAt)->toBe(100)
        ->and($execution->updatedAt)->toBe(100)
        ->and($execution->url)->toBe('https://partner.example.com/hooks')
        ->and(json_decode($execution->body, true))->toBe([
            'id' => $execution->id,
            'eventId' => 'evt-1',
            'eventType' => 'order.created',
            'occurredAt' => '2026-10-12T10:00:00+00:00',
            'data' => ['id' => 7, 'total' => 9.5],
        ]);
});

it('gives a redelivered envelope the same execution id and keeps distinct pairs apart', function () {
    $redelivered = EventEnvelope::fromArray((new EventEnvelope('order.created', 'orders', [], [], 'evt-9'))->toArray());

    expect(CallbackExecution::pending(w4Subscription(), $redelivered, 5)->id)->toBe(CallbackExecution::idFor('sub-1', 'evt-9'))
        ->and(CallbackExecution::idFor('sub-1', 'evt-9'))->not->toBe(CallbackExecution::idFor('sub-2', 'evt-9'))
        // The separator keeps (a, bc) and (ab, c) apart.
        ->and(CallbackExecution::idFor('a', 'bc'))->not->toBe(CallbackExecution::idFor('ab', 'c'));
});

it('records, updates in place, finds and lists executions newest first with filters', function (Closure $make) {
    /** @var ExecutionLog $log */
    $log = $make();
    $first = CallbackExecution::pending(w4Subscription(), new EventEnvelope('order.created', 'orders', [], [], 'e-1'), 100);
    $second = CallbackExecution::pending(w4Subscription('sub-2'), new EventEnvelope('order.created', 'orders', [], [], 'e-2'), 101);

    $log->record($first);
    $log->record($second);
    $log->record($first->withOutcome(CallbackStatus::Failed, 3, 503, 'HTTP 503', 102));

    expect($log->find($first->id)?->status)->toBe(CallbackStatus::Failed)
        ->and($log->find($first->id)?->attempts)->toBe(3)
        ->and($log->find($first->id)?->responseStatus)->toBe(503)
        ->and($log->find($first->id)?->lastError)->toBe('HTTP 503')
        ->and($log->find($first->id)?->createdAt)->toBe(100)
        ->and($log->find($first->id)?->updatedAt)->toBe(102)
        ->and($log->find($first->id)?->body)->toBe($first->body)
        ->and($log->find($second->id)?->responseStatus)->toBeNull()
        ->and($log->find($second->id)?->lastError)->toBeNull()
        ->and(array_map(static fn (CallbackExecution $e): string => $e->eventId, $log->recent()))->toBe(['e-1', 'e-2'])
        ->and(array_map(static fn (CallbackExecution $e): string => $e->eventId, $log->recent(10, CallbackStatus::Pending)))->toBe(['e-2'])
        ->and(array_map(static fn (CallbackExecution $e): string => $e->eventId, $log->recent(10, null, 'sub-1')))->toBe(['e-1'])
        ->and($log->recent(10, CallbackStatus::Delivered))->toBe([])
        ->and($log->recent(1))->toHaveCount(1)
        ->and($log->recent(0))->toBe([])
        ->and($log->find('missing'))->toBeNull();
})->with('w4 execution logs');

it('keeps the createdAt of the first record when an execution is recorded again', function (Closure $make) {
    /** @var ExecutionLog $log */
    $log = $make();
    $execution = CallbackExecution::pending(w4Subscription(), new EventEnvelope('order.created', 'orders', [], [], 'e-1'), 100);
    $log->record($execution);

    // A replay rebuilt from a fresh pending() carries a later createdAt; the log keeps the original.
    $log->record(CallbackExecution::pending(w4Subscription(), new EventEnvelope('order.created', 'orders', [], [], 'e-1'), 200));

    expect($log->find($execution->id)?->createdAt)->toBe(100)
        ->and($log->find($execution->id)?->updatedAt)->toBe(200)
        ->and($log->recent())->toHaveCount(1);
})->with('w4 execution logs');

it('keeps the last error short enough to store', function () {
    $execution = CallbackExecution::pending(w4Subscription(), new EventEnvelope('a', 'd'), 1)
        ->withOutcome(CallbackStatus::Failed, 1, null, str_repeat('x', 5000), 2);
    $multibyte = CallbackExecution::pending(w4Subscription(), new EventEnvelope('a', 'd'), 1)
        ->withOutcome(CallbackStatus::Failed, 1, null, str_repeat('é', 5000), 2);

    expect(strlen((string) $execution->lastError))->toBe(2000)
        ->and(strlen((string) $multibyte->lastError))->toBeLessThanOrEqual(2000)
        ->and(mb_check_encoding((string) $multibyte->lastError, 'UTF-8'))->toBeTrue();
});

it('binds the memory log by default through the boot pipeline', function () {
    $app = fireflyApplication(config: ['firefly' => ['callbacks' => ['enabled' => true]]], providers: [CallbacksServiceProvider::class, CallbacksWiringProvider::class]);

    expect($app->make(ExecutionLog::class))->toBeInstanceOf(InMemoryExecutionLog::class);
});

it('binds no execution log while firefly.callbacks.enabled is off', function () {
    $app = fireflyApplication(config: ['firefly' => []], providers: [CallbacksServiceProvider::class, CallbacksWiringProvider::class]);

    expect($app->bound(ExecutionLog::class))->toBeFalse();
});
