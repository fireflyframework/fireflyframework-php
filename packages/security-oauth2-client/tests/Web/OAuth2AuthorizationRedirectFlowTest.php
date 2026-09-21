<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Tests\Support\OAuth2ClientCapstoneTestCase;
use Firefly\Security\OAuth2\Client\Web\OAuth2AuthorizationRequest;
use Firefly\Security\Tests\Support\SecurityFlows;
use Firefly\Testing\Security\OAuth2\FakeAuthorizationServer;

/**
 * The first half of the login, through the real pipeline: the entry point sends an anonymous browser to the
 * login page (with only OAuth2 login on), the page lists the provider, the redirect filter answers the start
 * URL with a 302 that carries state, nonce and a PKCE challenge, and the session holds the request they came
 * from. A second registration with the client_credentials grant is present to prove it is NOT a login.
 */
abstract class RedirectCapstoneTestCase extends OAuth2ClientCapstoneTestCase
{
    protected function clientOverrides(): array
    {
        return [
            'firefly.security.oauth2.client.registration.svc' => FakeAuthorizationServer::registrationConfig(['authorization_grant_type' => 'client_credentials', 'scope' => ['orders:read'], 'client_name' => 'Service']),
        ];
    }
}

uses(RedirectCapstoneTestCase::class, SecurityFlows::class);

it('sends an anonymous browser to a login page that lists the provider and has no password form', function () {
    /** @var RedirectCapstoneTestCase $this */
    $refused = $this->get('/home', ['Accept' => 'text/html']);
    $refused->assertRedirect('/login');

    $page = $this->get('/login');
    $page->assertOk()
        ->assertSee('Sign in with Fake IdP')
        ->assertSee('href="/oauth2/authorization/fake"', escape: false)
        ->assertDontSee('Service')
        ->assertDontSee('<form', escape: false);
});

it('redirects the start URL to the provider with state, nonce and an S256 challenge, and keeps the request in the session', function () {
    /** @var RedirectCapstoneTestCase $this */
    $start = $this->startLogin();
    $query = $this->queryOf($start);

    expect((string) $start->headers->get('Location'))->toStartWith('http://localhost/fake-idp/authorize?')
        ->and($query)->toMatchArray(['response_type' => 'code', 'client_id' => FakeAuthorizationServer::CLIENT_ID, 'redirect_uri' => 'http://localhost/login/oauth2/code/fake', 'scope' => 'openid profile email', 'code_challenge_method' => 'S256'])
        ->and($query['state'])->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($query['nonce'])->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($query['code_challenge'])->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($this->idp->discoveryRequests)->toBe(1);

    $this->forgetSession();
    $stored = $this->followSession($start)->getJson('/open/authorization-request');
    $stored->assertOk();
    $verifier = $stored->json('codeVerifier');
    expect($stored->json('state'))->toBe($query['state'])
        ->and($stored->json('nonce'))->toBe($query['nonce'])
        ->and($stored->json('registrationId'))->toBe('fake')
        ->and(is_string($verifier) ? OAuth2AuthorizationRequest::codeChallenge($verifier) : 'no verifier')->toBe($query['code_challenge']);

    // A second start in the same session replaces the first (one per session), and discovery is not fetched again.
    $second = $this->followSession($start)->get('/oauth2/authorization/fake');
    expect($this->queryOf($second)['state'])->not->toBe($query['state'])
        ->and($this->idp->discoveryRequests)->toBe(1);
});

it('is not the start of a login for an unknown id, a client_credentials registration, or another method', function () {
    /** @var RedirectCapstoneTestCase $this */
    $notLogins = [['GET', '/oauth2/authorization/nope'], ['GET', '/oauth2/authorization/svc'], ['POST', '/oauth2/authorization/fake'], ['GET', '/oauth2/authorization/fake/extra']];

    // The filter lets each of these fall through, so what answers is the URL rules — `*` needs a principal
    // here, hence the entry point: a 401 for JSON, the login page for a browser — and never the provider.
    foreach ($notLogins as [$method, $url]) {
        $this->json($method, $url)->assertStatus(401)->assertJson(['code' => 'AUTHENTICATION_FAILED']);
    }

    $refused = $this->get('/oauth2/authorization/nope');
    $refused->assertRedirect('/login');

    // ...and the session the entry point opened holds no authorization request: nothing was started.
    $this->forgetSession();
    $stored = $this->followSession($refused)->getJson('/open/authorization-request');
    $stored->assertOk();
    expect($stored->json('registrationId'))->toBeNull()
        ->and($stored->json('state'))->toBeNull();
});

it('answers a 503 naming the provider when discovery is down, and recovers without a restart', function () {
    /** @var RedirectCapstoneTestCase $this */
    $this->idp->takeDiscoveryDown();

    $down = $this->getJson('/oauth2/authorization/fake');
    $down->assertStatus(503)->assertJson(['code' => 'OIDC_DISCOVERY_UNAVAILABLE']);
    expect($down->json('detail'))->toBeString()->toContain('localhost')->not->toContain('fake-idp/.well-known');

    $this->idp->takeDiscoveryDown(false);
    $this->get('/oauth2/authorization/fake')->assertRedirect();
});
