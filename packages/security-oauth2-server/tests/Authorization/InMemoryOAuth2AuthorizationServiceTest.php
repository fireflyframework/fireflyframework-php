<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Authorization\InMemoryOAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Authorization;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Token;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Firefly\Security\OAuth2\Server\Authorization\TokenHash;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientFactory;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;

function storedAuthorization(DateTimeImmutable $now, string $client = 'web-app', string $code = 'code-1', string $refresh = 'refresh-1'): OAuth2Authorization
{
    $registered = RegisteredClientFactory::fromConfig($client, ['client_secret' => '{noop}s', 'redirect_uris' => ['https://a.test/cb']], new AuthorizationServerSettings);

    return OAuth2Authorization::create($registered, 'ada', AuthorizationGrantType::AuthorizationCode, ['openid'])
        ->withToken(OAuth2Token::issue(OAuth2TokenType::AuthorizationCode, $code, $now, $now->modify('+5 minutes')))
        ->withToken(OAuth2Token::issue(OAuth2TokenType::AccessToken, 'access-'.$code, $now, $now->modify('+5 minutes')))
        ->withToken(OAuth2Token::issue(OAuth2TokenType::RefreshToken, $refresh, $now, $now->modify('+1 hour')));
}

it('saves without the token values and finds by id, by a typed token, by any token, and through the refresh family', function () {
    $now = new DateTimeImmutable('2026-09-21 10:00:00');
    $service = new InMemoryOAuth2AuthorizationService;
    $authorization = storedAuthorization($now)->withSupersededRefreshToken(TokenHash::of('refresh-0'));
    $service->save($authorization);

    $found = $service->findById($authorization->id);
    expect($found)->not->toBeNull()
        ->and($found?->token(OAuth2TokenType::AuthorizationCode)?->value)->toBeNull()
        ->and($found?->token(OAuth2TokenType::AuthorizationCode)?->hash)->toBe(TokenHash::of('code-1'))
        ->and($service->findByToken('code-1', OAuth2TokenType::AuthorizationCode)?->id)->toBe($authorization->id)
        ->and($service->findByToken('code-1', OAuth2TokenType::RefreshToken))->toBeNull()
        ->and($service->findByToken('access-code-1')?->id)->toBe($authorization->id)
        ->and($service->findByToken('refresh-0', OAuth2TokenType::RefreshToken)?->id)->toBe($authorization->id)
        ->and($service->findByToken('refresh-0', OAuth2TokenType::AccessToken))->toBeNull()
        ->and($service->findByToken('nope'))->toBeNull();
});

it('replaces on save, removes, counts the active authorizations of a client and purges the expired ones', function () {
    $now = new DateTimeImmutable('2026-09-21 10:00:00');
    $service = new InMemoryOAuth2AuthorizationService;
    $live = storedAuthorization($now);
    $other = storedAuthorization($now, 'other-app', 'code-2', 'refresh-2');
    $dead = storedAuthorization($now->modify('-3 hours'), 'web-app', 'code-3', 'refresh-3');
    $service->save($live);
    $service->save($other);
    $service->save($dead);

    expect($service->countActiveForClient('web-app', $now))->toBe(1)
        ->and($service->countActiveForClient('other-app', $now))->toBe(1);

    $service->save($live->withEveryTokenInvalidated());
    expect($service->countActiveForClient('web-app', $now))->toBe(0)
        ->and($service->purgeExpired($now))->toBe(1)
        ->and($service->findById($dead->id))->toBeNull()
        ->and($service->findById($live->id))->not->toBeNull();

    $service->remove($live);
    expect($service->findById($live->id))->toBeNull();
});
