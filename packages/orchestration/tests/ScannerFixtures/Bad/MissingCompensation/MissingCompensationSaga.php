<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\ScannerFixtures\Bad\MissingCompensation;

use Firefly\Orchestration\Saga\Attributes\Saga;
use Firefly\Orchestration\Saga\Attributes\SagaStep;

#[Saga(name: 'missing')]
final class MissingCompensationSaga
{
    #[SagaStep(id: 'a', compensate: 'nope')]
    public function a(): void {}
}
