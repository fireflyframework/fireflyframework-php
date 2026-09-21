<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerCapstoneTestCase;
use Firefly\Security\OAuth2\Server\Token\JwtEncodingContext;
use Firefly\Security\OAuth2\Server\Token\OAuth2TokenCustomizer;
use Illuminate\Foundation\Application;

abstract class ClientCredentialsCapstoneTestCase extends OAuth2ServerCapstoneTestCase
{
    protected function clients(): array
    {
        $clients = parent::clients();
        $clients['ref-svc'] = [
            'client_secret' => '{noop}ref-secret',
            'authorization_grant_types' => ['client_credentials'],
            'scopes' => ['orders:read'],
            'token_settings' => ['access_token_format' => 'reference', 'access_token_ttl' => 120],
        ];

        return $clients;
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);

        // The application's own customizer: the authorities the resource-server filter reads from `roles`.
        $app->instance(OAuth2TokenCustomizer::class, new class implements OAuth2TokenCustomizer
        {
            public function customize(JwtEncodingContext $context): void
            {
                $context->claim('roles', ['ROLE_SERVICE']);
            }
        });
    }
}

uses(ClientCredentialsCapstoneTestCase::class);

it('issues a JWT to a confidential client, which the resource-server filter then accepts on the API', function () {
    /** @var ClientCredentialsCapstoneTestCase $this */
    $response = $this->oauth2()->clientCredentials('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, 'orders:read');

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson(['token_type' => 'Bearer', 'expires_in' => 300, 'scope' => 'orders:read'])
        ->assertJsonMissing(['refresh_token' => null]);
    /** @var array<string,mixed> $body */
    $body = $response->json();
    expect($body)->not->toHaveKey('refresh_token');
    /** @var string $accessToken */
    $accessToken = $body['access_token'];

    $claims = $this->decodeJwt($accessToken);
    expect($claims)->toMatchArray(['iss' => 'http://localhost', 'sub' => 'svc', 'aud' => ['svc'], 'scope' => 'orders:read', 'client_id' => 'svc', 'roles' => ['ROLE_SERVICE']]);

    $this->withHeader('Authorization', 'Bearer '.$accessToken)->getJson('/api/profile')
        ->assertOk()
        ->assertJson(['sub' => 'svc', 'authorities' => ['SCOPE_orders:read', 'ROLE_SERVICE'], 'authenticated' => true]);

    $stored = $this->app()->make(OAuth2AuthorizationService::class)->findByToken($accessToken, OAuth2TokenType::AccessToken);
    expect($stored?->principalName)->toBe('svc')
        ->and($stored?->token(OAuth2TokenType::AccessToken)?->value)->toBeNull();
});

it('defaults the scope to every scope the client registered when none is asked for', function () {
    /** @var ClientCredentialsCapstoneTestCase $this */
    $this->oauth2()->clientCredentials('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET)->assertOk()->assertJson(['scope' => 'orders:read client.create']);
});

it('issues an opaque reference token the resource server cannot read, for a client that asks for that format', function () {
    /** @var ClientCredentialsCapstoneTestCase $this */
    $response = $this->oauth2()->clientCredentials('ref-svc', 'ref-secret', 'orders:read')->assertOk()->assertJson(['expires_in' => 120]);
    /** @var string $token */
    $token = $response->json('access_token');

    expect(substr_count($token, '.'))->toBe(0);
    $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/profile')->assertStatus(401);
});

it('refuses every bad request with the RFC 6749 document', function (string $clientId, string $secret, array $body, int $status, string $error) {
    /** @var ClientCredentialsCapstoneTestCase $this */
    /** @var array<string, mixed> $body */
    $response = $this->oauth2()->token($clientId, $secret, $body);

    $response->assertStatus($status)->assertJson(['error' => $error]);
    if ($error === 'invalid_client') {
        $response->assertHeader('WWW-Authenticate', 'Basic realm="oauth2"');
    }
})->with([
    'unknown scope' => ['svc', 'svc-secret', ['grant_type' => 'client_credentials', 'scope' => 'orders:write'], 400, 'invalid_scope'],
    'wrong secret' => ['svc', 'nope', ['grant_type' => 'client_credentials'], 401, 'invalid_client'],
    'grant the client lacks' => ['public-spa', 'x', ['grant_type' => 'client_credentials'], 401, 'invalid_client'],
    'unsupported grant' => ['svc', 'svc-secret', ['grant_type' => 'password'], 400, 'unsupported_grant_type'],
    'missing grant' => ['svc', 'svc-secret', [], 400, 'invalid_request'],
]);

it('refuses a public client the client_credentials grant, and a confidential client a grant it did not register', function () {
    /** @var ClientCredentialsCapstoneTestCase $this */
    $this->post('/oauth2/token', ['grant_type' => 'client_credentials', 'client_id' => 'public-spa'], ['Accept' => 'application/json'])
        ->assertStatus(400)
        ->assertJson(['error' => 'unauthorized_client']);

    $this->oauth2()->token('web-app', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET, ['grant_type' => 'refresh_token', 'refresh_token' => 'x'])
        ->assertStatus(400)
        ->assertJson(['error' => 'unsupported_grant_type']);
});

it('answers GET with 405 and a browser with JSON, never an HTML page', function () {
    /** @var ClientCredentialsCapstoneTestCase $this */
    $this->get('/oauth2/token', ['Accept' => 'text/html'])
        ->assertStatus(405)
        ->assertHeader('Allow', 'POST')
        ->assertHeader('Content-Type', 'application/json')
        ->assertJson(['error' => 'invalid_request']);

    $this->post('/oauth2/token', ['grant_type' => 'client_credentials'], ['Accept' => 'text/html'])
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJson(['error' => 'invalid_client']);
});

it('is not fooled by a signed-in browser: the token endpoint authenticates the CLIENT, not the session', function () {
    /** @var ClientCredentialsCapstoneTestCase $this */
    $this->actingAsPrincipal('ada', ['ROLE_USER']);

    $this->post('/oauth2/token', ['grant_type' => 'client_credentials'], ['Accept' => 'application/json'])
        ->assertStatus(401)
        ->assertJson(['error' => 'invalid_client']);
});
