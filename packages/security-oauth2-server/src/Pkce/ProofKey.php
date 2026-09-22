<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Pkce;

/**
 * PKCE (RFC 7636), S256 only: a verifier of 32 random bytes base64url-encoded (43 characters), its challenge the
 * base64url SHA-256, and a verification that compares in constant time. `plain` is not offered — OAuth 2.1
 * dropped it, and a challenge equal to its verifier protects nothing an attacker who saw the request lacks.
 */
final readonly class ProofKey
{
    public function __construct(public string $verifier) {}

    public static function generate(): self
    {
        return new self(self::base64url(random_bytes(32)));
    }

    public function challenge(): string
    {
        return self::base64url(hash('sha256', $this->verifier, true));
    }

    public static function verify(string $verifier, string $challenge): bool
    {
        if (! self::isWellFormed($verifier) || ! self::isWellFormed($challenge)) {
            return false;
        }

        return hash_equals($challenge, (new self($verifier))->challenge());
    }

    /** RFC 7636 §4.1: 43 to 128 unreserved characters. */
    public static function isWellFormed(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $value) === 1;
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
