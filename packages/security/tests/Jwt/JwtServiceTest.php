<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firefly\Kernel\Exception\Security\InvalidTokenException;
use Firefly\Kernel\Exception\Security\TokenExpiredException;
use Firefly\Security\Jwt\JwtService;
use Firefly\Security\Jwt\WeakSigningSecretException;

function jwt(): JwtService
{
    return new JwtService(str_repeat('k', 40));
}

it('round-trips claims and stamps exp', function () {
    $token = jwt()->encode(['sub' => 'alice', 'authorities' => ['ROLE_ADMIN']], 3600);
    $claims = jwt()->decode($token);

    expect($claims['sub'])->toBe('alice')
        ->and($claims['authorities'])->toBe(['ROLE_ADMIN'])
        ->and($claims)->toHaveKey('exp');
});

it('refuses a weak/placeholder signing secret at construction', function () {
    new JwtService('changeme');
})->throws(WeakSigningSecretException::class);

it('rejects an expired token with TokenExpiredException', function () {
    $token = jwt()->encode(['sub' => 'alice'], -10); // already expired

    expect(fn () => jwt()->decode($token))->toThrow(TokenExpiredException::class);
});

it('rejects a token with no exp claim', function () {
    // Hand-craft a token without exp using the same key.
    $noExp = JWT::encode(['sub' => 'alice'], str_repeat('k', 40), 'HS256');

    expect(fn () => jwt()->decode($noExp))->toThrow(InvalidTokenException::class);
});

it('rejects a tampered signature with InvalidTokenException', function () {
    $token = jwt()->encode(['sub' => 'alice'], 3600).'x';

    expect(fn () => jwt()->decode($token))->toThrow(InvalidTokenException::class);
});
