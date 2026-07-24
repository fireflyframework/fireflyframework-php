<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2;

use Firebase\JWT\Key;

/** Supplies the issuer's verification keys, keyed by `kid` (the port the resource-server filter validates against). */
interface JwksProvider
{
    /** @return array<string,Key> */
    public function keys(): array;
}
