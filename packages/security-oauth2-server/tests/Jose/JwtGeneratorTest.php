<?php

declare(strict_types=1);

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Firefly\Security\OAuth2\InMemoryJwksProvider;
use Firefly\Security\OAuth2\LocalJwksProvider;
use Firefly\Security\OAuth2\Server\Jose\AuthorizationServerJwksDocumentSource;
use Firefly\Security\OAuth2\Server\Jose\JwtGenerator;
use Firefly\Security\OAuth2\Server\Jose\JwtSigningKeys;
use Firefly\Security\OAuth2\Server\Jose\KeyPairGenerator;
use Firefly\Security\OAuth2\Server\Jose\SigningKey;

function generatorOver(string $algorithm, ?SigningKey $previous = null): JwtGenerator
{
    return new JwtGenerator(new JwtSigningKeys(SigningKey::fromPem(KeyPairGenerator::generate($algorithm), $algorithm, 'k-now'), $previous === null ? [] : [$previous]));
}

it('signs with the current key and kid, and verifies through the JWKS the LocalJwksProvider serves', function (string $algorithm) {
    $generator = generatorOver($algorithm);
    $jwt = $generator->encode(['sub' => 'ada', 'iat' => time(), 'exp' => time() + 60]);

    $header = json_decode(JWT::urlsafeB64Decode(explode('.', $jwt)[0]), true);
    expect($header)->toMatchArray(['alg' => $algorithm, 'kid' => 'k-now', 'typ' => 'JWT'])
        ->and($generator->decode($jwt)['sub'])->toBe('ada')
        ->and($generator->algorithm())->toBe($algorithm);

    // The same bytes the resource-server filter would use in this application, through the shipped provider.
    $keys = (new LocalJwksProvider(new AuthorizationServerJwksDocumentSource($generator->keys())))->keys();
    expect((array) JWT::decode($jwt, $keys))->toMatchArray(['sub' => 'ada'])
        ->and(InMemoryJwksProvider::fromJwks((new AuthorizationServerJwksDocumentSource($generator->keys()))->jwks())->keys())->toHaveKey('k-now');
})->with(['RS256', 'ES256']);

it('still verifies a token signed by a previous key, and refuses one signed by a stranger', function () {
    $old = SigningKey::fromPem(KeyPairGenerator::generate('RS256'), 'RS256', 'k-old');
    $generator = generatorOver('RS256', $old);
    $signedByOld = JWT::encode(['sub' => 'ada', 'exp' => time() + 60], $old->key, 'RS256', 'k-old');
    $stranger = SigningKey::fromPem(KeyPairGenerator::generate('RS256'), 'RS256', 'k-now');
    $forged = JWT::encode(['sub' => 'eve', 'exp' => time() + 60], $stranger->key, 'RS256', 'k-now');

    expect($generator->decode($signedByOld)['sub'])->toBe('ada')
        ->and(fn () => $generator->decode($forged))->toThrow(SignatureInvalidException::class);
});

it('reads an expired token\'s payload when asked to ignore expiry, and refuses it otherwise', function () {
    $generator = generatorOver('RS256');
    $expired = $generator->encode(['sub' => 'ada', 'iat' => time() - 120, 'exp' => time() - 60]);

    expect(fn () => $generator->decode($expired))->toThrow(ExpiredException::class)
        ->and($generator->decodeIgnoringExpiry($expired)['sub'])->toBe('ada');
});
