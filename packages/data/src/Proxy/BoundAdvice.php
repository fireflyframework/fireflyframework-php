<?php

declare(strict_types=1);

namespace Firefly\Data\Proxy;

/** An advice kind applied to one method, with the PHP literal that rebuilds its descriptor inside the proxy. */
final readonly class BoundAdvice
{
    public function __construct(
        public Advice $advice,
        public string $literal,
    ) {}
}
