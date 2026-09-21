<?php

declare(strict_types=1);

use Firefly\Security\Event\InteractiveAuthenticationSuccessEvent;
use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remember-me through the real pipeline: the checkbox the login page offers, the signed cookie the form-login
 * filter sets only when it is ticked, the re-authentication the remember-me filter performs once the session
 * behind the cookie is gone, and the expiry logout puts on the cookie.
 */
abstract class RememberMeCapstoneTestCase extends SecurityCapstoneTestCase
{
    protected function securityOverrides(): array
    {
        return [
            'firefly.security.form_login.enabled' => true,
            'firefly.security.remember_me.enabled' => true,
            'firefly.security.remember_me.key' => str_repeat('remember-', 5),
        ];
    }

    /**
     * Destroy the server-side session behind a response's cookie, as an expiry or a restart would.
     *
     * @param  TestResponse<Response>  $response
     */
    public function destroySessionOf(TestResponse $response): void
    {
        $id = (string) $response->getCookie($this->sessionCookieName())?->getValue();
        /** @var SessionManager $manager */
        $manager = $this->app()->make('session');
        /** @var Store $store */
        $store = $manager->driver();
        $store->getHandler()->destroy($id);
        $this->forgetSession();
    }
}

uses(RememberMeCapstoneTestCase::class, SecurityFlows::class);

it('offers the checkbox, sets the cookie only when it is ticked, and re-authenticates once the session is gone', function () {
    /** @var RememberMeCapstoneTestCase $this */
    $page = $this->get('/login');
    $page->assertSee('type="checkbox" name="remember-me"', escape: false);

    // Not ticked: no remember-me cookie.
    $this->forgetSession();
    $plain = $this->followSession($page)->post('/login', ['username' => 'ada', 'password' => 'secret', '_token' => $this->csrfTokenFrom($page)]);
    $plain->assertRedirect('/');
    expect($plain->getCookie('remember-me'))->toBeNull();

    // Ticked: the signed cookie rides along.
    $this->forgetSession();
    $again = $this->followSession($plain)->get('/login');
    $remembered = $this->followSession($again)->post('/login', ['username' => 'ada', 'password' => 'secret', '_token' => $this->csrfTokenFrom($again), 'remember-me' => '1']);
    $remembered->assertRedirect('/');
    $token = (string) $remembered->getCookie('remember-me')?->getValue();
    expect($token)->not->toBe('');

    // The session is gone; the cookie ALONE signs ada back in — through the remember-me mechanism.
    $this->destroySessionOf($remembered);
    $this->forgetCookies();
    $this->events->reset();
    $back = $this->withCookie('remember-me', $token)->get('/home');
    $back->assertOk()->assertSee('Signed in as ada');

    expect($this->events->interactive())->toHaveCount(1)
        ->and($this->events->interactive()[0]->mechanism)->toBe(InteractiveAuthenticationSuccessEvent::REMEMBER_ME);

    // And the NEW session it opened carries ada from now on, without the cookie.
    $this->forgetSession();
    $this->followSession($back)->getJson('/whoami')->assertJson(['name' => 'ada']);

    // A tampered cookie is anonymous, not an error.
    $this->forgetSession();
    $this->forgetCookies();
    $this->withCookie('remember-me', strrev($token))->get('/home')->assertRedirect('/login');
});

it('clears the remember-me cookie on logout', function () {
    /** @var RememberMeCapstoneTestCase $this */
    $page = $this->get('/login');
    $this->forgetSession();
    $login = $this->followSession($page)->post('/login', ['username' => 'ada', 'password' => 'secret', '_token' => $this->csrfTokenFrom($page), 'remember-me' => '1']);
    $token = (string) $login->getCookie('remember-me')?->getValue();

    $this->forgetSession();
    $form = $this->followSession($login)->get('/login');
    $logout = $this->followSession($login)->withCookie('remember-me', $token)->post('/logout', ['_token' => $this->csrfTokenFrom($form)]);
    $logout->assertRedirect('/login?logout');

    expect($logout->getCookie('remember-me')?->getExpiresTime())->toBeLessThan(time());
});
