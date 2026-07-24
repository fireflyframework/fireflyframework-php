<?php

declare(strict_types=1);

namespace Firefly\Security\Core;

/** The default value-object authority (Spring's SimpleGrantedAuthority). */
final readonly class SimpleGrantedAuthority implements GrantedAuthority
{
    public function __construct(private string $authority) {}

    public function getAuthority(): string
    {
        return $this->authority;
    }
}
