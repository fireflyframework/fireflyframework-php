<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\ScannerFixtures\Bad\Cycle;

use Firefly\Orchestration\Saga\Attributes\Saga;
use Firefly\Orchestration\Saga\Attributes\SagaStep;

#[Saga(name: 'cycle')]
final class CycleSaga
{
    #[SagaStep(id: 'a', dependsOn: ['b'])]
    public function a(): void {}

    #[SagaStep(id: 'b', dependsOn: ['a'])]
    public function b(): void {}
}
