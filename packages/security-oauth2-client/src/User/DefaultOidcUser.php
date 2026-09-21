<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\User;

use Firefly\Security\Core\CredentialsContainer;
use Firefly\Security\Core\GrantedAuthority;
use Firefly\Security\OAuth2\Client\Oidc\OidcIdToken;
use Firefly\Security\OAuth2\Client\Oidc\OidcUserInfo;
use InvalidArgumentException;

/**
 * The OpenID Connect principal (Spring's DefaultOidcUser). Its claims are the id token's with the userinfo's
 * written over them (Spring's precedence), its name is `user_name_attribute` read from that merge, and it is
 * immutable and serialisable — the shape the session repository stores.
 *
 * IT IS A CredentialsContainer, as the shipped User is: the copy the session stores has the id token's RAW
 * VALUE blanked — claims kept, so every getter answers the same on the next request — because a raw id token
 * is a credential and a session file, a sessions table or a page that dumps the session must never hold one.
 * The raw value is available on the request that signed in (getIdToken()->getTokenValue()) and, encrypted,
 * on the authorized client the RP-initiated logout reads.
 */
final readonly class DefaultOidcUser implements CredentialsContainer, OidcUser
{
    /** @var array<string, mixed> */
    private array $claims;

    /**
     * @param  list<GrantedAuthority>  $authorities
     */
    public function __construct(
        private array $authorities,
        private OidcIdToken $idToken,
        private ?OidcUserInfo $userInfo = null,
        private string $nameAttributeKey = 'sub',
    ) {
        $claims = [...$idToken->getClaims(), ...($userInfo?->getClaims() ?? [])];
        if (! is_scalar($claims[$nameAttributeKey] ?? null)) {
            throw new InvalidArgumentException("The claims carry no [{$nameAttributeKey}] to name the principal by.");
        }
        $this->claims = $claims;
    }

    public function getName(): string
    {
        $name = $this->claims[$this->nameAttributeKey] ?? null;

        return is_scalar($name) ? (string) $name : '';
    }

    public function getAttributes(): array
    {
        return $this->claims;
    }

    public function getAttribute(string $name): mixed
    {
        return $this->claims[$name] ?? null;
    }

    public function getAuthorities(): array
    {
        return $this->authorities;
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

    public function getIdToken(): OidcIdToken
    {
        return $this->idToken;
    }

    public function getUserInfo(): ?OidcUserInfo
    {
        return $this->userInfo;
    }

    public function getSubject(): string
    {
        $sub = $this->claims['sub'] ?? null;

        return is_scalar($sub) ? (string) $sub : $this->idToken->getSubject();
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

    /** The same principal with the id token's raw value gone — what the session stores. */
    public function eraseCredentials(): static
    {
        return new self($this->authorities, $this->idToken->withoutTokenValue(), $this->userInfo, $this->nameAttributeKey);
    }

    private function string(string $name): ?string
    {
        $value = $this->claims[$name] ?? null;

        return is_string($value) ? $value : null;
    }
}
