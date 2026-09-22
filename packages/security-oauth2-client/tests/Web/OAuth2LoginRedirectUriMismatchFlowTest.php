<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Tests\Support\OAuth2ClientCapstoneTestCase;
use Firefly\Security\OAuth2\Client\Token\OAuth2AuthenticationException;
use Firefly\Security\Tests\Support\SecurityFlows;

/**
 * The redirect_uri is compared EXACTLY: a registration whose redirect_uri names another host sends the
 * provider back there, and a callback that arrives at this host instead — a code delivered to the wrong
 * place — is refused before any exchange.
 */
abstract class RedirectUriMismatchCapstoneTestCase extends OAuth2ClientCapstoneTestCase
{
    protected function registrationOverrides(): array
    {
        return ['redirect_uri' => 'https://app.example.com/login/oauth2/code/{registrationId}'];
    }
}

uses(RedirectUriMismatchCapstoneTestCase::class, SecurityFlows::class);

it('refuses a callback whose URL is not the redirect_uri the request was made with', function () {
    /** @var RedirectUriMismatchCapstoneTestCase $this */
    $start = $this->startLogin();
    expect($this->queryOf($start)['redirect_uri'])->toBe('https://app.example.com/login/oauth2/code/fake');

    $provider = $this->follow($start, $start);
    $provider->assertRedirect();
    expect((string) $provider->headers->get('Location'))->toStartWith('https://app.example.com/login/oauth2/code/fake?code=');

    $this->forgetSession();
    $this->followSession($start)->get('http://localhost/login/oauth2/code/fake?'.http_build_query($this->queryOf($provider)))->assertRedirect('/login?error');

    $exception = ($this->events->failures()[0] ?? null)?->exception;
    expect($exception)->toBeInstanceOf(OAuth2AuthenticationException::class)
        ->and($exception instanceof OAuth2AuthenticationException ? $exception->error->errorCode : null)->toBe('invalid_redirect_uri')
        ->and($this->idp->tokenRequests)->toBe([]);
});
