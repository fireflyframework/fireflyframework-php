<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\ScannerFixtures\Bad\InitialDelay;

use Firefly\Orchestration\Saga\Attributes\Saga;
use Firefly\Orchestration\Saga\Attributes\SagaStep;
use Firefly\Orchestration\Saga\Attributes\ScheduledSaga;

#[Saga(name: 'delayed')]
#[ScheduledSaga(fixedRate: 1000, initialDelay: 5000)]
final class InitialDelaySaga
{
    #[SagaStep(id: 'a')]
    public function a(): void {}
}
