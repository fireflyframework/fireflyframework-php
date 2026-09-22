<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\JwksDocumentSource;
use Firefly\Security\OAuth2\Server\Jose\AuthorizationServerJwksDocumentSource;
use Firefly\Security\OAuth2\Server\Jose\JwtGenerator;
use Firefly\Security\OAuth2\Server\Jose\JwtSigningKeys;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerBootTestCase;

uses(OAuth2ServerBootTestCase::class);

it('binds the keys, the generator and the JwksDocumentSource the security core looks for', function () {
    /** @var OAuth2ServerBootTestCase $this */
    $keys = $this->app()->make(JwtSigningKeys::class);
    $source = $this->app()->make(JwksDocumentSource::class);

    expect($keys->current()->canSign)->toBeTrue()
        ->and($this->app()->make(JwtGenerator::class))->toBeInstanceOf(JwtGenerator::class)
        ->and($source)->toBeInstanceOf(AuthorizationServerJwksDocumentSource::class)
        ->and($source->jwks())->toBe($keys->jwks())
        ->and($keys->jwks()['keys'][0]['kid'])->toBe($keys->current()->kid);
});
