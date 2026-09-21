<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Tests\Support\OAuth2ClientCapstoneTestCase;
use Firefly\Security\OAuth2\Client\Token\OAuth2AuthenticationException;
use Firefly\Security\Tests\Support\SecurityFlows;

/**
 * Every way a callback is refused, through the real pipeline: each one is a redirect to failure_url, ONE
 * failure event carrying the OAuth2AuthenticationException with its RFC code, no principal in the session,
 * and — for the state checks — nothing sent to the token endpoint at all.
 */
uses(OAuth2ClientCapstoneTestCase::class, SecurityFlows::class);

/**
 * The error code of the one failure the recorder holds, then the recorder is cleared for the next attempt.
 */
function oauth2FailureCode(OAuth2ClientCapstoneTestCase $test): string
{
    $failures = $test->events->failures();
    expect($failures)->toHaveCount(1);
    $exception = $failures[0]->exception;
    expect($failures[0]->username)->toBe('')
        ->and($failures[0]->ip)->toBe('127.0.0.1')
        ->and($exception)->toBeInstanceOf(OAuth2AuthenticationException::class);
    $test->events->reset();

    return $exception instanceof OAuth2AuthenticationException ? $exception->error->errorCode : 'not an OAuth2 refusal';
}

it('refuses a forged state, and the genuine one afterwards, because the request was single-use', function () {
    /** @var OAuth2ClientCapstoneTestCase $this */
    $start = $this->startLogin();
    $provider = $this->follow($start, $start);
    $location = (string) $provider->headers->get('Location');
    $forged = (string) preg_replace('/state=[^&]+/', 'state=forged-by-someone-else', $location);

    $this->forgetSession();
    $this->followSession($start)->get($forged)->assertRedirect('/login?error');
    expect(oauth2FailureCode($this))->toBe('invalid_state_parameter')
        ->and($this->idp->tokenRequests)->toBe([]);

    $this->forgetSession();
    $this->followSession($start)->get($location)->assertRedirect('/login?error');
    expect(oauth2FailureCode($this))->toBe('authorization_request_not_found');

    $this->forgetSession();
    $this->followSession($start)->getJson('/whoami')->assertStatus(401);
});

it('refuses a callback without an authorization request in the session, and one carrying the provider\'s error', function () {
    /** @var OAuth2ClientCapstoneTestCase $this */
    $this->get('/login/oauth2/code/fake?code=whatever&state=whatever')->assertRedirect('/login?error');
    expect(oauth2FailureCode($this))->toBe('authorization_request_not_found');

    $this->idp->refuseAuthorization('access_denied');
    $start = $this->startLogin();
    $provider = $this->follow($start, $start);
    expect($this->queryOf($provider)['error'] ?? null)->toBe('access_denied');
    $this->follow($start, $provider)->assertRedirect('/login?error');
    expect(oauth2FailureCode($this))->toBe('access_denied')
        ->and($this->idp->tokenRequests)->toBe([]);
});

it('refuses a wrong nonce, a foreign audience, a foreign issuer, a foreign signature and a refused exchange — each by its code', function () {
    /** @var OAuth2ClientCapstoneTestCase $this */
    $this->idp->overrideIdTokenClaims(['nonce' => 'not-the-one-sent']);
    $this->signInThroughProvider()->assertRedirect('/login?error');
    expect(oauth2FailureCode($this))->toBe('invalid_nonce');

    $this->idp->overrideIdTokenClaims(['aud' => 'another-app']);
    $this->signInThroughProvider()->assertRedirect('/login?error');
    expect(oauth2FailureCode($this))->toBe('invalid_id_token');

    $this->idp->overrideIdTokenClaims(['iss' => 'https://evil.example.test']);
    $this->signInThroughProvider()->assertRedirect('/login?error');
    expect(oauth2FailureCode($this))->toBe('invalid_id_token');

    $this->idp->overrideIdTokenClaims([])->signWithUnknownKey();
    $this->signInThroughProvider()->assertRedirect('/login?error');
    expect(oauth2FailureCode($this))->toBe('invalid_id_token');

    $this->idp->signWithUnknownKey(false)->refuseToken('invalid_grant', 'the code is stale');
    $this->signInThroughProvider()->assertRedirect('/login?error');
    expect(oauth2FailureCode($this))->toBe('invalid_grant');

    $this->idp->refuseToken('');
    $this->signInThroughProvider()->assertRedirect('/');
    expect($this->events->failures())->toBe([])
        ->and($this->events->interactive())->toHaveCount(1);
});

it('shows the provider sentence on the login page after a failure, not the password one', function () {
    /** @var OAuth2ClientCapstoneTestCase $this */
    $page = $this->get('/login?error');

    $page->assertOk()
        ->assertSee('Signing in with the provider did not work')
        ->assertDontSee('Check the username and the password')
        ->assertSee('Sign in with Fake IdP');
});
