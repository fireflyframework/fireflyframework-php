<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Token;

/**
 * A bearer access token as the provider handed it out (Spring's OAuth2AccessToken): the value, when it was
 * issued and when it expires (null when the provider said nothing — the manager treats such a token as good
 * until the provider refuses it), and the scopes granted (the requested ones when the response omitted
 * `scope`, per RFC 6749 §5.1).
 */
final readonly class OAuth2AccessToken
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $tokenValue,
        public int $issuedAt,
        public ?int $expiresAt,
        public array $scopes = [],
        public string $tokenType = 'Bearer',
    ) {}

    public function getTokenValue(): string
    {
        return $this->tokenValue;
    }

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /** Expired at $now, or about to be within $skewSeconds — the manager's cue to refresh before a call fails. */
    public function isExpired(int $now, int $skewSeconds = 0): bool
    {
        return $this->expiresAt !== null && $this->expiresAt - $skewSeconds <= $now;
    }
}
