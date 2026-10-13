<?php

declare(strict_types=1);

namespace Firefly\Messaging\Container;

/** How long a listener container waits before attempting a delivery again, after its N-th failure (1 = first). */
interface BackOff
{
    public function delayAfter(int $failures): float;
}
