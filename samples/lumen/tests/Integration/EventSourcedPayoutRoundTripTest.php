<?php

declare(strict_types=1);

namespace Lumen\Tests\Integration;

use Firefly\EventSourcing\Store\EventStore;
use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Firefly\Orchestration\Saga\Engine\SagaEngine;
use Firefly\Testing\Integration\IntegrationDsn;
use Illuminate\Support\Facades\DB;
use Lumen\Application\Query\GetBalance;

uses(EventSourcedPayoutIntegrationTestCase::class);

/** The payout saga on firefly.orchestration.persistence.provider=event-sourced, on a real server per lane. */
it('runs the payout saga on the event-sourced store and keeps its read model', function (string $lane) {
    /** @var EventSourcedPayoutIntegrationTestCase $this */
    $this->signInAdmin();
    $walletId = $this->openWallet(50000);

    $result = $this->app()->make(SagaEngine::class)->execute('payout', ['walletId' => $walletId, 'iban' => 'ES9121000418450200051332', 'amountMinor' => 20000]);

    expect(DB::connection()->getName())->toBe('lumen-it')
        ->and($result->success)->toBeTrue()
        ->and($this->app()->make(EventStore::class)->load($result->correlationId)->aggregateType())->toBe('orchestration.execution')
        ->and(DB::table(OrchestrationSchema::EXECUTIONS)->where('correlation_id', $result->correlationId)->value('status'))->toBe('COMPLETED')
        ->and($this->queries()->ask(new GetBalance($walletId)))->toEqual(30000);
})->with(IntegrationDsn::lanes())->group('integration');
