<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\ScannerFixtures\Good;

use Firefly\Orchestration\Tcc\Attributes\CancelMethod;
use Firefly\Orchestration\Tcc\Attributes\ConfirmMethod;
use Firefly\Orchestration\Tcc\Attributes\FromTry;
use Firefly\Orchestration\Tcc\Attributes\TccEvent;
use Firefly\Orchestration\Tcc\Attributes\TccParticipant;
use Firefly\Orchestration\Tcc\Attributes\TryMethod;

#[TccParticipant(tcc: 'transfer', id: 'debit', order: 1)]
#[TccEvent(topic: 'ledger', eventType: 'ledger.debited', key: 'account')]
final class DebitParticipant
{
    /** @param array<string, mixed> $input */
    #[TryMethod(timeoutMs: 500)]
    public function hold(array $input): string
    {
        return 'hold-1';
    }

    #[ConfirmMethod]
    public function commit(#[FromTry] string $hold): void {}

    #[CancelMethod(retry: 2)]
    public function release(#[FromTry('debit')] string $hold): void {}
}
