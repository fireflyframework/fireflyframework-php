<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Jose;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * The public half of an OpenSSL key as a JWK (RFC 7517), built from openssl_pkey_get_details(): RSA as `n`/`e`,
 * EC P-256 as `crv`/`x`/`y` (each coordinate left-padded to 32 bytes — OpenSSL drops leading zeros). Nothing
 * private ever enters the array, so the document can be published as it is. The thumbprint (RFC 7638) hashes the
 * REQUIRED members in lexicographic order and nothing else, which is why two nodes holding the same key derive
 * the same default `kid`.
 */
final class Jwk
{
    /**
     * @param  array<mixed>  $details  what openssl_pkey_get_details() returned (typed as a bare array by PHP's stubs)
     * @return array<string,string>
     */
    public static function fromDetails(array $details, string $kid, string $algorithm): array
    {
        $type = $details['type'] ?? null;

        if ($type === OPENSSL_KEYTYPE_RSA && is_array($details['rsa'] ?? null)) {
            /** @var array{n: string, e: string} $rsa */
            $rsa = $details['rsa'];

            return ['kty' => 'RSA', 'kid' => $kid, 'alg' => $algorithm, 'use' => 'sig', 'n' => self::base64url($rsa['n']), 'e' => self::base64url($rsa['e'])];
        }

        if ($type === OPENSSL_KEYTYPE_EC && is_array($details['ec'] ?? null)) {
            /** @var array{curve_name?: string, x: string, y: string} $ec */
            $ec = $details['ec'];
            if (($ec['curve_name'] ?? '') !== 'prime256v1') {
                throw new ConfigurationException('Only the P-256 curve (prime256v1) can be published as an EC JWK for ES256; got `'.($ec['curve_name'] ?? 'unknown').'`.');
            }

            return [
                'kty' => 'EC', 'kid' => $kid, 'alg' => $algorithm, 'use' => 'sig', 'crv' => 'P-256',
                'x' => self::base64url(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)),
                'y' => self::base64url(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)),
            ];
        }

        throw new ConfigurationException('Only RSA and EC P-256 keys can be published as a JWK.');
    }

    /**
     * @param  array<string,string>  $jwk
     */
    public static function thumbprint(array $jwk): string
    {
        $members = ($jwk['kty'] ?? '') === 'RSA'
            ? ['e' => $jwk['e'], 'kty' => 'RSA', 'n' => $jwk['n']]
            : ['crv' => $jwk['crv'], 'kty' => 'EC', 'x' => $jwk['x'], 'y' => $jwk['y']];

        return self::base64url(hash('sha256', json_encode($members, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true));
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
