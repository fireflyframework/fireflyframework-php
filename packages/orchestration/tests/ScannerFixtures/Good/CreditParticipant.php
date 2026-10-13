<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\ScannerFixtures\Good;

use Firefly\Orchestration\Tcc\Attributes\CancelMethod;
use Firefly\Orchestration\Tcc\Attributes\ConfirmMethod;
use Firefly\Orchestration\Tcc\Attributes\TccParticipant;
use Firefly\Orchestration\Tcc\Attributes\TryMethod;

#[TccParticipant(tcc: 'transfer', id: 'credit', order: 2, optional: true)]
final class CreditParticipant
{
    #[TryMethod]
    public function hold(): string
    {
        return 'credit-hold';
    }

    #[ConfirmMethod]
    public function commit(string $hold): void {}

    #[CancelMethod]
    public function release(string $hold): void {}
}
