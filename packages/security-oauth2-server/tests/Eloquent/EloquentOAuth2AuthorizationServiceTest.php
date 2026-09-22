<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Authorization\OAuth2Authorization;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Token;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Firefly\Security\OAuth2\Server\Authorization\TokenHash;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientFactory;
use Firefly\Security\OAuth2\Server\Eloquent\EloquentOAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Eloquent\OAuth2AuthorizationModelRepository;
use Firefly\Security\OAuth2\Server\Eloquent\OAuth2ServerSchema;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerSqliteTestCase;
use Illuminate\Support\Facades\DB;

uses(OAuth2ServerSqliteTestCase::class);

function eloquentAuthorization(DateTimeImmutable $now, string $client = 'web-app', string $code = 'code-1', string $refresh = 'refresh-1'): OAuth2Authorization
{
    $registered = RegisteredClientFactory::fromConfig($client, ['client_secret' => '{noop}s', 'redirect_uris' => ['https://a.test/cb']], new AuthorizationServerSettings);

    return OAuth2Authorization::create($registered, 'ada', AuthorizationGrantType::AuthorizationCode, ['openid', 'profile'], ['redirect_uri' => 'https://a.test/cb', 'nonce' => 'n1'])
        ->withToken(OAuth2Token::issue(OAuth2TokenType::AuthorizationCode, $code, $now, $now->modify('+5 minutes'), ['claims' => ['x' => 1]]))
        ->withToken(OAuth2Token::issue(OAuth2TokenType::AccessToken, 'access-'.$code, $now, $now->modify('+5 minutes'), ['scopes' => ['openid']]))
        ->withToken(OAuth2Token::issue(OAuth2TokenType::RefreshToken, $refresh, $now, $now->modify('+1 hour')));
}

it('round-trips an authorization: hashes in their columns, no values anywhere, attributes and metadata as JSON', function () {
    $now = new DateTimeImmutable('2026-09-21 10:00:00');
    $service = new EloquentOAuth2AuthorizationService(new OAuth2AuthorizationModelRepository);
    $authorization = eloquentAuthorization($now)->withSupersededRefreshToken(TokenHash::of('refresh-0'));
    $service->save($authorization);
    $service->save($authorization->withInvalidatedToken(OAuth2TokenType::AuthorizationCode)); // replaced, not duplicated

    $row = DB::table(OAuth2ServerSchema::AUTHORIZATIONS)->first();
    expect(DB::table(OAuth2ServerSchema::AUTHORIZATIONS)->count())->toBe(1)
        ->and($row?->authorization_code_hash)->toBe(TokenHash::of('code-1'))
        ->and($row?->refresh_token_family)->toContain(TokenHash::of('refresh-0'))
        ->and(json_encode($row))->not->toContain('code-1')
        ->and(json_encode($row))->not->toContain('refresh-1');

    $found = $service->findById($authorization->id);
    expect($found?->principalName)->toBe('ada')
        ->and($found?->registeredClientId)->toBe('web-app')
        ->and($found?->authorizationGrantType)->toBe(AuthorizationGrantType::AuthorizationCode)
        ->and($found?->authorizedScopes)->toBe(['openid', 'profile'])
        ->and($found?->attribute('nonce'))->toBe('n1')
        ->and($found?->token(OAuth2TokenType::AuthorizationCode)?->isInvalidated())->toBeTrue()
        ->and($found?->token(OAuth2TokenType::AuthorizationCode)?->metadata['claims'] ?? null)->toBe(['x' => 1])
        ->and($found?->token(OAuth2TokenType::AccessToken)?->expiresAt)->toEqual($now->modify('+5 minutes'))
        ->and($found?->token(OAuth2TokenType::IdToken))->toBeNull()
        ->and($found?->expiresAt())->toEqual($now->modify('+1 hour'));
});

