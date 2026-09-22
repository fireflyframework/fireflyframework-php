<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Authorization;

use DateTimeImmutable;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\RegisteredClient;

/**
 * One grant a resource owner (or a client, for client credentials) gave a client (Spring's OAuth2Authorization):
 * who, which client, through which grant, for which scopes, the attributes of the request that produced it
 * (`redirect_uri`, `scope`, `state`, `code_challenge`, `code_challenge_method`, `nonce`, `auth_time`, `sid`) and
 * up to one token of each type. Immutable: every change is a with-er returning a copy, and the services persist
 * whatever copy they are handed. `refresh_token_family` is the list of superseded refresh-token hashes (bounded,
 * FAMILY_LIMIT): a refresh with a token from the family and not the current one is a replay, and the whole
 * authorization is revoked.
 *
 * The id is 16 random bytes in hex — 32 characters, unguessable, and never derived from anything the client
 * sent, so an id in a log line names a record and nothing else. `registeredClientId` is the client's `id` (the
 * config map key or the row id), not its `client_id`: the former is the stable identity of the registration,
 * the latter is what goes over the wire and may be re-issued.
 */
final readonly class OAuth2Authorization
{
    public const int FAMILY_LIMIT = 20;

    /**
     * @param  list<string>  $authorizedScopes
     * @param  array<string,mixed>  $attributes
     * @param  array<string,OAuth2Token>  $tokens  keyed by OAuth2TokenType value
     */
    public function __construct(
        public string $id,
        public string $registeredClientId,
        public string $principalName,
        public AuthorizationGrantType $authorizationGrantType,
        public array $authorizedScopes,
        public array $attributes = [],
        public array $tokens = [],
    ) {}

    /**
     * @param  list<string>  $scopes
     * @param  array<string,mixed>  $attributes
     */
    public static function create(RegisteredClient $client, string $principalName, AuthorizationGrantType $grant, array $scopes, array $attributes = []): self
    {
        return new self(bin2hex(random_bytes(16)), $client->id, $principalName, $grant, $scopes, $attributes);
    }

    public function token(OAuth2TokenType $type): ?OAuth2Token
    {
        return $this->tokens[$type->value] ?? null;
    }

    public function withToken(OAuth2Token $token): self
    {
        return new self($this->id, $this->registeredClientId, $this->principalName, $this->authorizationGrantType, $this->authorizedScopes, $this->attributes, [$token->type->value => $token] + $this->tokens);
    }

    public function withInvalidatedToken(OAuth2TokenType $type): self
    {
        $token = $this->token($type);

        return $token === null ? $this : $this->withToken($token->invalidated());
    }

    public function withEveryTokenInvalidated(): self
    {
        $authorization = $this;
        foreach ($this->tokens as $token) {
            $authorization = $authorization->withToken($token->invalidated());
        }

        return $authorization;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        return new self($this->id, $this->registeredClientId, $this->principalName, $this->authorizationGrantType, $this->authorizedScopes, [$name => $value] + $this->attributes, $this->tokens);
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withoutTokenValues(): self
    {
        $tokens = [];
        foreach ($this->tokens as $key => $token) {
            $tokens[$key] = $token->withoutValue();
        }

        return new self($this->id, $this->registeredClientId, $this->principalName, $this->authorizationGrantType, $this->authorizedScopes, $this->attributes, $tokens);
    }

    /** The latest expiry among the tokens — when the whole record may be purged. Null when nothing expires. */
    public function expiresAt(): ?DateTimeImmutable
    {
        $latest = null;
        foreach ($this->tokens as $token) {
            if ($token->expiresAt !== null && ($latest === null || $token->expiresAt > $latest)) {
                $latest = $token->expiresAt;
            }
        }

        return $latest;
    }

    /** Active while at least one token is neither invalidated nor expired; a record with no tokens is not. */
    public function isActive(DateTimeImmutable $now): bool
    {
        foreach ($this->tokens as $token) {
            if ($token->isActive($now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function refreshTokenFamily(): array
    {
        $family = $this->attributes['refresh_token_family'] ?? [];

        /** @var list<string> */
        return is_array($family) ? array_values(array_filter($family, 'is_string')) : [];
    }

    /** Appends the hash a rotation just superseded, keeping only the newest FAMILY_LIMIT entries. */
    public function withSupersededRefreshToken(string $hash): self
    {
        $family = [...$this->refreshTokenFamily(), $hash];

        return $this->withAttribute('refresh_token_family', array_slice($family, -self::FAMILY_LIMIT));
    }
}
