<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Jose\JwtGenerator;
use Firefly\Security\OAuth2\Server\Jose\JwtSigningKeys;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerCapstoneTestCase;
use Firefly\Security\OAuth2\Server\Web\OAuth2AuthorizationServerFilter;

uses(OAuth2ServerCapstoneTestCase::class);

it('boots the filter and publishes the OIDC discovery and RFC 8414 documents ahead of the deny-by-default rules', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    expect($this->app()->make(OAuth2AuthorizationServerFilter::class))->toBeInstanceOf(OAuth2AuthorizationServerFilter::class);

    $oidc = $this->getJson('/.well-known/openid-configuration');
    $oidc->assertOk()->assertJson([
        'issuer' => 'http://localhost',
        'authorization_endpoint' => 'http://localhost/oauth2/authorize',
        'token_endpoint' => 'http://localhost/oauth2/token',
        'jwks_uri' => 'http://localhost/oauth2/jwks',
        'introspection_endpoint' => 'http://localhost/oauth2/introspect',
        'revocation_endpoint' => 'http://localhost/oauth2/revoke',
        'userinfo_endpoint' => 'http://localhost/userinfo',
        'end_session_endpoint' => 'http://localhost/connect/logout',
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'client_credentials', 'refresh_token'],
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'private_key_jwt', 'none'],
        'introspection_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'private_key_jwt'],
        'subject_types_supported' => ['public'],
        'id_token_signing_alg_values_supported' => ['RS256'],
        'scopes_supported' => ['openid'],
    ])->assertJsonMissing(['registration_endpoint' => 'http://localhost/connect/register']);

    expect($this->getJson('/.well-known/oauth-authorization-server')->assertOk()->json())->toBe($oidc->json());
});

it('serves the JWKS with the current kid first and a public cache header', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    $kid = $this->app()->make(JwtSigningKeys::class)->current()->kid;

    $this->getJson('/oauth2/jwks')
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=3600, public')
        ->assertJsonPath('keys.0.kid', $kid)
        ->assertJsonPath('keys.0.kty', 'RSA')
        ->assertJsonPath('keys.0.alg', 'RS256')
        ->assertJsonMissingPath('keys.0.d');
});

it('answers a wrong method with 405, Allow, and the RFC 6749 JSON document', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    $this->postJson('/oauth2/jwks')
        ->assertStatus(405)
        ->assertHeader('Allow', 'GET')
        ->assertJson(['error' => 'invalid_request']);
});

it('is the resource server for its own keys: a JWT signed by the generator passes the resource-server filter on /api/profile', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    $jwt = $this->app()->make(JwtGenerator::class)->encode(['iss' => 'http://localhost', 'sub' => 'ada', 'scope' => 'orders:read', 'iat' => time(), 'exp' => time() + 60]);

    $this->getJson('/api/profile')->assertStatus(401);
    $this->withHeader('Authorization', 'Bearer '.$jwt)->getJson('/api/profile')
        ->assertOk()
        ->assertJson(['sub' => 'ada', 'authorities' => ['SCOPE_orders:read'], 'authenticated' => true]);
});
