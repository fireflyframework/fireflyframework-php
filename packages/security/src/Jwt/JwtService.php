<?php

declare(strict_types=1);

namespace Firefly\Security\Jwt;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Firefly\Kernel\Exception\Security\InvalidTokenException;
use Firefly\Kernel\Exception\Security\TokenExpiredException;
use Throwable;

/**
 * HMAC JWT encode/decode over firebase/php-jwt. Two fail-closed invariants: (1) the signing secret is vetted at
 * CONSTRUCTION — a placeholder or a secret shorter than 32 bytes throws WeakSigningSecretException, so a
 * misconfigured app refuses to boot rather than sign with a guessable key (parity with pyfly's INSECURE guard);
 * (2) decode() DEMANDS an `exp` claim and rejects any token without one, so a forgotten-expiry token can never
 * be accepted as eternally valid. firebase's own ExpiredException maps to the kernel TokenExpiredException (401),
 * every other verification failure to InvalidTokenException (401).
 */
final class JwtService
{
    private const MIN_SECRET_BYTES = 32;

    private const PLACEHOLDERS = ['changeme', 'change-me', 'secret', 'password', 'insecure', 'your-secret-key', 'null', ''];

    public function __construct(
        private readonly string $secret,
        private readonly string $algorithm = 'HS256',
        private readonly int $leewaySeconds = 0,
    ) {
        if (in_array(strtolower($secret), self::PLACEHOLDERS, true) || strlen($secret) < self::MIN_SECRET_BYTES) {
            throw new WeakSigningSecretException(
                'Refusing to boot: the JWT signing secret is a placeholder or shorter than '.self::MIN_SECRET_BYTES.' bytes. Set firefly.security.jwt.secret to a strong random value.'
            );
        }
    }

    /**
     * @param  array<string,mixed>  $claims
     */
    public function encode(array $claims, int $ttlSeconds): string
    {
        $now = time();
        $claims['iat'] = $now;
        $claims['exp'] = $now + $ttlSeconds;

        return JWT::encode($claims, $this->secret, $this->algorithm);
    }

    /**
     * @return array<string,mixed>
     */
    public function decode(string $token): array
    {
        JWT::$leeway = $this->leewaySeconds;

        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));
        } catch (ExpiredException $e) {
            throw new TokenExpiredException('JWT has expired.', 'TOKEN_EXPIRED', $e);
        } catch (SignatureInvalidException $e) {
            throw new InvalidTokenException('JWT signature is invalid.', 'INVALID_TOKEN', $e);
        } catch (Throwable $e) {
            throw new InvalidTokenException('JWT could not be decoded.', 'INVALID_TOKEN', $e);
        }

        /** @var array<string,mixed> $claims */
        $claims = (array) $decoded;
        if (! array_key_exists('exp', $claims)) {
            throw new InvalidTokenException('JWT is missing the mandatory exp claim.');
        }

        return $claims;
    }
}
