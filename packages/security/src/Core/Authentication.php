<?php

declare(strict_types=1);

namespace Firefly\Security\Core;

/**
 * An immutable authentication token (Spring's Authentication). Built via named factories so the two states —
 * an unauthenticated request token carrying raw credentials, and an authenticated principal carrying granted
 * authorities — are impossible to confuse. `name` is the stable principal id (username / JWT `sub`) the
 * AuditorAware and expression root read; `principal` is the full principal (a UserDetails or an id).
 * Credentials are erased (returned as a fresh instance) the moment authentication succeeds — this class never
 * mutates, so a leaked reference can never observe cleared-then-repopulated credentials.
 */
final class Authentication
{
    /**
     * @param  list<GrantedAuthority>  $authorities
     * @param  array<string,mixed>  $attributes
     */
    private function __construct(
        public readonly string $name,
        public readonly mixed $principal,
        public readonly mixed $credentials,
        public readonly array $authorities,
        public readonly bool $authenticated,
        public readonly array $attributes,
    ) {}

    /**
     * @param  list<GrantedAuthority>  $authorities
     * @param  array<string,mixed>  $attributes
     */
    public static function authenticated(string $name, mixed $principal, array $authorities, array $attributes = []): self
    {
        return new self($name, $principal, null, $authorities, true, $attributes);
    }

    public static function unauthenticated(string $name, mixed $principal, mixed $credentials): self
    {
        return new self($name, $principal, $credentials, [], false, []);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPrincipal(): mixed
    {
        return $this->principal;
    }

    public function getCredentials(): mixed
    {
        return $this->credentials;
    }

    /**
     * @return list<GrantedAuthority>
     */
    public function getAuthorities(): array
    {
        return $this->authorities;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    /**
     * @return array<string,mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function eraseCredentials(): self
    {
        return new self($this->name, $this->principal, null, $this->authorities, $this->authenticated, $this->attributes);
    }

    /**
     * @return list<string>
     */
    public function authorityStrings(): array
    {
        return array_map(static fn (GrantedAuthority $a): string => $a->getAuthority(), $this->authorities);
    }
}
