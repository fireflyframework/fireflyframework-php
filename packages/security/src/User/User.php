<?php

declare(strict_types=1);

namespace Firefly\Security\User;

use Firefly\Security\Core\GrantedAuthority;

/** The default immutable UserDetails value object. */
final readonly class User implements UserDetails
{
    /**
     * @param  list<GrantedAuthority>  $authorities
     */
    public function __construct(
        private string $username,
        private string $password,
        private array $authorities,
        private bool $enabled = true,
        private bool $accountNonLocked = true,
    ) {}

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * @return list<GrantedAuthority>
     */
    public function getAuthorities(): array
    {
        return $this->authorities;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isAccountNonLocked(): bool
    {
        return $this->accountNonLocked;
    }
}
