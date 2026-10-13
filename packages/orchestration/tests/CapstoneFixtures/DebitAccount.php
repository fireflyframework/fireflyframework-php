<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\CapstoneFixtures;

use Firefly\Container\Attributes\Service;
use Firefly\Orchestration\Core\Argument\Input;
use Firefly\Orchestration\Tcc\Attributes\CancelMethod;
use Firefly\Orchestration\Tcc\Attributes\ConfirmMethod;
use Firefly\Orchestration\Tcc\Attributes\FromTry;
use Firefly\Orchestration\Tcc\Attributes\TccParticipant;
use Firefly\Orchestration\Tcc\Attributes\TryMethod;

#[Service]
#[TccParticipant(tcc: 'transfer', id: 'debit', order: 1)]
final class DebitAccount
{
    public function __construct(private readonly Ledger $ledger) {}

    #[TryMethod]
    public function hold(#[Input('amount')] int $amount): string
    {
        $this->ledger->record("hold {$amount}");

        return "hold-{$amount}";
    }

    #[ConfirmMethod]
    public function commit(#[FromTry] string $hold): void
    {
        $this->ledger->record("commit {$hold}");
    }

    #[CancelMethod]
    public function release(#[FromTry] string $hold): void
    {
        $this->ledger->record("release {$hold}");
    }
}
