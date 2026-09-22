<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Client\RegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerCapstoneTestCase;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerEloquentCapstoneTestCase;

/**
 * Registration runs on the ELOQUENT capstone, and only there: the endpoint WRITES a client, the `memory` driver
 * is the config map rebuilt in every process, and OAuth2ServerWiringPass refuses that pairing at boot (refusal
 * 5). Testbench keeps one container across the simulated requests of a test, so a memory-driver suite would have
 * passed here and handed out dead credentials in PHP-FPM — the table is the only store on which a registered
 * client survives the request that created it.
 */
abstract class RegistrationCapstoneTestCase extends OAuth2ServerEloquentCapstoneTestCase
{
    protected function serverOverrides(): array
    {
        return ['firefly.security.oauth2.server.oidc_client_registration_endpoint' => '/connect/register'] + parent::serverOverrides();
    }
}

uses(RegistrationCapstoneTestCase::class);

it('publishes the endpoint, registers a client for a bearer with client.create, and the new client works end to end', function () {
    /** @var RegistrationCapstoneTestCase $this */
    $this->getJson('/.well-known/openid-configuration')->assertJson(['registration_endpoint' => 'http://localhost/connect/register']);

    $oauth2 = $this->oauth2();
    /** @var string $bearer */
    $bearer = $oauth2->clientCredentials('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, 'client.create')->json('access_token');

    $registered = $this->withHeader('Authorization', 'Bearer '.$bearer)->postJson('/connect/register', [
        'client_name' => 'Registered at runtime',
        'redirect_uris' => ['https://dyn.test/cb'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'scope' => 'openid profile',
        'token_endpoint_auth_method' => 'client_secret_post',
    ]);
    $this->flushHeaders();

    $registered->assertStatus(201)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson(['client_name' => 'Registered at runtime', 'redirect_uris' => ['https://dyn.test/cb'], 'grant_types' => ['authorization_code', 'refresh_token'], 'token_endpoint_auth_method' => 'client_secret_post', 'scope' => 'openid profile', 'client_secret_expires_at' => 0]);
    /** @var array{client_id: string, client_secret: string, client_id_issued_at: int} $body */
    $body = $registered->json();
    expect($body['client_id'])->toBeString()->and($body['client_secret'])->toBeString()->and($body['client_id_issued_at'])->toBeInt();

    /** @var RegisteredClientRepository $clients */
    $clients = $this->app()->make(RegisteredClientRepository::class);
    $stored = $clients->findByClientId($body['client_id']);
    expect($stored?->clientSecret)->toStartWith('{bcrypt}')->and($stored?->clientSecret)->not->toContain($body['client_secret']);

    // The new client authenticates with client_secret_post and completes the code flow.
    $this->signIn();
    $code = $oauth2->obtainCode($body['client_id'], 'https://dyn.test/cb', 'openid');
    $this->post('/oauth2/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://dyn.test/cb', 'code_verifier' => $oauth2->proofKey()->verifier, 'client_id' => $body['client_id'], 'client_secret' => $body['client_secret']], ['Accept' => 'application/json'])
        ->assertOk();
});

it('spends the initial access token on ONE registration: a second POST with the same bearer is invalid_token', function () {
    /** @var RegistrationCapstoneTestCase $this */
    /** @var string $bearer */
    $bearer = $this->oauth2()->clientCredentials('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, 'client.create')->json('access_token');

    $this->withHeader('Authorization', 'Bearer '.$bearer)->postJson('/connect/register', ['redirect_uris' => ['https://first.test/cb']])->assertStatus(201);
    $this->withHeader('Authorization', 'Bearer '.$bearer)->postJson('/connect/register', ['redirect_uris' => ['https://second.test/cb']])
        ->assertStatus(401)
        ->assertJson(['error' => 'invalid_token']);
    $this->flushHeaders();

    // Exactly one client was added to the three the capstone seeds.
    /** @var RegisteredClientRepository $clients */
    $clients = $this->app()->make(RegisteredClientRepository::class);
    expect($clients->all())->toHaveCount(4);

    // A FRESH bearer registers again: the rule is one client per token, not one client per client.
    /** @var string $next */
    $next = $this->oauth2()->clientCredentials('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, 'client.create')->json('access_token');
    $this->withHeader('Authorization', 'Bearer '.$next)->postJson('/connect/register', ['redirect_uris' => ['https://third.test/cb']])->assertStatus(201);
    $this->flushHeaders();
});

it('refuses to register a client that carries client.create itself', function () {
    /** @var RegistrationCapstoneTestCase $this */
    /** @var string $bearer */
    $bearer = $this->oauth2()->clientCredentials('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, 'client.create')->json('access_token');

    $this->withHeader('Authorization', 'Bearer '.$bearer)->postJson('/connect/register', ['grant_types' => ['client_credentials'], 'scope' => 'client.create'])
        ->assertStatus(400)
        ->assertJson(['error' => 'invalid_client_metadata']);
    $this->flushHeaders();

    // The refusal came before the write AND before the token was spent: the bearer is still good.
    /** @var RegisteredClientRepository $clients */
    $clients = $this->app()->make(RegisteredClientRepository::class);
    expect($clients->all())->toHaveCount(3);
    $this->withHeader('Authorization', 'Bearer '.$bearer)->postJson('/connect/register', ['redirect_uris' => ['https://dyn.test/cb']])->assertStatus(201);
    $this->flushHeaders();
});

it('refuses without the scope, without a bearer, and for bad metadata', function () {
    /** @var RegistrationCapstoneTestCase $this */
    $oauth2 = $this->oauth2();

    $this->postJson('/connect/register', ['redirect_uris' => ['https://dyn.test/cb']])->assertStatus(401)->assertJson(['error' => 'invalid_token']);

    /** @var string $noScope */
    $noScope = $oauth2->clientCredentials('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, 'orders:read')->json('access_token');
    $this->withHeader('Authorization', 'Bearer '.$noScope)->postJson('/connect/register', ['redirect_uris' => ['https://dyn.test/cb']])->assertStatus(403)->assertJson(['error' => 'insufficient_scope']);
    $this->flushHeaders();

    /** @var string $bearer */
    $bearer = $oauth2->clientCredentials('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, 'client.create')->json('access_token');
    $this->withHeader('Authorization', 'Bearer '.$bearer)->postJson('/connect/register', ['redirect_uris' => ['/relative']])->assertStatus(400)->assertJson(['error' => 'invalid_redirect_uri']);
    $this->withHeader('Authorization', 'Bearer '.$bearer)->postJson('/connect/register', ['grant_types' => ['authorization_code']])->assertStatus(400)->assertJson(['error' => 'invalid_redirect_uri']);
    $this->withHeader('Authorization', 'Bearer '.$bearer)->postJson('/connect/register', ['redirect_uris' => ['https://dyn.test/cb'], 'grant_types' => ['password']])->assertStatus(400)->assertJson(['error' => 'invalid_client_metadata']);
    $this->withHeader('Authorization', 'Bearer '.$bearer)->postJson('/connect/register', ['redirect_uris' => ['https://dyn.test/cb'], 'token_endpoint_auth_method' => 'tls_client_auth'])->assertStatus(400)->assertJson(['error' => 'invalid_client_metadata']);
    $this->flushHeaders();

    // Every refusal above left the bearer unspent: nothing was written, so nothing was paid for.
    /** @var RegisteredClientRepository $clients */
    $clients = $this->app()->make(RegisteredClientRepository::class);
    expect($clients->all())->toHaveCount(3);
});

it('answers 405 for a GET at the registration endpoint', function () {
    /** @var RegistrationCapstoneTestCase $this */
    $this->getJson('/connect/register')->assertStatus(405)->assertHeader('Allow', 'POST');
});
