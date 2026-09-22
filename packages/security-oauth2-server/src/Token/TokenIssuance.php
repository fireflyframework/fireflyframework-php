<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Token;

use Firefly\Security\OAuth2\Server\Authorization\OAuth2Authorization;

/** What a grant produces: the authorization to persist (tokens with their plain values, still) and the response body. */
final readonly class TokenIssuance
{
    public function __construct(
        public OAuth2Authorization $authorization,
        public OAuth2AccessTokenResponse $response,
    ) {}
}
