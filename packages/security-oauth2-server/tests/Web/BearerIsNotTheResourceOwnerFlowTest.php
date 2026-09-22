<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerCapstoneTestCase;
use Firefly\Security\OAuth2\Server\Web\Consent\SessionAuthenticationTime;
use Firefly\Security\Session\SessionSecurityContextRepository;

uses(OAuth2ServerCapstoneTestCase::class);

/**
 * THE RESOURCE OWNER IS A BROWSER WITH A SESSION, never whatever principal happens to be in SecurityContextHolder
 * when the authorization endpoint runs at -82. The capstone is the shape this package documents — the server is
 * the resource server for its own keys — so OAuth2ResourceServerFilter (-85) authenticates any bearer it can
 * verify, including the access tokens this very server issued, three filters before the endpoint looks.
 *
 * Were the endpoint to take that principal, an access token would redeem itself for new ones: a stolen or
 * narrowly scoped token presented at /oauth2/authorize would come back with a code for any client that needs no
 * consent or was consented to once, exchanged for a WIDER scope, a refresh token and an id token whose
 * `auth_time` was stamped on the cookie-less session the bearer's own request had just opened — so any `max_age`
 * would be satisfied by an authentication that never happened.
 */
it('never lets a bearer be the resource owner: an access token alone at the authorization endpoint is anonymous', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    // 1. A real browser sign-in ten minutes ago, and the narrow token it ended up with: scope `openid` only.
    $this->signIn();
    $session = $this->sessionStore();
    $session->put(SessionAuthenticationTime::KEY, time() - 600);
    $session->save();

    $oauth2 = $this->oauth2();
    $tokens = $oauth2->tokens('public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid');
    /** @var string $accessToken */
    $accessToken = $tokens['access_token'];

    // 2. That bearer ALONE, as a thief would send it — no session, no cookie — asking for a wider scope and for a
    //    sign-in no older than a second: the login page, not a code.
    $this->forgetSession();
    $this->forgetCookies()->withHeader('Authorization', 'Bearer '.$accessToken);
    $stolen = $oauth2->authorize('public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid profile', ['max_age' => '1']);
    $stolen->assertRedirect('http://localhost/login');
    expect((string) $stolen->headers->get('Location'))->not->toContain('code=');

    // 3. And with prompt=none, where there is no page to send it to: login_required, the answer for an anonymous
    //    request — never a code, and never consent_required.
    $none = $oauth2->authorize('public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid profile', ['prompt' => 'none']);
    $none->assertStatus(302);
    expect((string) $none->headers->get('Location'))->toStartWith(OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI.'?')
        ->toContain('error=login_required')
        ->not->toContain('code=');

    // 4. The token is refused HERE and nowhere else: it still authenticates the API call it was minted for.
    $this->getJson('/api/profile')->assertOk()->assertJson(['sub' => 'ada', 'authenticated' => true]);
    $this->flushHeaders();
});

it('never lets a bearer approve the consent a browser left pending', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    $this->signIn();
    $oauth2 = $this->oauth2();
    $tokens = $oauth2->tokens('public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid');
    /** @var string $accessToken */
    $accessToken = $tokens['access_token'];

    // The browser reaches the consent page for another client, so the session holds the pending request...
    $page = $oauth2->authorize('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid profile');
    $page->assertOk();

    // ...and then stops being signed in — a logout in another tab, an expired context — while the session, its
    // CSRF token and the pending consent are all still there. The POST arrives with the bearer in its place.
    $session = $this->sessionStore();
    $session->forget(SessionSecurityContextRepository::KEY);
    $session->save();

    preg_match('/name="state" value="([^"]+)"/', (string) $page->getContent(), $state);
    $denied = $this->withHeader('Authorization', 'Bearer '.$accessToken)->post('/oauth2/authorize', [
        '_token' => $this->csrfTokenFrom($page),
        'state' => $state[1] ?? '',
        'scope' => ['openid', 'profile'],
        'action' => 'approve',
    ]);
    $this->flushHeaders();

    $denied->assertStatus(400);
    expect((string) $denied->headers->get('Location'))->not->toContain('code=');
});
