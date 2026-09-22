<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Authorization;

/**
 * The one way a token value is kept and looked up: its hex SHA-256. A code, a refresh token or a reference token
 * has 256 bits of entropy, so the hash is not reversible and needs no salt; a store that leaks yields nothing a
 * client could present. Sixty-four lowercase hex characters, so the column is fixed-width and indexable.
 */
final class TokenHash
{
    public static function of(string $value): string
    {
        return hash('sha256', $value);
    }
}
