<?php

declare(strict_types=1);

namespace Firefly\Security\Access;

/** A compiled URL rule: a glob path pattern → a security expression the HttpSecurityFilter evaluates. */
final readonly class UrlAuthorizationRule
{
    public function __construct(
        public string $pattern,
        public string $expression,
    ) {}
}
