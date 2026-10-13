<?php

declare(strict_types=1);

namespace Firefly\Messaging\Container;

use Throwable;

/** What a listener container's attempts at one delivery came to: handled, or exhausted with the last failure. */
final readonly class DeliveryOutcome
{
    public function __construct(
        public bool $handled,
        public int $attempts,
        public ?Throwable $failure = null,
    ) {}
}
