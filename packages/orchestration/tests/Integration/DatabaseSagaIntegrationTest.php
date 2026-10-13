<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Dlq\DatabaseDeadLetterStore;
use Firefly\Orchestration\Persistence\DatabaseExecutionPersistenceProvider;
use Firefly\Orchestration\Saga\Builder\SagaBuilder;
use Firefly\Orchestration\Tests\Support\IntegrationDatabase;
use Firefly\Orchestration\Tests\Support\TestEngines;
use Firefly\Orchestration\Workflow\Builder\WorkflowBuilder;

foreach (IntegrationDatabase::servers() as $server => [$driver, $variable, $extension]) {
    it("runs, compensates and dead-letters a saga, and parks and wakes a workflow, on {$server}", function () use ($driver, $variable): void {
        $connection = IntegrationDatabase::fresh(IntegrationDatabase::connection($driver, $variable));
        try {
            $engines = TestEngines::create(persistence: new DatabaseExecutionPersistenceProvider($connection), deadLetters: new DatabaseDeadLetterStore($connection));
            $saga = SagaBuilder::saga('order')
                ->step('reserve')->handler(static fn (): array => ['hold' => 'h-1', 'note' => 'café'])->compensation(static fn (): string => 'released')->add()
                ->step('charge')->dependsOn('reserve')->handler(static fn (): never => throw new RuntimeException('card declined'))->add()
                ->build();
            $engines->workflowRegistry->register(WorkflowBuilder::workflow('approval')
                ->step('approve')->waitForTimer(60_000, 'deadline')->add()->build());

            $failed = $engines->sagas->execute($saga, ['orderId' => 7], correlationId: "it-{$variable}-saga");
            $waiting = $engines->workflows->start('approval', correlationId: "it-{$variable}-wf");
            $engines->clock->advance(61.0);
            $woken = $engines->workflows->resumeDue();

            expect($failed->status)->toBe(ExecutionStatus::Failed)
                ->and($engines->persistence->findById("it-{$variable}-saga")?->compensationResults)->toBe(['reserve' => 'released'])
                ->and($engines->persistence->findById("it-{$variable}-saga")?->stepResults['reserve'] ?? null)->toBe(['hold' => 'h-1', 'note' => 'café'])
                ->and($engines->deadLetterStore->findByCorrelationId("it-{$variable}-saga")[0]->stepId ?? null)->toBe('charge')
                ->and($waiting->status)->toBe(ExecutionStatus::Waiting)
                ->and($woken[0]->status ?? null)->toBe(ExecutionStatus::Completed);
        } finally {
            $connection->disconnect();
        }
    })->group('integration')->skip(IntegrationDatabase::skipReason($variable, $extension) !== null, (string) IntegrationDatabase::skipReason($variable, $extension));
}
