<?php

declare(strict_types=1);

namespace Lumen\Application\Payout;

/**
 * The bank a payout leaves through — a PORT, because the bank is another system with its own transactions: nothing
 * Lumen commits can roll it back, so every call that changes something at the bank has an explicit undo.
 *
 * submit() starts a transfer and answers the bank's reference; confirm() asks the bank to execute it; cancel() voids
 * one that was submitted. Each throws PayoutRejected when the bank refuses.
 */
interface BankGateway
{
    public function submit(string $iban, int $amountMinor): string;

    public function confirm(string $reference): void;

    public function cancel(string $reference): void;
}
