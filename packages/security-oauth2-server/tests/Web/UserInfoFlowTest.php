<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerCapstoneTestCase;

uses(OAuth2ServerCapstoneTestCase::class);

it('answers the claims the scopes allow for a JWT bearer, and 401/403 with the RFC 6750 challenge otherwise', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    $this->signIn();
    $oauth2 = $this->oauth2();
    $tokens = $oauth2->tokens('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid profile email', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET);
    /** @var string $accessToken */
    $accessToken = $tokens['access_token'];
    /** @var string $idToken */
    $idToken = $tokens['id_token'];

    $oauth2->userInfo($accessToken)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['sub' => 'ada', 'name' => 'ada', 'preferred_username' => 'ada']);

    $narrow = $oauth2->tokens('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET);
    /** @var string $narrowAccess */
    $narrowAccess = $narrow['access_token'];
    $oauth2->userInfo($narrowAccess)->assertOk()->assertExactJson(['sub' => 'ada']);

    /** @var string $noOpenId */
    $noOpenId = $oauth2->clientCredentials('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, 'orders:read')->json('access_token');
    $oauth2->userInfo($noOpenId)
        ->assertStatus(403)
        ->assertHeader('WWW-Authenticate', 'Bearer realm="oauth2", error="insufficient_scope", error_description="The access token has no openid scope.", scope="openid"')
        ->assertJson(['error' => 'insufficient_scope']);

    $this->getJson('/userinfo')
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Bearer realm="oauth2"')
        ->assertJson(['error' => 'invalid_token']);

    // A bearer that is not one of this server's tokens at all is refused: it is in no authorization.
    $oauth2->userInfo('not-a-token')->assertStatus(401)->assertJson(['error' => 'invalid_token']);
    // An id token IS a JWT this server signed, so it verifies — and the endpoint still refuses it, because the
    // hash is in the id-token slot and never in the access-token one.
    $oauth2->userInfo($idToken)->assertStatus(401)->assertJson(['error' => 'invalid_token']);
});

it('stops answering for a revoked token, and answers a POST as well as a GET', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    $this->signIn();
    $oauth2 = $this->oauth2();
    $tokens = $oauth2->tokens('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET);
    /** @var string $accessToken */
    $accessToken = $tokens['access_token'];

    $this->withHeader('Authorization', 'Bearer '.$accessToken)->postJson('/userinfo')->assertOk()->assertJson(['sub' => 'ada']);
    $this->flushHeaders();

    $oauth2->revoke('web-app', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET, $accessToken)->assertOk();
    $oauth2->userInfo($accessToken)->assertStatus(401)->assertJson(['error' => 'invalid_token']);
});

it('refuses a bearer the resource-server filter of the same application cannot verify, before the endpoint sees it', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    // No session: nothing has authenticated the request, so OAuth2ResourceServerFilter (-85) examines the
    // bearer and answers its own 401 problem document — the documented interaction of the two.
    //
    // WHICH 401 IS THE WHOLE POINT, so the assertions are the ones only the FILTER's answer satisfies. Both
    // candidates refuse `not.a.jwt` with a 401: the endpoint's BearerToken::challenge() renders
    // {"error":"invalid_token",…} under a `WWW-Authenticate: Bearer realm="oauth2"…` header, while the filter
    // raises InvalidTokenException and the exception translation renders the problem document (INVALID_TOKEN,
    // pinned by packages/kernel/tests/Exception/ExceptionTaxonomyTest.php) with no challenge header at all. On
    // `assertStatus(401)` alone this case would still pass with the filter skipped or reordered, which is
    // exactly the interaction it exists to pin.
    $this->forgetCookies()->withHeader('Authorization', 'Bearer not.a.jwt')->getJson('/userinfo')
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertHeaderMissing('WWW-Authenticate')
        ->assertJsonPath('code', 'INVALID_TOKEN')
        ->assertJsonMissingPath('error');
    $this->flushHeaders();
});

it('refuses a bearer that was never issued to an end user: client credentials with openid is not a userinfo token', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    // `web-app` registers client_credentials AND the openid scope, so this token carries `openid` and its
    // principal is the CLIENT id. OIDC Core §5.3 answers claims about the authenticated End-User; there is none
    // here, and a 200 would hand the relying party `sub` = "web-app" — a client id where it expects a user.
    $oauth2 = $this->oauth2();
    /** @var string $clientToken */
    $clientToken = $oauth2->clientCredentials('web-app', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET, 'openid')->json('access_token');

    $oauth2->userInfo($clientToken)
        ->assertStatus(401)
        ->assertJson(['error' => 'invalid_token', 'error_description' => 'The access token was not issued to an end user.'])
        ->assertHeader('WWW-Authenticate', 'Bearer realm="oauth2", error="invalid_token", error_description="The access token was not issued to an end user."');

    // The same client's AUTHORIZATION-CODE token, with the same scope, is still answered: the refusal is about
    // the grant, not the client.
    $this->signIn();
    $tokens = $oauth2->tokens('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET);
    /** @var string $userToken */
    $userToken = $tokens['access_token'];
    $oauth2->userInfo($userToken)->assertOk()->assertExactJson(['sub' => 'ada']);
});
