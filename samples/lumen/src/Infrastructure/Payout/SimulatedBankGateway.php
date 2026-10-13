<?php

declare(strict_types=1);

namespace Lumen\Infrastructure\Payout;

use Firefly\Container\Attributes\Component;
use Lumen\Application\Payout\BankGateway;
use Lumen\Application\Payout\PayoutRejected;

/**
 * The sample's stand-in bank: an ADAPTER behind BankGateway with the two refusals a real bank answers with, so the
 * payout saga has something to compensate. It refuses any IBAN starting with "XX" at submit(), and refuses to
 * confirm a transfer above DAILY_LIMIT_MINOR (1,000.00). $calls is what the bank was asked to do, in order.
 */
#[Component]
final class SimulatedBankGateway implements BankGateway
{
    public const int DAILY_LIMIT_MINOR = 100000;

    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, int> reference => amount in minor units */
    private array $transfers = [];

    private int $issued = 0;

    public function submit(string $iban, int $amountMinor): string
    {
        if (str_starts_with($iban, 'XX')) {
            $this->calls[] = "refused {$iban}";

            throw new PayoutRejected("The bank refused the account {$iban}.");
        }

        $reference = 'bank-'.++$this->issued;
        $this->transfers[$reference] = $amountMinor;
        $this->calls[] = "submit {$reference}";

        return $reference;
    }

    public function confirm(string $reference): void
    {
        if (($this->transfers[$reference] ?? 0) > self::DAILY_LIMIT_MINOR) {
            $this->calls[] = "limit {$reference}";

            throw new PayoutRejected("Transfer {$reference} is over the bank's daily limit.");
        }

        $this->calls[] = "confirm {$reference}";
    }

    public function cancel(string $reference): void
    {
        unset($this->transfers[$reference]);
        $this->calls[] = "cancel {$reference}";
    }
}
