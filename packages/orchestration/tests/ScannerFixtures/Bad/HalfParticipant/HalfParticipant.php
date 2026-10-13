<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\ScannerFixtures\Bad\HalfParticipant;

use Firefly\Orchestration\Tcc\Attributes\ConfirmMethod;
use Firefly\Orchestration\Tcc\Attributes\TccParticipant;
use Firefly\Orchestration\Tcc\Attributes\TryMethod;

#[TccParticipant(tcc: 'half', id: 'p')]
final class HalfParticipant
{
    #[TryMethod]
    public function hold(): void {}

    #[ConfirmMethod]
    public function commit(): void {}
}
