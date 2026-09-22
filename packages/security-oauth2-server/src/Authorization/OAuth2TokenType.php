<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Authorization;

/**
 * The four tokens an authorization can hold, keyed by their wire name so a stored row and a token response spell
 * them the same way. The two `token_type_hint` values RFC 7662 (introspection) and RFC 7009 (revocation) let a
 * client send map onto it through fromHint(); a hint the RFCs do not define — or none — answers null, and the
 * endpoint then searches every column, which is what both RFCs ask for when the hint is absent or wrong.
 */
enum OAuth2TokenType: string
{
    case AuthorizationCode = 'authorization_code';

    case AccessToken = 'access_token';

    case RefreshToken = 'refresh_token';

    case IdToken = 'id_token';

    public static function fromHint(?string $hint): ?self
    {
        return match ($hint) {
            'access_token' => self::AccessToken,
            'refresh_token' => self::RefreshToken,
            default => null,
        };
    }
}
