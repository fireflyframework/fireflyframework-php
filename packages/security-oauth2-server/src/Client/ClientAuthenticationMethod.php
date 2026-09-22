<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Client;

/** How a client proves who it is at the token, introspection and revocation endpoints (RFC 6749 §2.3, RFC 7523). */
enum ClientAuthenticationMethod: string
{
    case ClientSecretBasic = 'client_secret_basic';

    case ClientSecretPost = 'client_secret_post';

    case PrivateKeyJwt = 'private_key_jwt';

    case None = 'none';

    public function isConfidential(): bool
    {
        return $this !== self::None;
    }

    /**
     * @return list<self>
     */
    public static function confidential(): array
    {
        return [self::ClientSecretBasic, self::ClientSecretPost, self::PrivateKeyJwt];
    }
}
