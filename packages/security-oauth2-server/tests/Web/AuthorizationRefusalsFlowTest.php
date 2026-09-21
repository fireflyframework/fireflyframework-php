<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Token;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerCapstoneTestCase;

uses(OAuth2ServerCapstoneTestCase::class);

it('does NOT redirect for an unknown client or an unregistered redirect_uri: the resource owner sees the 400', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    $this->actingAsPrincipal('ada', ['ROLE_USER']);
    $oauth2 = $this->oauth2();

    $oauth2->authorize('ghost', OAuth2ServerCapstoneTestCase::REDIRECT_URI)->assertStatus(400);
    $oauth2->authorize('web-app', 'https://evil.test/cb')->assertStatus(400);
    $oauth2->authorize('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI.'/')->assertStatus(400);

    $this->getJson('/oauth2/authorize?response_type=code&client_id=ghost')
        ->assertStatus(400)
        ->assertJson(['code' => 'INVALID_AUTHORIZATION_REQUEST']);
});

it('redirects every redirectable refusal with the RFC error and the echoed state', function (string $clientId, string $redirectUri, string $scope, array $extra, string $error) {
    /** @var OAuth2ServerCapstoneTestCase $this */
    /** @var array<string,string> $extra */
    $this->actingAsPrincipal('ada', ['ROLE_USER']);
    $oauth2 = $this->oauth2();

    $response = $oauth2->authorize($clientId, $redirectUri, $scope, $extra);
    $response->assertStatus(302);
    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith($redirectUri.'?')
        ->and($query['error'] ?? null)->toBe($error)
        ->and($query['state'] ?? null)->toBe($oauth2->state())
        ->and($query)->not->toHaveKey('code');
})->with([
    'response_type token' => ['public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid', ['response_type' => 'token'], 'unsupported_response_type'],
    'unknown scope' => ['public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid orders:write', [], 'invalid_scope'],
    'missing PKCE' => ['web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid', ['code_challenge' => '', 'code_challenge_method' => ''], 'invalid_request'],
    'plain PKCE' => ['web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid', ['code_challenge_method' => 'plain'], 'invalid_request'],
    'malformed challenge' => ['web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid', ['code_challenge' => 'short'], 'invalid_request'],
    'bad max_age' => ['public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid', ['max_age' => 'soon'], 'invalid_request'],
    'prompt=none needing consent' => ['web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid', ['prompt' => 'none'], 'consent_required'],
]);

it('redirects an anonymous prompt=none with login_required instead of the login page', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    $response = $this->oauth2()->authorize('public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid', ['prompt' => 'none']);

    $response->assertStatus(302);
    expect((string) $response->headers->get('Location'))->toContain('error=login_required');
});

it('refuses at the token endpoint a wrong verifier, a missing verifier, a wrong redirect_uri, another client\'s code and a wrong secret', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    $this->actingAsPrincipal('ada', ['ROLE_USER']);
    $oauth2 = $this->oauth2();

    $code = $oauth2->obtainCode('public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid');
    $this->post('/oauth2/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'client_id' => 'public-spa', 'code_verifier' => str_repeat('x', 43)], ['Accept' => 'application/json'])
        ->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    $this->post('/oauth2/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'client_id' => 'public-spa'], ['Accept' => 'application/json'])
        ->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    $this->post('/oauth2/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://spa.test/other', 'client_id' => 'public-spa', 'code_verifier' => $oauth2->proofKey()->verifier], ['Accept' => 'application/json'])
        ->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    $oauth2->token('web-app', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'code_verifier' => $oauth2->proofKey()->verifier])
        ->assertStatus(400)->assertJson(['error' => 'invalid_grant']);

    // None of those consumed the code: the right request still works.
    $oauth2->exchangeCode('public-spa', $code, OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI)->assertOk();

    $code = $oauth2->obtainCode('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid');
    $oauth2->exchangeCode('web-app', $code, OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'nope')->assertStatus(401)->assertJson(['error' => 'invalid_client']);
});

it('refuses a reused code with invalid_grant AND revokes the tokens it issued (RFC 6749 §4.1.2)', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    $this->actingAsPrincipal('ada', ['ROLE_USER']);
    $oauth2 = $this->oauth2();
    $code = $oauth2->obtainCode('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid');

    $first = $oauth2->exchangeCode('web-app', $code, OAuth2ServerCapstoneTestCase::REDIRECT_URI, OAuth2ServerCapstoneTestCase::WEB_APP_SECRET);
    $first->assertOk();
    $oauth2->exchangeCode('web-app', $code, OAuth2ServerCapstoneTestCase::REDIRECT_URI, OAuth2ServerCapstoneTestCase::WEB_APP_SECRET)->assertStatus(400)->assertJson(['error' => 'invalid_grant']);

    /** @var string $refreshToken */
    $refreshToken = $first->json('refresh_token');
    $authorization = $this->app()->make(OAuth2AuthorizationService::class)->findByToken($refreshToken, OAuth2TokenType::RefreshToken);
    expect($authorization?->token(OAuth2TokenType::AccessToken)?->isInvalidated())->toBeTrue()
        ->and($authorization?->token(OAuth2TokenType::RefreshToken)?->isInvalidated())->toBeTrue();
});

it('refuses an expired code', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    $this->actingAsPrincipal('ada', ['ROLE_USER']);
    $oauth2 = $this->oauth2();
    $code = $oauth2->obtainCode('public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid');

    $service = $this->app()->make(OAuth2AuthorizationService::class);
    $authorization = $service->findByToken($code, OAuth2TokenType::AuthorizationCode);
    $token = $authorization?->token(OAuth2TokenType::AuthorizationCode);
    expect($authorization)->not->toBeNull()->and($token)->not->toBeNull();
    if ($authorization !== null && $token !== null) {
        $service->save($authorization->withToken(new OAuth2Token($token->type, $token->hash, $token->issuedAt, new DateTimeImmutable('-1 second'), $token->metadata)));
    }

    $oauth2->exchangeCode('public-spa', $code, OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI)->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
});

it('refuses a client with no authorization_code grant before any redirect', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    $this->actingAsPrincipal('ada', ['ROLE_USER']);

    // svc registered no redirect URI, so nothing can be redirected to: the 400 page, not unauthorized_client.
    $this->oauth2()->authorize('svc', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'orders:read')->assertStatus(400);
});
