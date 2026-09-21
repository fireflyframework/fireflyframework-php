<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Oidc;

/**
 * A verified id token (Spring's OidcIdToken): the raw value and the claims the decoder read out of it. The
 * standard claims have typed accessors (OIDC Core §2); `aud` is answered as a list whether the provider wrote
 * a string or an array. withoutTokenValue() is what the session stores and what an authority carries: the
 * claims, and an empty value — a raw id token is a credential, and the only places it lives are the request
 * that received it and the encrypted authorized-client entry.
 */
final readonly class OidcIdToken implements ClaimAccessor
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function __construct(
        public string $tokenValue,
        public array $claims,
    ) {}

    public function getTokenValue(): string
    {
        return $this->tokenValue;
    }

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

    public function getIssuer(): ?string
    {
        $iss = $this->claims['iss'] ?? null;

        return is_string($iss) ? $iss : null;
    }

    /**
     * @return list<string>
     */
    public function getAudience(): array
    {
        $aud = $this->claims['aud'] ?? null;
        if (is_string($aud)) {
            return [$aud];
        }
        if (! is_array($aud)) {
            return [];
        }

        return array_values(array_filter($aud, static fn (mixed $a): bool => is_string($a)));
    }

    public function getIssuedAt(): ?int
    {
        return self::timestamp($this->claims['iat'] ?? null);
    }

    public function getExpiresAt(): ?int
    {
        return self::timestamp($this->claims['exp'] ?? null);
    }

    public function getNonce(): ?string
    {
        $nonce = $this->claims['nonce'] ?? null;

        return is_string($nonce) ? $nonce : null;
    }

    public function getAuthorizedParty(): ?string
    {
        $azp = $this->claims['azp'] ?? null;

        return is_string($azp) ? $azp : null;
    }

    public function withoutTokenValue(): self
    {
        return new self('', $this->claims);
    }

    private static function timestamp(mixed $value): ?int
    {
        return match (true) {
            is_int($value) => $value,
            is_float($value) => (int) $value,
            is_string($value) && ctype_digit($value) => (int) $value,
            default => null,
        };
    }
}
