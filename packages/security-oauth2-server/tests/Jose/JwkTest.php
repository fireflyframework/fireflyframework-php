<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Server\Jose\Jwk;
use Firefly\Security\OAuth2\Server\Jose\KeyPairGenerator;

/** @return array<mixed> what openssl_pkey_get_details() returned for the private key */
function keyDetails(string $pem): array
{
    $key = openssl_pkey_get_private($pem);
    expect($key)->not->toBeFalse();
    $details = openssl_pkey_get_details($key === false ? throw new RuntimeException('unloadable') : $key);

    return $details === false ? throw new RuntimeException('no details') : $details;
}

it('exports an RSA public key as a JWK with n and e, and an EC P-256 key with crv, x and y', function () {
    $rsa = Jwk::fromDetails(keyDetails(KeyPairGenerator::generate('RS256')), 'k1', 'RS256');
    $ec = Jwk::fromDetails(keyDetails(KeyPairGenerator::generate('ES256')), 'k2', 'ES256');

    expect($rsa)->toHaveKeys(['kty', 'kid', 'alg', 'use', 'n', 'e'])
        ->and($rsa['kty'])->toBe('RSA')
        ->and($rsa['alg'])->toBe('RS256')
        ->and($rsa['use'])->toBe('sig')
        ->and($rsa['e'])->toBe('AQAB')
        ->and($rsa)->not->toHaveKey('d')
        ->and($ec)->toHaveKeys(['kty', 'kid', 'alg', 'use', 'crv', 'x', 'y'])
        ->and($ec['crv'])->toBe('P-256')
        ->and(strlen(Jwk::base64url(str_repeat('a', 32))))->toBe(43);
});

it('computes the RFC 7638 thumbprint over the canonical members only, so it is stable across reorderings', function () {
    $details = keyDetails(KeyPairGenerator::generate('RS256'));
    $jwk = Jwk::fromDetails($details, 'k1', 'RS256');

    $expected = Jwk::base64url(hash('sha256', json_encode(['e' => $jwk['e'], 'kty' => 'RSA', 'n' => $jwk['n']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true));

    expect(Jwk::thumbprint($jwk))->toBe($expected)
        ->and(Jwk::thumbprint(Jwk::fromDetails($details, 'another-kid', 'RS256')))->toBe($expected);
});

it('refuses a curve other than P-256 and a key type it cannot publish', function () {
    $ecKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
    $details = openssl_pkey_get_details($ecKey === false ? throw new RuntimeException('no key') : $ecKey);

    expect(fn () => Jwk::fromDetails($details === false ? [] : $details, 'k', 'ES256'))->toThrow(ConfigurationException::class, 'P-256')
        ->and(fn () => Jwk::fromDetails(['type' => 99], 'k', 'RS256'))->toThrow(ConfigurationException::class, 'RSA');
});
