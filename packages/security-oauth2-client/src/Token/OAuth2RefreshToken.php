<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Token;

/** A refresh token (Spring's OAuth2RefreshToken). Opaque to the client; only ever sent back to the token endpoint. */
final readonly class OAuth2RefreshToken
{
    public function __construct(
        public string $tokenValue,
        public int $issuedAt,
    ) {}

    public function getTokenValue(): string
    {
        return $this->tokenValue;
    }
}
