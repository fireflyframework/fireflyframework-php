<?php

declare(strict_types=1);

namespace Lumen\Domain;

use Firefly\Domain\HasDomainEvents;
use Firefly\Domain\RecordsDomainEvents;
use Firefly\Kernel\Exception\Business\ConflictException;
use Illuminate\Database\Eloquent\Model;
use Lumen\Domain\Event\FundsDeposited;
use Lumen\Domain\Event\FundsWithdrawn;
use Lumen\Domain\Event\TransferCompleted;
use Lumen\Domain\Event\WalletOpened;

final class Wallet extends Model implements RecordsDomainEvents
{
    use HasDomainEvents;

    protected $table = 'wallets';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['id', 'owner_id', 'currency', 'balance_minor'];

    public $timestamps = false;

    public static function open(string $id, string $ownerId, Currency $currency): self
    {
        if (trim($ownerId) === '') {
            throw new ConflictException('owner_id is required');
        }

        $wallet = new self([
            'id' => $id,
            'owner_id' => $ownerId,
            'currency' => $currency->value,
            'balance_minor' => 0,
        ]);
        $wallet->raiseEvent(new WalletOpened($id, $ownerId, $currency->value));

        return $wallet;
    }

    public function currency(): Currency
    {
        $value = $this->getAttribute('currency');

        return Currency::from(match (true) {
            is_string($value) => $value,
            default => '',
        });
    }

    public function balanceMoney(): Money
    {
        $value = $this->getAttribute('balance_minor');

        $minor = match (true) {
            is_int($value) => $value,
            is_numeric($value) => (int) $value,
            default => 0,
        };

        return new Money($minor, $this->currency());
    }

    /**
     * `getKey()` is declared `mixed` (a primary key may be any attribute type); Wallet's own key is always the
     * string `id` set in open(), so this narrows for the event payload rather than casting mixed directly.
     */
    private function walletId(): string
    {
        $key = $this->getKey();

        return match (true) {
            is_string($key) => $key,
            is_int($key) => (string) $key,
            default => '',
        };
    }

    public function deposit(Money $amount): void
    {
        $this->assertCurrency($amount);
        if (! $amount->isPositive()) {
            throw new ConflictException('deposit amount must be > 0');
        }
        $new = $this->balanceMoney()->add($amount);
        $this->setAttribute('balance_minor', $new->minorUnits);
        $this->raiseEvent(new FundsDeposited(
            $this->walletId(), $amount->minorUnits, $amount->currency->value, $new->minorUnits
        ));
    }

    public function withdraw(Money $amount): void
    {
        $this->assertCurrency($amount);
        if (! $amount->isPositive()) {
            throw new ConflictException('withdrawal amount must be > 0');
        }
        $remaining = $this->balanceMoney()->subtract($amount);
        if ($remaining->isNegative()) {
            throw new ConflictException(
                "cannot withdraw {$amount}; balance is {$this->balanceMoney()}"
            );
        }
        $this->setAttribute('balance_minor', $remaining->minorUnits);
        $this->raiseEvent(new FundsWithdrawn(
            $this->walletId(), $amount->minorUnits, $amount->currency->value, $remaining->minorUnits
        ));
    }

    /**
     * Records that a transfer of `$amount` from this (source) wallet to `$destinationWalletId` has completed. The
     * TransferHandler calls this only AFTER both legs succeed (the debit here + the credit there), so it marks the
     * transfer as a single business fact — distinct from, and in addition to, the low-level FundsWithdrawn (here)
     * and FundsDeposited (there) the two legs already raised. Because domain events drain at COMMIT from the tracked
     * aggregate (this wallet was tracked by its save()), raising it after that save() still publishes it atomically;
     * and on a rolled-back transfer the handler never reaches this call, so a failed transfer publishes nothing.
     */
    public function recordTransferTo(string $destinationWalletId, Money $amount): void
    {
        $this->assertCurrency($amount);
        $this->raiseEvent(new TransferCompleted(
            $this->walletId(), $destinationWalletId, $amount->minorUnits, $amount->currency->value
        ));
    }

    private function assertCurrency(Money $amount): void
    {
        if ($amount->currency !== $this->currency()) {
            throw new ConflictException('currency mismatch');
        }
    }
}
