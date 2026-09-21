<?php

declare(strict_types=1);

use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;

/**
 * `firefly.security.oauth2.client.login.enabled` ALONE, through the real pipeline and with nothing but the
 * security core booted: the login page is mounted without the password form (no LoginPageLinks is bound
 * either, so it lists nothing — the providers are firefly/security-oauth2-client's to add), a browser refused
 * at a protected page is sent to it, the session middleware is on the stack (the page answers with the
 * session cookie) and the logout filter answers POST /logout — a 403 for a token-less POST, where an
 * unmounted logout would leave it to the URL rules (a 302 or a 401, never a 403). FormLoginFilter stays off:
 * the form POST is nobody's and meets the URL rules like any other request.
 */
abstract class OAuth2LoginOnlyCapstoneTestCase extends SecurityCapstoneTestCase
{
    protected function securityOverrides(): array
    {
        return ['firefly.security.oauth2.client.login.enabled' => true];
    }
}

uses(OAuth2LoginOnlyCapstoneTestCase::class, SecurityFlows::class);

it('mounts a form-less login page, sends a browser to it, and turns the session and logout on', function () {
    /** @var OAuth2LoginOnlyCapstoneTestCase $this */
    $this->get('/home', ['Accept' => 'text/html'])->assertRedirect('http://localhost/login');

    $page = $this->get('/login');
    $page->assertOk();

    expect((string) $page->getContent())->toContain('<h1>Sign in</h1>')
        ->not->toContain('<form')
        ->not->toContain('providers')
        ->and($page->getCookie($this->sessionCookieName()))->not->toBeNull();

    $this->post('/login', ['username' => 'ada', 'password' => 'secret'], ['Accept' => 'application/json'])->assertStatus(401);
    $this->post('/logout')->assertStatus(403);
});
