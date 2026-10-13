<?php

declare(strict_types=1);

namespace App\Cards;

use Firefly\Container\Attributes\Component;
use RuntimeException;

/**
 * A stand-in for the three systems a greeting-card order touches — the card stock, the payment provider and the
 * mailer. A real application has one adapter per system; one in-memory class keeps this step about the saga.
 * $journal records every call, in order.
 */
#[Component]
final class CardDesk
{
    /** @var list<string> */
    public array $journal = [];

    private int $cards = 0;

    private int $payments = 0;

    public function reserve(): string
    {
        $card = 'card-'.++$this->cards;
        $this->journal[] = "reserve {$card}";

        return $card;
    }

    public function release(string $card): void
    {
        $this->journal[] = "release {$card}";
    }

    public function charge(int $amountMinor): string
    {
        $payment = 'payment-'.++$this->payments;
        $this->journal[] = "charge {$payment} {$amountMinor}";

        return $payment;
    }

    public function refund(string $payment): void
    {
        $this->journal[] = "refund {$payment}";
    }

    public function send(string $card, string $to): string
    {
        if ($to === 'Nobody') {
            throw new RuntimeException("The mailer has no address for {$to}.");
        }

        $this->journal[] = "send {$card} to {$to}";

        return "sent {$card} to {$to}";
    }
}
