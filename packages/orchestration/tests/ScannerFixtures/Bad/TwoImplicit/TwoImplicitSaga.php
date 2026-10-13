<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\ScannerFixtures\Bad\TwoImplicit;

use Firefly\Orchestration\Saga\Attributes\Saga;
use Firefly\Orchestration\Saga\Attributes\SagaStep;

#[Saga(name: 'two')]
final class TwoImplicitSaga
{
    #[SagaStep(id: 'a')]
    public function a(string $first, string $second): void {}
}
