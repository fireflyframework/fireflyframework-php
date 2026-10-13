<?php

declare(strict_types=1);

namespace Lumen\Tests\EventSourcing;

use Firefly\EventSourcing\Orchestration\EventSourcedExecutionPersistenceProvider;
use Firefly\EventSourcing\Store\EventStore;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Firefly\Orchestration\Saga\Engine\SagaEngine;
use Firefly\Security\Core\SecurityContextHolder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Lumen\Application\Query\GetBalance;

uses(LumenEventSourcedOrchestrationTestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

it('keeps the payout saga\'s execution as an event stream in the event store', function () {
    /** @var LumenEventSourcedOrchestrationTestCase $this */
    $this->signInAdmin();
    $walletId = $this->openWallet(50000);

    $result = $this->app()->make(SagaEngine::class)->execute('payout', ['walletId' => $walletId, 'iban' => 'ES9121000418450200051332', 'amountMinor' => 20000]);
    $stream = $this->app()->make(EventStore::class)->load($result->correlationId);

    expect($result->success)->toBeTrue()
        ->and($this->app()->make(ExecutionPersistenceProvider::class))->toBeInstanceOf(EventSourcedExecutionPersistenceProvider::class)
        ->and($stream->aggregateType())->toBe('orchestration.execution')
        ->and($stream->events[0]->eventType)->toBe('orchestration.execution.started')
        ->and(DB::table(OrchestrationSchema::EXECUTIONS)->where('correlation_id', $result->correlationId)->value('status'))->toBe('COMPLETED')
        ->and($this->queries()->ask(new GetBalance($walletId)))->toBe(30000);
})->group('lumen');

it('rebuilds the executions read model from the event streams', function () {
    /** @var LumenEventSourcedOrchestrationTestCase $this */
    $this->signInAdmin();
    $result = $this->app()->make(SagaEngine::class)->execute('payout', ['walletId' => $this->openWallet(50000), 'iban' => 'ES9121000418450200051332', 'amountMinor' => 20000]);
    DB::table(OrchestrationSchema::SEARCH_ATTRIBUTES)->delete();
    DB::table(OrchestrationSchema::EXECUTIONS)->delete();

    $exit = Artisan::call('firefly:eventsourcing:projections:rebuild', ['projection' => 'orchestration.executions']);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('orchestration.executions rebuilt')
        ->and(DB::table(OrchestrationSchema::EXECUTIONS)->where('correlation_id', $result->correlationId)->value('status'))->toBe('COMPLETED');
})->group('lumen');
