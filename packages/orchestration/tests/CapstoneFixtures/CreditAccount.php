<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\CapstoneFixtures;

use Firefly\Container\Attributes\Service;
use Firefly\Orchestration\Core\Argument\Input;
use Firefly\Orchestration\Tcc\Attributes\CancelMethod;
use Firefly\Orchestration\Tcc\Attributes\ConfirmMethod;
use Firefly\Orchestration\Tcc\Attributes\TccParticipant;
use Firefly\Orchestration\Tcc\Attributes\TryMethod;
use RuntimeException;

#[Service]
#[TccParticipant(tcc: 'transfer', id: 'credit', order: 2)]
final class CreditAccount
{
    public function __construct(private readonly Ledger $ledger) {}

    #[TryMethod]
    public function hold(#[Input('to')] string $account): string
    {
        if ($account === 'closed') {
            throw new RuntimeException('account closed');
        }
        $this->ledger->record("credit hold {$account}");

        return "credit-{$account}";
    }

    #[ConfirmMethod]
    public function commit(string $hold): void
    {
        $this->ledger->record("credit commit {$hold}");
    }

    #[CancelMethod]
    public function release(string $hold): void
    {
        $this->ledger->record("credit release {$hold}");
    }
}
