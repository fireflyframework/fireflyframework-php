<?php

declare(strict_types=1);

namespace Firefly\Security\Core;

/** An immutable authority string (a role such as `ROLE_ADMIN`, or a bare permission such as `orders:read`). */
interface GrantedAuthority
{
    public function getAuthority(): string;
}
