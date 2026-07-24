<?php

declare(strict_types=1);

namespace Firefly\Security\Core;

/** An immutable snapshot of the current Authentication. `anonymous()` is the unauthenticated zero-value. */
final readonly class SecurityContext
{
    public function __construct(public ?Authentication $authentication) {}

    public static function anonymous(): self
    {
        return new self(null);
    }

    public function getAuthentication(): ?Authentication
    {
        return $this->authentication;
    }

    public function isAuthenticated(): bool
    {
        return $this->authentication?->isAuthenticated() ?? false;
    }
}
