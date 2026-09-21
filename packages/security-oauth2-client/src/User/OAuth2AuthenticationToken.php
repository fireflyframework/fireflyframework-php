<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\User;

use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\GrantedAuthority;

/**
 * How an OAuth2 login is represented on firefly/security's Authentication (Spring's OAuth2AuthenticationToken,
 * as factories over the one final token class): the principal is the OAuth2User, the name is its name, the
 * authorities are the MAPPED ones, and the registration id rides in the token's attributes — the fact the
 * authorized-client manager and the RP-initiated logout read back to know which provider signed the person in.
 */
final class OAuth2AuthenticationToken
{
    public const string REGISTRATION_ID = 'oauth2.registration_id';

    /**
     * @param  list<GrantedAuthority>  $authorities
     */
    public static function of(OAuth2User $principal, array $authorities, string $registrationId): Authentication
    {
        return Authentication::authenticated($principal->getName(), $principal, $authorities, [self::REGISTRATION_ID => $registrationId]);
    }

    public static function registrationId(?Authentication $authentication): ?string
    {
        $id = $authentication?->getAttributes()[self::REGISTRATION_ID] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public static function principal(?Authentication $authentication): ?OAuth2User
    {
        $principal = $authentication?->getPrincipal();

        return $principal instanceof OAuth2User ? $principal : null;
    }
}
