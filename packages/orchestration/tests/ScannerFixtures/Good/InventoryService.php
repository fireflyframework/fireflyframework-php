<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\ScannerFixtures\Good;

use Firefly\Orchestration\Core\Argument\CorrelationId;
use Firefly\Orchestration\Saga\Attributes\CompensationSagaStep;
use Firefly\Orchestration\Saga\Attributes\ExternalSagaStep;

final class InventoryService
{
    #[ExternalSagaStep(saga: 'order', id: 'notify', dependsOn: ['reserve'])]
    public function notify(#[CorrelationId] string $id): string
    {
        return 'sent';
    }

    #[CompensationSagaStep(saga: 'order', forStepId: 'notify')]
    public function unnotify(): void {}
}
