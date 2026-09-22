<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerCapstoneTestCase;

abstract class RefreshCapstoneTestCase extends OAuth2ServerCapstoneTestCase
{
    protected function clients(): array
    {
        $clients = parent::clients();
        $clients['reuse-app'] = [
            'client_secret' => '{noop}reuse-secret',
            'authorization_grant_types' => ['authorization_code', 'refresh_token'],
            'redirect_uris' => ['https://reuse.test/cb'],
            'scopes' => ['openid', 'profile'],
            'client_settings' => ['require_authorization_consent' => false],
            'token_settings' => ['reuse_refresh_tokens' => true],
        ];

        return $clients;
    }
}

uses(RefreshCapstoneTestCase::class);

it('rotates the refresh token, invalidates the previous access token, narrows the scope on request, and detects reuse by revoking the family', function () {
    /** @var RefreshCapstoneTestCase $this */
    $this->signIn();
    $oauth2 = $this->oauth2();
    $first = $oauth2->tokens('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid profile orders:read', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET);
    /** @var string $firstRefresh */
    $firstRefresh = $first['refresh_token'];
    /** @var string $firstAccess */
    $firstAccess = $first['access_token'];

    $refreshed = $oauth2->refresh('web-app', $firstRefresh, OAuth2ServerCapstoneTestCase::WEB_APP_SECRET, 'openid orders:read');
    $refreshed->assertOk()->assertJson(['token_type' => 'Bearer', 'scope' => 'openid orders:read']);
    /** @var array<string,mixed> $second */
    $second = $refreshed->json();
    /** @var string $secondRefresh */
    $secondRefresh = $second['refresh_token'];
    expect($secondRefresh)->not->toBe($firstRefresh)
        ->and($second['access_token'])->not->toBe($firstAccess)
        ->and($second)->toHaveKey('id_token');

    /** @var OAuth2AuthorizationService $service */
    $service = $this->app()->make(OAuth2AuthorizationService::class);
    $authorization = $service->findByToken($secondRefresh, OAuth2TokenType::RefreshToken);
    expect($authorization?->authorizedScopes)->toBe(['openid', 'profile', 'orders:read'])
        ->and($authorization?->refreshTokenFamily())->toHaveCount(1)
        // The previous access token was replaced on the record: it no longer resolves, so introspection says inactive.
        ->and($service->findByToken($firstAccess, OAuth2TokenType::AccessToken))->toBeNull();

    // The new token widens back to the original grant when asked for nothing narrower.
    $oauth2->refresh('web-app', $secondRefresh, OAuth2ServerCapstoneTestCase::WEB_APP_SECRET)->assertOk()->assertJson(['scope' => 'openid profile orders:read']);

    // Presenting the FIRST refresh token again is a replay: the whole authorization is gone.
    $oauth2->refresh('web-app', $firstRefresh, OAuth2ServerCapstoneTestCase::WEB_APP_SECRET)->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    expect($service->findById($authorization->id ?? ''))->toBeNull();
    $oauth2->refresh('web-app', $secondRefresh, OAuth2ServerCapstoneTestCase::WEB_APP_SECRET)->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
});

it('keeps the same refresh token for a client that opts into reuse', function () {
    /** @var RefreshCapstoneTestCase $this */
    $this->signIn();
    $oauth2 = $this->oauth2();
    $first = $oauth2->tokens('reuse-app', 'https://reuse.test/cb', 'openid', 'reuse-secret');
    /** @var string $firstRefresh */
    $firstRefresh = $first['refresh_token'];

    /** @var array<string,mixed> $refreshed */
    $refreshed = $oauth2->refresh('reuse-app', $firstRefresh, 'reuse-secret')->assertOk()->json();
    expect($refreshed['refresh_token'])->toBe($firstRefresh);
    $oauth2->refresh('reuse-app', $firstRefresh, 'reuse-secret')->assertOk();
});

it('refuses a widened scope, an unknown token, another client\'s token, a public client and a missing token', function () {
    /** @var RefreshCapstoneTestCase $this */
    $this->signIn();
    $oauth2 = $this->oauth2();
    $tokens = $oauth2->tokens('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET);
    /** @var string $refresh */
    $refresh = $tokens['refresh_token'];

    $oauth2->refresh('web-app', $refresh, OAuth2ServerCapstoneTestCase::WEB_APP_SECRET, 'openid orders:read')->assertStatus(400)->assertJson(['error' => 'invalid_scope']);
    $oauth2->refresh('web-app', 'unknown', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET)->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    $oauth2->refresh('reuse-app', $refresh, 'reuse-secret')->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    $this->post('/oauth2/token', ['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => 'public-spa'], ['Accept' => 'application/json'])
        ->assertStatus(400)->assertJson(['error' => 'unauthorized_client']);
    $oauth2->token('web-app', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET, ['grant_type' => 'refresh_token'])->assertStatus(400)->assertJson(['error' => 'invalid_request']);

    // None of that touched the valid token.
    $oauth2->refresh('web-app', $refresh, OAuth2ServerCapstoneTestCase::WEB_APP_SECRET)->assertOk();
});
