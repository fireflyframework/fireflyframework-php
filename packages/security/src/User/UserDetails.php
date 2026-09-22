<?php

declare(strict_types=1);

namespace Firefly\Security\User;

use Firefly\Security\Core\GrantedAuthority;

/**
 * The user record an AuthenticationProvider verifies against (Spring's UserDetails). Password is the ENCODED
 * form. An implementation that carries that hash should also implement CredentialsContainer, as the shipped
 * User does, so the session repository can store the principal without it.
 */
interface UserDetails
{
    public function getUsername(): string;

    public function getPassword(): string;

    /** @return list<GrantedAuthority> */
    public function getAuthorities(): array;

    public function isEnabled(): bool;

    public function isAccountNonLocked(): bool;
}
