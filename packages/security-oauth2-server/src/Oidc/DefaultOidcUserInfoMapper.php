<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Oidc;

use Firefly\Security\Authentication\Exception\UsernameNotFoundException;
use Firefly\Security\User\UserDetailsService;

/**
 * `sub` always; with `profile`, `name` and `preferred_username` (the username — the principal model has no
 * display name); with `email`, `email` when the username is an address, which is exactly what the Eloquent
 * users driver's default `email` username column makes it. A user the store no longer knows keeps `sub` and
 * nothing else: the token is still valid, the profile is gone.
 */
final class DefaultOidcUserInfoMapper implements OidcUserInfoMapper
{
    public function __construct(private readonly ?UserDetailsService $users = null) {}

    public function map(OidcUserInfoContext $context): array
    {
        $name = $context->authorization->principalName;
        $claims = ['sub' => $name];

        if ($this->users !== null) {
            try {
                $this->users->loadUserByUsername($name);
            } catch (UsernameNotFoundException) {
                return $claims;
            }
        }

        if (in_array('profile', $context->scopes, true)) {
            $claims['name'] = $name;
            $claims['preferred_username'] = $name;
        }
        if (in_array('email', $context->scopes, true) && filter_var($name, FILTER_VALIDATE_EMAIL) !== false) {
            $claims['email'] = $name;
        }

        return $claims;
    }
}
