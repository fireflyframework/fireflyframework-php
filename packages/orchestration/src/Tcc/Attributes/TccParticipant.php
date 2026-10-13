<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Attributes;

use Attribute;

/**
 * A participant of the TCC named `tcc`, tried and confirmed in ascending `order` (declaration order breaks
 * ties) and cancelled in reverse. The class declares exactly one #[TryMethod], #[ConfirmMethod] and
 * #[CancelMethod]. An `optional` participant may fail its try without cancelling the transaction.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TccParticipant
{
    public function __construct(
        public string $tcc,
        public string $id,
        public int $order = 0,
        public int $timeoutMs = -1,
        public bool $optional = false,
        public bool $jitter = false,
        public float $jitterFactor = 0.0,
    ) {}
}
