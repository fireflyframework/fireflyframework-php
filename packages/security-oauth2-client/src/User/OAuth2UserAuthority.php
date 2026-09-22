<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\User;

use Firefly\Security\Core\GrantedAuthority;
use Firefly\Security\Core\SimpleGrantedAuthority;

/**
 * The authority every OAuth2 login grants (Spring's OAuth2UserAuthority): `OAUTH2_USER`, carrying the user's
 * attributes so a GrantedAuthoritiesMapper can read a `groups` or `roles` member off the list it is handed and
 * add `ROLE_*` — without the mapper needing the principal. Not final: OidcUserAuthority extends it.
 */
readonly class OAuth2UserAuthority implements GrantedAuthority
{
    public const string OAUTH2_USER = 'OAUTH2_USER';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        protected string $authority,
        protected array $attributes,
    ) {}

    public function getAuthority(): string
    {
        return $this->authority;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * `SCOPE_x` for every granted scope — the same spelling the resource-server filter uses, so hasScope()
     * reads both.
     *
     * @param  list<string>  $scopes
     * @return list<SimpleGrantedAuthority>
     */
    public static function scopes(array $scopes): array
    {
        return array_map(static fn (string $scope): SimpleGrantedAuthority => new SimpleGrantedAuthority('SCOPE_'.$scope), $scopes);
    }
}
