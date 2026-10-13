<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Attributes;

use Attribute;

/**
 * The participant's CANCEL: release the reservation; receives the try result. Called for tried participants
 * when the transaction cancels, and for confirmed ones too when a composition or recovery compensates it.
 *
 * A participant whose try timed out, or was interrupted by a crash, may still have reserved, so it is
 * cancelled too — with a null try result (the TCC "empty rollback"). A cancel must therefore accept null and
 * release whatever it holds for this transaction (find it by the participant input), do nothing when it holds
 * nothing, and be idempotent, since a resume can repeat it.
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
