<?php

declare(strict_types=1);

namespace Firefly\Eda\Database\Outbox\Claim;

use DateTimeImmutable;
use Firefly\Eda\Database\Outbox\LeaseClock;

/** One claim request: who asks, for how many rows, at what instant, and until when the lease runs. */
final readonly class OutboxClaim
{
    public function __construct(
        public string $claimant,
        public int $limit,
        public DateTimeImmutable $now,
        public DateTimeImmutable $until,
    ) {}

    public static function at(string $claimant, int $limit, DateTimeImmutable $now, float $leaseSeconds): self
    {
        return new self($claimant, max(1, $limit), $now, LeaseClock::plus($now, $leaseSeconds));
    }

    public function nowString(): string
    {
        return LeaseClock::format($this->now);
    }

    public function untilString(): string
    {
        return LeaseClock::format($this->until);
    }
}
