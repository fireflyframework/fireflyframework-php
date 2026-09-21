<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Oidc;

/** The userinfo endpoint's claims (Spring's OidcUserInfo), with the standard accessors (OIDC Core §5.1). */
final readonly class OidcUserInfo implements ClaimAccessor
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function __construct(public array $claims) {}

    public function getClaims(): array
    {
        return $this->claims;
    }

    public function getClaim(string $name): mixed
    {
        return $this->claims[$name] ?? null;
    }

    public function hasClaim(string $name): bool
    {
        return array_key_exists($name, $this->claims);
    }

    public function getSubject(): string
    {
        $sub = $this->claims['sub'] ?? null;

        return is_scalar($sub) ? (string) $sub : '';
    }

    public function getEmail(): ?string
    {
        return $this->string('email');
    }

    public function getFullName(): ?string
    {
        return $this->string('name');
    }

    public function getPreferredUsername(): ?string
    {
        return $this->string('preferred_username');
    }

    private function string(string $name): ?string
    {
        $value = $this->claims[$name] ?? null;

        return is_string($value) ? $value : null;
    }
}
