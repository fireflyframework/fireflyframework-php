<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerCapstoneTestCase;

abstract class IntrospectionCapstoneTestCase extends OAuth2ServerCapstoneTestCase
{
    protected function clients(): array
    {
        $clients = parent::clients();
        $clients['ref-svc'] = [
            'client_secret' => '{noop}ref-secret',
            'authorization_grant_types' => ['client_credentials'],
            'scopes' => ['orders:read'],
            'token_settings' => ['access_token_format' => 'reference'],
        ];

        return $clients;
    }
}

uses(IntrospectionCapstoneTestCase::class);

it('introspects a JWT access token, a reference token and a refresh token, and answers active:false for anything else', function () {
    /** @var IntrospectionCapstoneTestCase $this */
    $this->signIn();
    $oauth2 = $this->oauth2();
    $tokens = $oauth2->tokens('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid orders:read', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET);
    /** @var string $accessToken */
    $accessToken = $tokens['access_token'];
    /** @var string $refreshToken */
    $refreshToken = $tokens['refresh_token'];
    /** @var string $idToken */
    $idToken = $tokens['id_token'];
    /** @var string $reference */
    $reference = $oauth2->clientCredentials('ref-svc', 'ref-secret', 'orders:read')->json('access_token');

    // Any authenticated confidential client may introspect any token (resource servers are clients).
    $access = $oauth2->introspect('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, $accessToken);
    $access->assertOk()->assertJson(['active' => true, 'client_id' => 'web-app', 'token_type' => 'Bearer', 'scope' => 'openid orders:read', 'sub' => 'ada', 'username' => 'ada', 'iss' => 'http://localhost', 'aud' => ['web-app']]);
    expect($access->json('exp'))->toBeInt()->and($access->json('iat'))->toBeInt()->and($access->json('jti'))->toBeString();

    $oauth2->introspect('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, $reference, 'access_token')
        ->assertOk()->assertJson(['active' => true, 'client_id' => 'ref-svc', 'sub' => 'ref-svc', 'scope' => 'orders:read']);

    $oauth2->introspect('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, $refreshToken, 'refresh_token')
        ->assertOk()->assertJson(['active' => true, 'client_id' => 'web-app', 'token_type' => 'refresh_token', 'sub' => 'ada']);

    // A wrong hint is only a hint.
    $oauth2->introspect('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, $refreshToken, 'access_token')->assertOk()->assertJson(['active' => true, 'token_type' => 'refresh_token']);

    $oauth2->introspect('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, 'nope')->assertOk()->assertJson(['active' => false]);
    $oauth2->introspect('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, $idToken)->assertOk()->assertJson(['active' => false]);
});

it('requires a confidential client and the token parameter', function () {
    /** @var IntrospectionCapstoneTestCase $this */
    $oauth2 = $this->oauth2();

    $oauth2->introspect('svc', 'nope', 'x')->assertStatus(401)->assertJson(['error' => 'invalid_client'])->assertHeader('WWW-Authenticate', 'Basic realm="oauth2"');
    $this->post('/oauth2/introspect', ['token' => 'x', 'client_id' => 'public-spa'], ['Accept' => 'application/json'])->assertStatus(401)->assertJson(['error' => 'invalid_client']);
    $this->withBasicAuth('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET)->post('/oauth2/introspect', [], ['Accept' => 'application/json'])->assertStatus(400)->assertJson(['error' => 'invalid_request']);
    $this->flushHeaders();
    $this->getJson('/oauth2/introspect')->assertStatus(405);
});

it('revokes an access token alone, and a refresh token together with its access token; unknown tokens are 200', function () {
    /** @var IntrospectionCapstoneTestCase $this */
    $this->signIn();
    $oauth2 = $this->oauth2();
    $tokens = $oauth2->tokens('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET);
    /** @var string $accessToken */
    $accessToken = $tokens['access_token'];
    /** @var string $refreshToken */
    $refreshToken = $tokens['refresh_token'];

    $oauth2->revoke('web-app', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET, $accessToken, 'access_token')->assertOk();
    $oauth2->introspect('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, $accessToken)->assertJson(['active' => false]);
    $oauth2->introspect('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, $refreshToken)->assertJson(['active' => true]);

    /** @var array<string,mixed> $renewed */
    $renewed = $oauth2->refresh('web-app', $refreshToken, OAuth2ServerCapstoneTestCase::WEB_APP_SECRET)->assertOk()->json();
    /** @var string $renewedAccess */
    $renewedAccess = $renewed['access_token'];
    /** @var string $renewedRefresh */
    $renewedRefresh = $renewed['refresh_token'];

    $oauth2->revoke('web-app', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET, $renewedRefresh)->assertOk();
    $oauth2->introspect('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, $renewedRefresh)->assertJson(['active' => false]);
    $oauth2->introspect('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, $renewedAccess)->assertJson(['active' => false]);
    $oauth2->refresh('web-app', $renewedRefresh, OAuth2ServerCapstoneTestCase::WEB_APP_SECRET)->assertStatus(400)->assertJson(['error' => 'invalid_grant']);

    $oauth2->revoke('web-app', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET, 'unknown')->assertOk();
});

it('refuses to revoke another client\'s token, and requires a confidential client', function () {
    /** @var IntrospectionCapstoneTestCase $this */
    $this->signIn();
    $oauth2 = $this->oauth2();
    $tokens = $oauth2->tokens('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET);
    /** @var string $accessToken */
    $accessToken = $tokens['access_token'];

    $oauth2->revoke('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, $accessToken)->assertStatus(401)->assertJson(['error' => 'invalid_client']);
    $oauth2->introspect('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, $accessToken)->assertJson(['active' => true]);
    $this->post('/oauth2/revoke', ['token' => 'x', 'client_id' => 'public-spa'], ['Accept' => 'application/json'])->assertStatus(401);
});
