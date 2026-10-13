<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Attributes;

use Attribute;

/**
 * The participant's CANCEL: release the reservation; receives the try result. Called for tried participants
 * when the transaction cancels, and for confirmed ones too when a composition or recovery compensates it.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class CancelMethod
{
    public function __construct(
        public int $timeoutMs = -1,
        public int $retry = -1,
        public int $backoffMs = -1,
    ) {}
}
