<?php

declare(strict_types=1);

use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logout through the real pipeline: form login on (which turns logout on with it), a named extra cookie to
 * expire, the POST the filter answers before routing, the CSRF check, the session invalidation the old cookie
 * can no longer survive, and the event that names who left.
 */
abstract class LogoutCapstoneTestCase extends SecurityCapstoneTestCase
{
    protected function securityOverrides(): array
    {
        return [
            'firefly.security.form_login.enabled' => true,
            'firefly.security.logout.delete_cookies' => ['theme'],
        ];
    }

    /**
     * Sign ada in through the real form and return the login response (its cookie is the live session).
     *
     * @return TestResponse<Response>
     */
    protected function signInAsAda(): TestResponse
    {
        $page = $this->get('/login');
        $this->forgetSession();

        return $this->followSession($page)->post('/login', ['username' => 'ada', 'password' => 'secret', '_token' => $this->csrfTokenFrom($page)]);
    }
}

uses(LogoutCapstoneTestCase::class, SecurityFlows::class);

it('ends the session on POST /logout, expires the named cookies, publishes the event, and lands on ?logout', function () {
    /** @var LogoutCapstoneTestCase $this */
    $login = $this->signInAsAda();
    $login->assertRedirect('/');

    $this->forgetSession();
    $home = $this->followSession($login)->get('/home');
    $home->assertOk();

    $this->forgetSession();
    $logout = $this->followSession($login)->withCookie('theme', 'dark')->post('/logout', ['_token' => $this->csrfTokenFrom($this->followSession($login)->get('/login'))]);
    $logout->assertRedirect('/login?logout');

    $theme = $logout->getCookie('theme');
    expect($theme)->not->toBeNull()
        ->and($theme?->getExpiresTime())->toBeLessThan(time())
        ->and($this->events->logouts())->toHaveCount(1)
        ->and($this->events->logouts()[0]->authentication?->getName())->toBe('ada');

    // The OLD session is gone: its cookie no longer authenticates anyone.
    $this->forgetSession();
    $this->followSession($login)->getJson('/whoami')->assertStatus(401);
    $this->followSession($login)->get('/home')->assertRedirect('/login');
});

it('ignores a GET and refuses a POST without the token', function () {
    /** @var LogoutCapstoneTestCase $this */
    $login = $this->signInAsAda();

    $this->forgetSession();
    $this->followSession($login)->get('/logout')->assertStatus(404);
    $this->followSession($login)->post('/logout')->assertStatus(403);

    $this->forgetSession();
    $this->followSession($login)->getJson('/whoami')->assertJson(['name' => 'ada']);
    expect($this->events->logouts())->toBe([]);
});
