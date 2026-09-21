<?php

declare(strict_types=1);

use Firebase\JWT\Key;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Server\Jose\JwtSigningKeys;
use Firefly\Security\OAuth2\Server\Jose\KeyPairGenerator;
use Firefly\Security\OAuth2\Server\Jose\SigningKey;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;

it('loads a private PEM as a key that can sign, derives the thumbprint kid, and publishes only the public half', function () {
    $key = SigningKey::fromPem(KeyPairGenerator::generate('RS256'), 'RS256');

    expect($key->canSign)->toBeTrue()
        ->and($key->algorithm)->toBe('RS256')
        ->and(strlen($key->kid))->toBe(43)
        ->and($key->jwk['kid'])->toBe($key->kid)
        ->and($key->jwk)->not->toHaveKey('d');
});

it('loads a public PEM as a verification-only key and keeps an explicit kid', function () {
    $details = openssl_pkey_get_details(openssl_pkey_get_private(KeyPairGenerator::generate('RS256')) ?: throw new RuntimeException('no key'));
    $publicPem = is_array($details) && is_string($details['key'] ?? null) ? $details['key'] : '';

    $key = SigningKey::fromPem($publicPem, 'RS256', 'kid-2025');

    expect($key->canSign)->toBeFalse()
        ->and($key->kid)->toBe('kid-2025');
});

it('refuses a key whose type does not match the algorithm, and text that is no key at all', function () {
    expect(fn () => SigningKey::fromPem(KeyPairGenerator::generate('RS256'), 'ES256'))->toThrow(ConfigurationException::class, 'ES256')
        ->and(fn () => SigningKey::fromPem(KeyPairGenerator::generate('ES256'), 'RS256'))->toThrow(ConfigurationException::class, 'RS256')
        ->and(fn () => SigningKey::fromPem('not a pem', 'RS256'))->toThrow(ConfigurationException::class, 'PEM');
});

it('builds from the settings: inline PEM or a path, previous keys after the current one in the JWKS, every kid verifiable', function () {
    $current = KeyPairGenerator::generate('RS256');
    $previous = KeyPairGenerator::generate('RS256');
    $path = sys_get_temp_dir().'/firefly-oauth2-previous-'.bin2hex(random_bytes(4)).'.pem';
    file_put_contents($path, $previous);

    try {
        $keys = JwtSigningKeys::fromSettings(new AuthorizationServerSettings(
            signingKey: $current,
            keyId: 'now',
            previousKeys: [['key' => $path, 'key_id' => 'before']],
        ));

        $jwks = $keys->jwks();
        expect($keys->current()->kid)->toBe('now')
            ->and($keys->current()->canSign)->toBeTrue()
            ->and($keys->previous())->toHaveCount(1)
            ->and($jwks['keys'][0]['kid'])->toBe('now')
            ->and($jwks['keys'][1]['kid'])->toBe('before')
            ->and(array_keys($keys->verificationKeys()))->toBe(['now', 'before'])
            ->and($keys->verificationKeys()['before'])->toBeInstanceOf(Key::class);
    } finally {
        @unlink($path);
    }
});

it('refuses an empty signing key with the command to run, a path that does not exist, and a current key that cannot sign', function () {
    $details = openssl_pkey_get_details(openssl_pkey_get_private(KeyPairGenerator::generate('RS256')) ?: throw new RuntimeException('no key'));
    $publicPem = is_array($details) && is_string($details['key'] ?? null) ? $details['key'] : '';

    expect(fn () => JwtSigningKeys::fromSettings(new AuthorizationServerSettings))->toThrow(ConfigurationException::class, 'firefly:oauth2:keys')
        ->and(fn () => JwtSigningKeys::fromSettings(new AuthorizationServerSettings(signingKey: '/nowhere/private.pem')))->toThrow(ConfigurationException::class, '/nowhere/private.pem')
        ->and(fn () => JwtSigningKeys::fromSettings(new AuthorizationServerSettings(signingKey: $publicPem)))->toThrow(ConfigurationException::class, 'private key');
});
