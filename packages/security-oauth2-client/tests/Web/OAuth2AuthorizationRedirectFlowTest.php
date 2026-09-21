<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Discovery\ProviderDiscoveryException;
use Firefly\Security\OAuth2\Client\Tests\Support\OAuth2ClientCapstoneTestCase;
use Firefly\Security\OAuth2\Client\Web\OAuth2AuthorizationRequest;
use Firefly\Security\Tests\Support\RecordingLogger;
use Firefly\Security\Tests\Support\SecurityFlows;
use Firefly\Testing\Security\OAuth2\FakeAuthorizationServer;
use Illuminate\Foundation\Application;
use Psr\Log\LoggerInterface;

/**
 * The first half of the login, through the real pipeline: the entry point sends an anonymous browser to the
 * login page (with only OAuth2 login on), the page lists the provider, the redirect filter answers the start
 * URL with a 302 that carries state, nonce and a PKCE challenge, and the session holds the request they came
 * from. A second registration with the client_credentials grant is present to prove it is NOT a login. The
 * logger is a RecordingLogger bound before boot — what OAuth2ClientAutoConfiguration hands the login-page
 * links — so the line the page writes when it omits a provider is asserted on, not assumed.
 */
abstract class RedirectCapstoneTestCase extends OAuth2ClientCapstoneTestCase
{
    public RecordingLogger $logger;

    protected function clientOverrides(): array
    {
        return [
            'firefly.security.oauth2.client.registration.svc' => FakeAuthorizationServer::registrationConfig(['authorization_grant_type' => 'client_credentials', 'scope' => ['orders:read'], 'client_name' => 'Service']),
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);

        $this->logger = new RecordingLogger;
        $app->instance(LoggerInterface::class, $this->logger);
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

it('still renders the login page while the provider is down, leaving it out with a warning that names it, and lists it again once discovery recovers', function () {
    /** @var RedirectCapstoneTestCase $this */
    $this->idp->takeDiscoveryDown();

    // The page is where every OTHER way in lives, so one provider's outage costs its button, not the page.
    $this->get('/login')->assertOk()
        ->assertDontSee('Sign in with Fake IdP')
        ->assertDontSee('/oauth2/authorization/fake');

    $leftOut = $this->logger->mentioning('[fake]');
    $exception = $leftOut[0]['context']['exception'] ?? null;
    expect($leftOut)->toHaveCount(1)
        ->and($leftOut[0]['level'])->toBe('warning')
        ->and($leftOut[0]['message'])->toContain('left out')->toContain('localhost')->not->toContain('fake-idp/.well-known')
        ->and($leftOut[0]['context']['registration'] ?? null)->toBe('fake')
        ->and($exception)->toBeInstanceOf(ProviderDiscoveryException::class)
        ->and($exception instanceof ProviderDiscoveryException && $exception->transient)->toBeTrue();

    // Every registration is resolved to learn its grant, so `svc` (client_credentials, never a login) on the
    // same down provider is reported too, at the same level — two lines for two registrations, nothing else.
    expect($this->logger->mentioning('[svc]'))->toHaveCount(1)
        ->and(array_column($this->logger->records, 'level'))->toBe(['warning', 'warning']);

    // Back up: no restart, no cache to clear — a failed discovery was never cached — and nothing more is logged.
    $this->idp->takeDiscoveryDown(false);
    $this->get('/login')->assertOk()->assertSee('Sign in with Fake IdP');
    expect($this->logger->records)->toHaveCount(2);
});

it('leaves out a provider whose discovery document is unusable — a misconfiguration, not an outage — with an ERROR rather than a warning, and lists it again once the document is right', function () {
    /** @var RedirectCapstoneTestCase $this */
    $this->idp->overrideDiscoveryDocument(['issuer' => 'http://localhost/another-idp']);

    $this->get('/login')->assertOk()->assertDontSee('Sign in with Fake IdP');

    $leftOut = $this->logger->mentioning('[fake]');
    $exception = $leftOut[0]['context']['exception'] ?? null;
    expect($leftOut)->toHaveCount(1)
        ->and($leftOut[0]['level'])->toBe('error')
        ->and($leftOut[0]['message'])->toContain('left out')->toContain('misconfiguration')->toContain('issuer differs')
        ->and($leftOut[0]['context']['registration'] ?? null)->toBe('fake')
        ->and($exception instanceof ProviderDiscoveryException && ! $exception->transient)->toBeTrue()
        ->and(array_column($this->logger->records, 'level'))->toBe(['error', 'error']);

    // A document that names no authorization_endpoint is the same permanent kind of wrong.
    $this->idp->overrideDiscoveryDocument(['authorization_endpoint' => null]);
    $this->get('/login')->assertOk()->assertDontSee('Sign in with Fake IdP');
    expect($this->logger->mentioning('[fake]'))->toHaveCount(2)
        ->and($this->logger->mentioning('[fake]')[1]['level'])->toBe('error')
        ->and($this->logger->mentioning('[fake]')[1]['message'])->toContain('authorization_endpoint');

    // ...and the start URL itself, asked directly, is the same 503 the outage gives: the redirect filter does not degrade.
    $this->getJson('/oauth2/authorization/fake')->assertStatus(503)->assertJson(['code' => 'OIDC_DISCOVERY_UNAVAILABLE']);

    $this->idp->overrideDiscoveryDocument([]);
    $this->get('/login')->assertOk()->assertSee('Sign in with Fake IdP');
    expect($this->logger->mentioning('[fake]'))->toHaveCount(2);
});
