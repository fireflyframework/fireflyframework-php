<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Attributes;

use Attribute;

/** Called after the TCC CANCELED or FAILED; `suppressError` reports it as a success (the cancel still ran). */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class OnTccError
{
    /**
     * @param  list<string>  $errorTypes  exception class names; empty matches every error
     */
    public function __construct(
        public array $errorTypes = [],
        public bool $suppressError = false,
        public bool $async = false,
        public int $priority = 0,
    ) {}
}
