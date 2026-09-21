<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Authorization\OAuth2Authorization;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Token;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Firefly\Security\OAuth2\Server\Authorization\TokenHash;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientFactory;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;

function authorizationFor(string $principal = 'ada'): OAuth2Authorization
{
    $client = RegisteredClientFactory::fromConfig('web-app', ['client_secret' => '{noop}s', 'redirect_uris' => ['https://a.test/cb']], new AuthorizationServerSettings);

    return OAuth2Authorization::create($client, $principal, AuthorizationGrantType::AuthorizationCode, ['openid', 'profile'], ['redirect_uri' => 'https://a.test/cb']);
}

it('issues a token with its hash and keeps the plain value only until it is stripped', function () {
    $now = new DateTimeImmutable('2026-09-21 10:00:00');
    $token = OAuth2Token::issue(OAuth2TokenType::AuthorizationCode, 'the-code', $now, $now->modify('+5 minutes'), ['claims' => ['a' => 1]]);

    expect($token->hash)->toBe(hash('sha256', 'the-code'))
        ->and($token->hash)->toBe(TokenHash::of('the-code'))
        ->and($token->value)->toBe('the-code')
        ->and($token->withoutValue()->value)->toBeNull()
        ->and($token->withoutValue()->hash)->toBe($token->hash)
        ->and($token->isActive($now))->toBeTrue()
        ->and($token->isExpired($now->modify('+5 minutes')))->toBeTrue()
        ->and($token->invalidated()->isInvalidated())->toBeTrue()
        ->and($token->invalidated()->isActive($now))->toBeFalse()
        ->and(OAuth2Token::fromArray($token->withoutValue()->toArray()))->toEqual($token->withoutValue());
});

it('creates an authorization with a random id, holds one token per type, and answers the latest expiry', function () {
    $now = new DateTimeImmutable('2026-09-21 10:00:00');
    $authorization = authorizationFor()
        ->withToken(OAuth2Token::issue(OAuth2TokenType::AuthorizationCode, 'c', $now, $now->modify('+5 minutes')))
        ->withToken(OAuth2Token::issue(OAuth2TokenType::RefreshToken, 'r', $now, $now->modify('+1 hour')));

    expect(strlen($authorization->id))->toBe(32)
        ->and($authorization->registeredClientId)->toBe('web-app')
        ->and($authorization->principalName)->toBe('ada')
        ->and($authorization->authorizedScopes)->toBe(['openid', 'profile'])
        ->and($authorization->attribute('redirect_uri'))->toBe('https://a.test/cb')
        ->and($authorization->attribute('nonce', 'none'))->toBe('none')
        ->and($authorization->token(OAuth2TokenType::AuthorizationCode)?->value)->toBe('c')
        ->and($authorization->token(OAuth2TokenType::AccessToken))->toBeNull()
        ->and($authorization->expiresAt())->toEqual($now->modify('+1 hour'))
        ->and($authorization->isActive($now))->toBeTrue()
        ->and($authorization->isActive($now->modify('+2 hours')))->toBeFalse()
        ->and($authorization->withoutTokenValues()->token(OAuth2TokenType::RefreshToken)?->value)->toBeNull()
        ->and($authorization->withInvalidatedToken(OAuth2TokenType::AuthorizationCode)->token(OAuth2TokenType::AuthorizationCode)?->isInvalidated())->toBeTrue()
        ->and($authorization->withEveryTokenInvalidated()->isActive($now))->toBeFalse()
        ->and($authorization->withAttribute('nonce', 'n1')->attribute('nonce'))->toBe('n1');
});

it('keeps a bounded family of superseded refresh-token hashes', function () {
    $authorization = authorizationFor();
    for ($i = 0; $i < 25; $i++) {
        $authorization = $authorization->withSupersededRefreshToken("h{$i}");
    }

    expect($authorization->refreshTokenFamily())->toHaveCount(OAuth2Authorization::FAMILY_LIMIT)
        ->and($authorization->refreshTokenFamily()[0])->toBe('h5')
        ->and($authorization->refreshTokenFamily()[19])->toBe('h24');
});
