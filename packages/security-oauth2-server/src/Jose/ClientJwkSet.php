<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Jose;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Throwable;

/**
 * The JWK set a `private_key_jwt` client registers under `client_settings.jwk_set` (RFC 7523: the public keys its
 * assertions are verified with), held to the rules php-jwt would otherwise only reveal on the first token request
 * — as an identical INFO line and a permanent `invalid_client` — at the place the client is read: a config block
 * at boot, an Eloquent row where it is mapped. Every refusal names the client and the key.
 *
 * `kty` is RSA or EC: an `oct` key would let an HMAC-signed assertion verify against a secret the set holds in
 * the clear, which is client_secret_jwt, a method this server does not offer. `alg` is one of ALGORITHMS when
 * given and DERIVED from the key type when not — RFC 7517 §4.4 makes it OPTIONAL and several client libraries
 * publish keys without it, while php-jwt refuses such a key outright — so a key without one verifies RS256 (RSA),
 * ES256 (P-256) or ES384 (P-384) assertions, and a client that signs with another algorithm names it in the key.
 * A set that holds more than one key carries a `kid` on each, and no two share one: php-jwt keeps the LAST key
 * under a kid, so the first could never be chosen. And the material itself must load, so a truncated `n` or a
 * curve php-jwt does not know is refused here rather than on a live request.
 */
final class ClientJwkSet
{
    /**
     * The algorithms an assertion may be signed with: what php-jwt verifies with an RSA or EC public key and no
     * optional extension (PS256 needs phpseclib, EdDSA libsodium). Published by the metadata endpoint as
     * `token_endpoint_auth_signing_alg_values_supported`, so a client can read what to sign with.
     *
     * @var list<string>
     */
    public const array ALGORITHMS = ['RS256', 'RS384', 'RS512', 'ES256', 'ES384'];

    /**
     * @param  array<string,mixed>  $jwkSet  the decoded JWKS document
     *
     * @throws ConfigurationException naming the client and the key that could never verify an assertion
     */
    public static function assertValid(array $jwkSet, string $client): void
    {
        $keys = $jwkSet['keys'] ?? null;
        if (! is_array($keys) || $keys === []) {
            throw new ConfigurationException("Client [{$client}]: client_settings.jwk_set must hold at least one key ({keys: [...]}) for private_key_jwt.");
        }

        /** @var array<string,int> $kids kid => the position that took it */
        $kids = [];
        foreach (array_values($keys) as $index => $jwk) {
            $where = "Client [{$client}]: client_settings.jwk_set.keys[{$index}]";
            if (! is_array($jwk)) {
                throw new ConfigurationException("{$where} must be a JWK (a map with kty, kid, alg and the key members).");
            }
            /** @var array<string,mixed> $jwk */
            $kty = $jwk['kty'] ?? null;
            if ($kty !== 'RSA' && $kty !== 'EC') {
                throw new ConfigurationException("{$where}: kty must be RSA or EC — a private_key_jwt client signs with an asymmetric key, never a shared secret.");
            }

            $alg = $jwk['alg'] ?? self::defaultAlgorithm($jwk);
            if (! is_string($alg) || ! in_array($alg, self::ALGORITHMS, true)) {
                throw new ConfigurationException(sprintf(
                    '%s: alg must be one of %s%s.',
                    $where,
                    implode(', ', self::ALGORITHMS),
                    isset($jwk['alg']) ? '' : ' (none is named, and none follows from kty '.$kty.' on the curve `'.self::string($jwk['crv'] ?? null).'`)',
                ));
            }

            $kid = $jwk['kid'] ?? null;
            if ($kid !== null && (! is_string($kid) || $kid === '')) {
                throw new ConfigurationException("{$where}: kid must be a non-empty string.");
            }
            if ($kid === null && count($keys) > 1) {
                throw new ConfigurationException("{$where}: kid is required when the set holds more than one key; the assertion's header names the key by it.");
            }
            if ($kid !== null && isset($kids[$kid])) {
                throw new ConfigurationException("{$where}: kid [{$kid}] is already used by keys[{$kids[$kid]}]; every key needs its own, or the first could never be chosen.");
            }
            if ($kid !== null) {
                $kids[$kid] = $index;
            }

            try {
                JWK::parseKey($jwk, $alg);
            } catch (Throwable $e) {
                // php-jwt's sentences name the missing member or the OpenSSL failure, never the material.
                throw new ConfigurationException("{$where} could not be loaded: {$e->getMessage()}", previous: $e);
            }
        }
    }

    /**
     * The php-jwt keys of a set assertValid() accepted, under their kid (the position, for the single key that
     * has none), each missing `alg` derived exactly as it was validated.
     *
     * @param  array<string,mixed>  $jwkSet
     * @return array<string,Key>
     */
    public static function parse(array $jwkSet): array
    {
        $keys = [];
        $entries = is_array($jwkSet['keys'] ?? null) ? array_values($jwkSet['keys']) : [];
        foreach ($entries as $index => $jwk) {
            if (! is_array($jwk)) {
                continue;
            }
            /** @var array<string,mixed> $jwk */
            $key = JWK::parseKey($jwk, self::defaultAlgorithm($jwk));
            if ($key !== null) {
                $keys[self::string($jwk['kid'] ?? $index)] = $key;
            }
        }

        return $keys;
    }

    /**
     * The algorithm a key without `alg` verifies: the one the server itself would sign with for that key type.
     *
     * @param  array<string,mixed>  $jwk
     */
    public static function defaultAlgorithm(array $jwk): ?string
    {
        return match ($jwk['kty'] ?? null) {
            'RSA' => 'RS256',
            'EC' => match ($jwk['crv'] ?? null) {
                'P-256' => 'ES256',
                'P-384' => 'ES384',
                default => null,
            },
            default => null,
        };
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