it('finds by a typed token, by any token and through the refresh family, counts the active ones and purges', function () {
    $now = new DateTimeImmutable('2026-09-21 10:00:00');
    $service = new EloquentOAuth2AuthorizationService(new OAuth2AuthorizationModelRepository);
    $live = eloquentAuthorization($now)->withSupersededRefreshToken(TokenHash::of('refresh-0'));
    $other = eloquentAuthorization($now, 'other-app', 'code-2', 'refresh-2');
    $dead = eloquentAuthorization($now->modify('-3 hours'), 'web-app', 'code-3', 'refresh-3');
    $service->save($live);
    $service->save($other);
    $service->save($dead);

    expect($service->findByToken('code-1', OAuth2TokenType::AuthorizationCode)?->id)->toBe($live->id)
        ->and($service->findByToken('code-1', OAuth2TokenType::RefreshToken))->toBeNull()
        ->and($service->findByToken('access-code-2')?->id)->toBe($other->id)
        ->and($service->findByToken('refresh-0', OAuth2TokenType::RefreshToken)?->id)->toBe($live->id)
        ->and($service->findByToken('refresh-0')?->id)->toBe($live->id)
        ->and($service->findByToken('nope'))->toBeNull()
        ->and($service->countActiveForClient('web-app', $now))->toBe(1)
        ->and($service->countActiveForClient('other-app', $now))->toBe(1);

    $service->save($live->withEveryTokenInvalidated());
    expect($service->countActiveForClient('web-app', $now))->toBe(0)
        ->and($service->purgeExpired($now))->toBe(1)
        ->and($service->findById($dead->id))->toBeNull()
        ->and($service->findById($live->id))->not->toBeNull();

    $service->remove($live);
    expect($service->findById($live->id))->toBeNull();
});

/**
 * The SQL the given work ran, in order.
 *
 * @return list<string>
 */
function oauth2StatementsRun(callable $work): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $work();

        return array_values(array_map(static fn (array $entry): string => strtolower((string) $entry['query']), DB::getQueryLog()));
    } finally {
        DB::disableQueryLog();
    }
}

it('answers a current token from its indexed hash column alone, and reads the family only when that misses', function () {
    $now = new DateTimeImmutable('2026-09-21 10:00:00');
    $service = new EloquentOAuth2AuthorizationService(new OAuth2AuthorizationModelRepository);
    $service->save(eloquentAuthorization($now)->withSupersededRefreshToken(TokenHash::of('refresh-0')));

    // A live refresh token, a live access token without a hint, a live code: one equality each, no LIKE anywhere.
    $current = oauth2StatementsRun(function () use ($service): void {
        expect($service->findByToken('refresh-1', OAuth2TokenType::RefreshToken))->not->toBeNull()
            ->and($service->findByToken('access-code-1'))->not->toBeNull()
            ->and($service->findByToken('code-1', OAuth2TokenType::AuthorizationCode))->not->toBeNull();
    });
    expect($current)->toHaveCount(3)
        ->each->not->toContain('like');
    expect($current[0])->toContain('"refresh_token_hash" = ?')->not->toContain('"access_token_hash"')
        ->and($current[1])->toContain('"authorization_code_hash" = ?')->toContain('"access_token_hash" = ?')->toContain('"refresh_token_hash" = ?')->toContain('"id_token_hash" = ?');

    // A superseded refresh token misses the column and is found in the family by a second statement.
    $replayed = oauth2StatementsRun(function () use ($service): void {
        expect($service->findByToken('refresh-0', OAuth2TokenType::RefreshToken))->not->toBeNull()
            ->and($service->findByToken('refresh-0'))->not->toBeNull();
    });
    expect($replayed)->toHaveCount(4)
        ->and($replayed[0])->not->toContain('like')
        ->and($replayed[1])->toContain('"refresh_token_family" like ?')->not->toContain('_hash')
        ->and($replayed[2])->not->toContain('like')
        ->and($replayed[3])->toContain('"refresh_token_family" like ?');

    // A typed lookup for a code, access or id token never reads the family, hit or miss.
    $typedMiss = oauth2StatementsRun(function () use ($service): void {
        expect($service->findByToken('nope', OAuth2TokenType::AuthorizationCode))->toBeNull()
            ->and($service->findByToken('refresh-0', OAuth2TokenType::AccessToken))->toBeNull();
    });
    expect($typedMiss)->toHaveCount(2)
        ->each->not->toContain('like');
});
