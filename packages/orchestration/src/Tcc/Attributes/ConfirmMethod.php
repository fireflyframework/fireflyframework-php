<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Attributes;

use Attribute;

/**
 * The participant's CONFIRM: make the reservation permanent; receives the try result as its implicit argument
 * (or through #[FromTry]). Must be idempotent — a resumed TCC confirms again what a crash interrupted.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class ConfirmMethod
{
    public function __construct(
        public int $timeoutMs = -1,
        public int $retry = -1,
        public int $backoffMs = -1,
    ) {}
}
