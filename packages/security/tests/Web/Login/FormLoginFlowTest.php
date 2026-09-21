<?php

declare(strict_types=1);

use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Event\AuthenticationFailureBadCredentialsEvent;
use Firefly\Security\Event\AuthenticationFailureLockedEvent;
use Firefly\Security\Event\InteractiveAuthenticationSuccessEvent;
use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;

/**
 * Form login on, through the real pipeline: the login page route, the POST the filter answers before routing,
 * the session CSRF check, the fixation-protected sign-in, the saved request, and the event family each step
 * publishes. The capstone's users: ada/secret (ROLE_USER), root/secret (ROLE_ADMIN), lock/secret (locked).
 */
abstract class FormLoginCapstoneTestCase extends SecurityCapstoneTestCase
{
    protected function securityOverrides(): array
    {
        return ['firefly.security.form_login.enabled' => true];
    }
}

uses(FormLoginCapstoneTestCase::class, SecurityFlows::class);

it('renders the login page to anyone, with the session token, and marks the error and logout states', function () {
    /** @var FormLoginCapstoneTestCase $this */
    $page = $this->get('/login');

    $page->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('Sign in')
        ->assertSee('name="_token"', escape: false)
        ->assertDontSee('Those credentials did not work.');

    expect(strlen($this->csrfTokenFrom($page)))->toBe(40);

    $this->get('/login?error')->assertSee('Those credentials did not work.');
    $this->get('/login?logout')->assertSee('You have signed out.');
});

it('redirects a wrong password to ?error, publishes the failure with the username and ip, and signs nobody in', function () {
    /** @var FormLoginCapstoneTestCase $this */
    $page = $this->get('/login');
    $token = $this->csrfTokenFrom($page);

    $failed = $this->followSession($page)->post('/login', ['username' => 'ada', 'password' => 'nope', '_token' => $token]);
    $failed->assertRedirect('/login?error');

    expect($this->events->failures())->toHaveCount(1)
        ->and($this->events->failures()[0])->toBeInstanceOf(AuthenticationFailureBadCredentialsEvent::class)
        ->and($this->events->failures()[0]->username)->toBe('ada')
        ->and($this->events->failures()[0]->ip)->toBe('127.0.0.1')
        ->and($this->events->successes())->toBe([]);

    $this->forgetSession();
    $this->followSession($failed)->getJson('/whoami')->assertStatus(401);
});

it('reports an unknown user exactly like a wrong password, and a locked account as locked only after the right password', function () {
    /** @var FormLoginCapstoneTestCase $this */
    $page = $this->get('/login');
    $token = $this->csrfTokenFrom($page);

    $this->followSession($page)->post('/login', ['username' => 'nobody', 'password' => 'nope', '_token' => $token])->assertRedirect('/login?error');
    $this->followSession($page)->post('/login', ['username' => 'lock', 'password' => 'nope', '_token' => $token])->assertRedirect('/login?error');
    $this->followSession($page)->post('/login', ['username' => 'lock', 'password' => 'secret', '_token' => $token])->assertRedirect('/login?error');

    expect($this->events->failures())->toHaveCount(3)
        ->and($this->events->failures()[0])->toBeInstanceOf(AuthenticationFailureBadCredentialsEvent::class)
        ->and($this->events->failures()[1])->toBeInstanceOf(AuthenticationFailureBadCredentialsEvent::class)
        ->and($this->events->failures()[2])->toBeInstanceOf(AuthenticationFailureLockedEvent::class);
});

it('refuses a login POST without the session token', function () {
    /** @var FormLoginCapstoneTestCase $this */
    $page = $this->get('/login');

    $this->followSession($page)->post('/login', ['username' => 'ada', 'password' => 'secret'])->assertStatus(403);
    $this->followSession($page)->post('/login', ['username' => 'ada', 'password' => 'secret', '_token' => 'forged'])->assertStatus(403);

    expect($this->events->successes())->toBe([]);
});

it('signs in with the right password: a NEW session id, the context in the session, the saved request honoured, the page then accessible', function () {
    /** @var FormLoginCapstoneTestCase $this */
    // 1. Refused at /home → redirected to /login with /home saved.
    $refused = $this->get('/home');
    $refused->assertRedirect('/login');

    // 2. The login page, in that same session.
    $this->forgetSession();
    $page = $this->followSession($refused)->get('/login');
    $page->assertOk();
    $before = (string) $page->getCookie($this->sessionCookieName())?->getValue();

    // 3. The POST: right password → 302 to the SAVED request, with a regenerated session id.
    $this->forgetSession();
    $login = $this->followSession($page)->post('/login', ['username' => 'ada', 'password' => 'secret', '_token' => $this->csrfTokenFrom($page)]);
    $login->assertRedirect('/home');
    $after = (string) $login->getCookie($this->sessionCookieName())?->getValue();

    expect($after)->not->toBe($before)
        ->and($this->events->interactive())->toHaveCount(1)
        ->and($this->events->interactive()[0]->mechanism)->toBe(InteractiveAuthenticationSuccessEvent::FORM)
        ->and($this->events->interactive()[0]->authentication->getName())->toBe('ada')
        ->and($this->events->successes())->toHaveCount(1)
        ->and(SecurityContextHolder::getContext()->isAuthenticated())->toBeFalse();

    // 4. The protected page, from the session alone.
    $this->forgetSession();
    $this->followSession($login)->get('/home')->assertOk()->assertSee('Signed in as ada');
    $this->followSession($login)->getJson('/whoami')->assertJson(['name' => 'ada', 'authorities' => ['ROLE_USER']]);

    // 5. Nothing was saved to come back to any more: a second login lands on the default URL.
    $this->forgetSession();
    $again = $this->followSession($login)->get('/login');
    $this->followSession($again)->post('/login', ['username' => 'root', 'password' => 'secret', '_token' => $this->csrfTokenFrom($again)])->assertRedirect('/');
});
