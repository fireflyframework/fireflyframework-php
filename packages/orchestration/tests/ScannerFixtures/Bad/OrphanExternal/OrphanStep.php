<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\ScannerFixtures\Bad\OrphanExternal;

use Firefly\Orchestration\Saga\Attributes\ExternalSagaStep;

final class OrphanStep
{
    #[ExternalSagaStep(saga: 'ghost', id: 'a')]
    public function a(): void {}
}
