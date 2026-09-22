<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Tests\Support\OAuth2ClientCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;
use Firefly\Testing\Security\OAuth2\FakeAuthorizationServer;

/**
 * RP-initiated logout through the real pipeline: the logout POST (CSRF-checked by the LogoutFilter) becomes a
 * redirect to the provider's end-session endpoint carrying the id token the session held, the session is
 * gone afterwards, the provider sends the browser back to the post-logout URI, and the login page shows the
 * signed-out notice — with form login OFF, logout exists because OAuth2 login implies it.
 */
abstract class OidcClientLogoutCapstoneTestCase extends OAuth2ClientCapstoneTestCase
{
    protected function clientOverrides(): array
    {
        return ['firefly.security.oauth2.client.logout.oidc_initiated' => true];
    }
}

uses(OidcClientLogoutCapstoneTestCase::class, SecurityFlows::class);

it('signs out at the provider too, then lands on the signed-out login page with no session left', function () {
    /** @var OidcClientLogoutCapstoneTestCase $this */
    $callback = $this->signInThroughProvider();
    $token = $this->csrfTokenOf($callback);

    $this->forgetSession();
    $logout = $this->followSession($callback)->post('/logout', ['_token' => $token]);

    $logout->assertRedirect();
    $location = (string) $logout->headers->get('Location');
    expect($location)->toStartWith(OAuth2ClientCapstoneTestCase::ISSUER.'/end-session?')
        ->and($this->queryOf($logout))->toBe([
            'id_token_hint' => $this->idp->issuedIdTokens[0],
            'client_id' => FakeAuthorizationServer::CLIENT_ID,
            'post_logout_redirect_uri' => 'http://localhost/login?logout',
        ])
        ->and($this->events->logouts())->toHaveCount(1)
        ->and($this->events->logouts()[0]->authentication?->getName())->toBe('ada');

    // The provider's front channel (a real route) sends the browser back; the notice is the login page's.
    $this->forgetSession();
    $back = $this->followSession($logout)->get($location);
    $back->assertRedirect('http://localhost/login?logout');
    expect($this->idp->endSessionRequests[0]['id_token_hint'] ?? null)->toBe($this->idp->issuedIdTokens[0]);

    $this->forgetSession();
    $this->followSession($logout)->get('/login?logout')->assertOk()->assertSee('You have signed out.')->assertSee('Sign in with Fake IdP');
    $this->followSession($logout)->getJson('/whoami')->assertStatus(401);
});

it('is a plain logout without the CSRF token — the provider is never asked', function () {
    /** @var OidcClientLogoutCapstoneTestCase $this */
    $callback = $this->signInThroughProvider();
    $this->forgetSession();

    $this->followSession($callback)->post('/logout')->assertStatus(403);
    expect($this->idp->endSessionRequests)->toBe([])
        ->and($this->events->logouts())->toBe([]);
});
