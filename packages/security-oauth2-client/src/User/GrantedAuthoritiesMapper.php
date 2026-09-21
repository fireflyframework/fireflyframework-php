<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\User;

use Firefly\Security\Core\GrantedAuthority;

/**
 * The seam an application binds to turn a provider's claims into its own authorities (Spring's
 * GrantedAuthoritiesMapper): it receives the list the user service granted — the OidcUserAuthority or
 * OAuth2UserAuthority carrying the claims, then `SCOPE_x` — and returns the list the Authentication carries.
 * Bind an implementation as a #[Bean]; there is no default, and without one the granted list is used as is.
 */
interface GrantedAuthoritiesMapper
{
    /**
     * @param  list<GrantedAuthority>  $authorities
     * @return list<GrantedAuthority>
     */
    public function mapAuthorities(array $authorities): array;
}
