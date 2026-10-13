<?php

declare(strict_types=1);

namespace Lumen\Domain\Loan;

use Firefly\EventSourcing\Aggregate\EventSourcedAggregateRoot;
use Firefly\EventSourcing\Aggregate\SnapshotAware;
use Firefly\Kernel\Exception\Business\ConflictException;
use Lumen\Domain\Currency;
use Lumen\Domain\Loan\Event\LoanGranted;
use Lumen\Domain\Loan\Event\LoanRepaid;
use Lumen\Domain\Loan\Event\LoanSettled;
use Lumen\Domain\Money;

/**
 * A loan Lumen grants into a wallet, event-sourced: its state IS its history. No row holds a loan's balance;
 * firefly_eventsourcing_events holds its LoanGranted, every LoanRepaid and the final LoanSettled, and LoanRepository
 * rebuilds the object by applying them in order — from the newest snapshot when there is one.
 *
 * A command method checks the invariant against the current state and calls recordThat(), which applies the event at
 * once and buffers it until the repository saves it: a repayment never exceeds what is outstanding, a settled loan
 * takes no more repayments, and the repayment that brings the balance to zero records LoanSettled with it. The
 * protected apply<Event>() methods are the only code that changes state, so replaying the history rebuilds exactly
 * the object that recorded it.
 */
final class Loan extends EventSourcedAggregateRoot implements SnapshotAware
{
    private string $walletId = '';

    private Currency $currency = Currency::EUR;

    private int $principalMinor = 0;

    private int $repaidMinor = 0;

    private int $repayments = 0;

    private bool $settled = false;

    public static function aggregateType(): string
    {
        return 'lumen.loan';
    }

    public static function grant(string $id, string $walletId, Money $principal): self
    {
        if (! $principal->isPositive()) {
            throw new ConflictException('a loan principal must be > 0');
        }

        $loan = new self($id);
        $loan->recordThat(new LoanGranted($id, $walletId, $principal->minorUnits, $principal->currency->value));

        return $loan;
    }

    public function repay(Money $amount): void
    {
        if ($this->settled) {
            throw new ConflictException("loan {$this->id()} is already settled");
        }
        if ($amount->currency !== $this->currency || ! $amount->isPositive()) {
            throw new ConflictException("a repayment must be a positive amount in {$this->currency->value}");
        }
        if ($amount->minorUnits > $this->outstanding()->minorUnits) {
            throw new ConflictException("cannot repay {$amount}; {$this->outstanding()} is outstanding");
        }

        $this->recordThat(new LoanRepaid($this->id(), $amount->minorUnits, $this->outstanding()->minorUnits - $amount->minorUnits));
        if ($this->outstanding()->minorUnits === 0) {
            $this->recordThat(new LoanSettled($this->id(), $this->repayments));
        }
    }

    public function walletId(): string
    {
        return $this->walletId;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function principal(): Money
    {
        return new Money($this->principalMinor, $this->currency);
    }

    public function outstanding(): Money
    {
        return new Money($this->principalMinor - $this->repaidMinor, $this->currency);
    }

    public function repaidMinor(): int
    {
        return $this->repaidMinor;
    }

    public function repayments(): int
    {
        return $this->repayments;
    }

    public function isSettled(): bool
    {
        return $this->settled;
    }

    protected function applyLoanGranted(LoanGranted $event): void
    {
        $this->walletId = $event->walletId;
        $this->currency = Currency::from($event->currency);
        $this->principalMinor = $event->principalMinor;
    }

    protected function applyLoanRepaid(LoanRepaid $event): void
    {
        $this->repaidMinor += $event->amountMinor;
        $this->repayments++;
    }

    protected function applyLoanSettled(LoanSettled $event): void
    {
        $this->settled = true;
    }

    /** @return array{walletId: string, currency: string, principalMinor: int, repaidMinor: int, repayments: int, settled: bool} */
    public function snapshotState(): array
    {
        return [
            'walletId' => $this->walletId,
            'currency' => $this->currency->value,
            'principalMinor' => $this->principalMinor,
            'repaidMinor' => $this->repaidMinor,
            'repayments' => $this->repayments,
            'settled' => $this->settled,
        ];
    }

    public function restoreSnapshot(array $state): void
    {
        $walletId = $state['walletId'] ?? '';
        $currency = $state['currency'] ?? '';
        $principal = $state['principalMinor'] ?? 0;
        $repaid = $state['repaidMinor'] ?? 0;
        $repayments = $state['repayments'] ?? 0;

        $this->walletId = is_string($walletId) ? $walletId : '';
        $this->currency = Currency::tryFrom(is_string($currency) ? $currency : '') ?? Currency::EUR;
        $this->principalMinor = is_int($principal) ? $principal : 0;
        $this->repaidMinor = is_int($repaid) ? $repaid : 0;
        $this->repayments = is_int($repayments) ? $repayments : 0;
        $this->settled = ($state['settled'] ?? false) === true;
    }

    /** Raise it whenever snapshotState()'s shape changes: an older snapshot is then ignored and the loan replayed. */
    public static function snapshotVersion(): int
    {
        return 1;
    }
}
