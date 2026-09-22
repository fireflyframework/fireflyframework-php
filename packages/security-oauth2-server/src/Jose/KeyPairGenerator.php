<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Jose;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * Generates the private key the server signs with — RSA (2048 bits by default) for RS256, P-256 for ES256 — as an
 * unencrypted PEM: the shape `signing_key` accepts inline or from a file. Used by `firefly:oauth2:keys` and by
 * every test that needs a key.
 */
final class KeyPairGenerator
{
    public static function generate(string $algorithm = 'RS256', int $bits = 2048): string
    {
        $options = match ($algorithm) {
            'RS256' => ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => max(2048, $bits)],
            'ES256' => ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'],
            default => throw new ConfigurationException("Cannot generate a key for `{$algorithm}`: the authorization server signs with RS256 or ES256."),
        };

        $key = openssl_pkey_new($options);
        if ($key === false || ! openssl_pkey_export($key, $pem) || ! is_string($pem)) {
            throw new ConfigurationException('openssl could not generate a signing key: '.(openssl_error_string() ?: 'unknown error'));
        }

        return $pem;
    }
}
