<?php

declare(strict_types=1);

use Firefly\Security\Session\SecurityContextRepository;
use Firefly\Security\Tests\Fixtures\LogoutContext\CountingSecurityContextRepository;
use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;
use Firefly\Security\Web\Logout\LogoutHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SecurityContextRepository IS A PORT — its own docblock offers "a signed cookie, a cache keyed by a device
 * id" — and signing out has to reach the BOUND one, not only the session. This capstone scans a fixture
 * configuration that binds one, exactly as an application supplies its own, and the flows below count what that
 * store hears from LogoutHandler.
 *
 * The second case is the one the shipped store hides: a request with NO session, which is what a bearer-only
 * application (form login off, nothing that starts a session) hands the handler. SessionSecurityContextRepository
 * is a no-op there — so a handler that skipped the port whenever `hasSession()` was false looked correct and
 * left a custom store's principal in place for ever, on both POST {logout_url} and RP-initiated logout.
 */
abstract class LogoutContextRepositoryCapstoneTestCase extends SecurityCapstoneTestCase
{
    protected function fixturePaths(): array
    {
        return parent::fixturePaths() + ['Firefly\\Security\\Tests\\Fixtures\\LogoutContext\\' => dirname(__DIR__, 2).'/Fixtures/LogoutContext'];
    }

    protected function securityOverrides(): array
    {
        return ['firefly.security.form_login.enabled' => true];
    }

    protected function setUp(): void
    {
        parent::setUp();

        CountingSecurityContextRepository::reset();
    }
}

uses(LogoutContextRepositoryCapstoneTestCase::class, SecurityFlows::class);

it('binds the application\'s own repository and asks IT to clear on POST /logout, although the session was invalidated as well', function () {
    /** @var LogoutContextRepositoryCapstoneTestCase $this */
    expect($this->app()->make(SecurityContextRepository::class))->toBeInstanceOf(CountingSecurityContextRepository::class);

    $page = $this->get('/login');
    $this->forgetSession();
    $login = $this->followSession($page)->post('/login', ['username' => 'ada', 'password' => 'secret', '_token' => $this->csrfTokenFrom($page)]);
    $login->assertRedirect('/');

    $this->forgetSession();
    $form = $this->followSession($login)->get('/login');
    CountingSecurityContextRepository::reset();

    $this->forgetSession();
    $this->followSession($login)->post('/logout', ['_token' => $this->csrfTokenFrom($form)])->assertRedirect('/login?logout');

    expect(CountingSecurityContextRepository::shared()->cleared)->toBe(1)
        ->and($this->events->logouts())->toHaveCount(1);
});

it('clears the bound repository for a request that carries no session at all — the bearer-only application the port exists for', function () {
    /** @var LogoutContextRepositoryCapstoneTestCase $this */
    /** @var LogoutHandler $handler */
    $handler = $this->app()->make(LogoutHandler::class);
    $request = Request::create('/logout', 'POST');

    expect($request->hasSession())->toBeFalse();

    $handler->logout($request, new Response, null);

    expect(CountingSecurityContextRepository::shared()->cleared)->toBe(1);
});
