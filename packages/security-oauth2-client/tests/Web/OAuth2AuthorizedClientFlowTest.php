<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Tests\Support\OAuth2ClientCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;
use Firefly\Testing\Security\OAuth2\FakeAuthorizationServer;

/**
 * Outbound calls through the real pipeline: a controller uses Http::oauth2Client('fake') with the person's
 * token from the session (refreshed when it is about to expire, the refreshed client written back) and
 * Http::oauth2Client('svc') with client credentials (fetched once, cached across requests, refetched on expiry).
 */
abstract class AuthorizedClientCapstoneTestCase extends OAuth2ClientCapstoneTestCase
{
    protected function clientOverrides(): array
    {
        return [
            'firefly.security.oauth2.client.registration.svc' => FakeAuthorizationServer::registrationConfig(['authorization_grant_type' => 'client_credentials', 'scope' => ['orders:read'], 'client_name' => 'Service']),
        ];
    }
}

uses(AuthorizedClientCapstoneTestCase::class, SecurityFlows::class);

it('calls the API as the signed-in person, and refreshes the token when it is about to expire', function () {
    /** @var AuthorizedClientCapstoneTestCase $this */
    $callback = $this->signInThroughProvider();
    $this->forgetSession();

    $this->followSession($callback)->getJson('/api/proxy/me')->assertOk()->assertJson(['sub' => 'ada', 'email' => 'ada@example.com']);
    expect($this->idp->userInfoRequests)->toBe([$this->idp->issuedAccessTokens[0], $this->idp->issuedAccessTokens[0]])
        ->and($this->idp->tokenRequests)->toHaveCount(1);

    $this->travel(3541)->seconds();
    $this->forgetSession();
    $this->followSession($callback)->getJson('/api/proxy/me')->assertOk()->assertJson(['sub' => 'ada']);
    expect($this->idp->tokenRequests)->toHaveCount(2)
        ->and($this->idp->lastTokenRequest()['grant_type'])->toBe('refresh_token')
        ->and($this->idp->userInfoRequests[2])->toBe($this->idp->issuedAccessTokens[1]);

    // The refreshed client was written back: the next call needs no token request.
    $this->forgetSession();
    $this->followSession($callback)->getJson('/api/proxy/me')->assertOk();
    expect($this->idp->tokenRequests)->toHaveCount(2);

    // Nothing on disk in clear, after the refresh either: neither the new access token nor the refresh token that was sent.
    foreach ($this->sessionFiles() as $content) {
        expect($content)->not->toContain($this->idp->issuedAccessTokens[1])->not->toContain($this->idp->lastTokenRequest()['form']['refresh_token'] ?? 'no-refresh-token');
    }
});

it('refuses the user-bound call for a person who did not sign in through the provider, as a 401', function () {
    /** @var AuthorizedClientCapstoneTestCase $this */
    $this->actingAsPrincipal('ada', ['ROLE_USER'])->getJson('/api/proxy/me')->assertStatus(401)->assertJson(['code' => 'CLIENT_AUTHORIZATION_REQUIRED']);
    expect($this->idp->tokenRequests)->toBe([]);
});

it('calls the API as the application with client credentials, fetched once and cached across requests', function () {
    /** @var AuthorizedClientCapstoneTestCase $this */
    $client = $this->actingAsPrincipal('ada', ['ROLE_USER']);

    $client->getJson('/api/proxy/service')->assertOk()->assertJson(['sub' => FakeAuthorizationServer::CLIENT_ID]);
    $client->getJson('/api/proxy/service')->assertOk();
    expect($this->idp->tokenRequests)->toHaveCount(1)
        ->and($this->idp->tokenRequests[0]['form'])->toMatchArray(['grant_type' => 'client_credentials', 'scope' => 'orders:read'])
        ->and($this->idp->tokenRequests[0]['authorization'])->toStartWith('Basic ');

    $this->travel(3541)->seconds();
    $client->getJson('/api/proxy/service')->assertOk();
    expect($this->idp->tokenRequests)->toHaveCount(2);
});
