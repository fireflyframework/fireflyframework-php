<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Attributes;

use Attribute;

/**
 * Declares a TCC transaction (jafly @Tcc). Its participants are separate classes naming it in
 * #[TccParticipant(tcc: …)] (spec decision D4 — PHP has no nested classes); this class carries the
 * #[OnTccComplete]/#[OnTccError] callbacks and any #[ScheduledTcc]. `timeoutMs`, `maxRetries` and
 * `backoffMs` apply to every phase that sets none of its own; `retryEnabled: false` turns phase retries off.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Tcc
{
    public function __construct(
        public string $name,
        public int $timeoutMs = -1,
        public bool $retryEnabled = true,
        public int $maxRetries = 3,
        public int $backoffMs = 1000,
        public string $triggerEventType = '',
    ) {}
}
