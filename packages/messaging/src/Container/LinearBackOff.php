<?php

declare(strict_types=1);

namespace Firefly\Messaging\Container;

/** step × failures — what #[MessageListener(retryDelay: …)] has always meant. */
final readonly class LinearBackOff implements BackOff
{
    public function __construct(public float $step) {}

    public function delayAfter(int $failures): float
    {
        return max(0.0, $this->step * $failures);
    }
}
