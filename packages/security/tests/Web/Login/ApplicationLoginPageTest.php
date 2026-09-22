<?php

declare(strict_types=1);

use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Illuminate\Routing\Router;

/**
 * Form login on in an application that already answers GET /login itself (tests/Fixtures/OwnLogin): the page
 * is the application's, the framework mounts nothing over it, and the POST, the redirect and the saved
 * request work exactly as they do with the framework page.
 */
abstract class ApplicationLoginPageCapstoneTestCase extends SecurityCapstoneTestCase
{
    protected function securityOverrides(): array
    {
        return ['firefly.security.form_login.enabled' => true];
    }

    protected function fixturePaths(): array
    {
        return [
            ...parent::fixturePaths(),
            'Firefly\\Security\\Tests\\Fixtures\\OwnLogin\\' => dirname(__DIR__, 2).'/Fixtures/OwnLogin',
        ];
    }
}

uses(ApplicationLoginPageCapstoneTestCase::class);

it('leaves the application\'s own GET {login_page} route in place and mounts no framework page over it', function () {
    /** @var ApplicationLoginPageCapstoneTestCase $this */
    $this->get('/login')->assertOk()
        ->assertSee('Our own sign-in')
        ->assertDontSee('<form class="panel form"', escape: false);

    // Exactly one GET login route survives boot — the body above says whose — and the framework's name is absent.
    /** @var Router $router */
    $router = $this->app()->make('router');
    $routes = $router->getRoutes();
    $login = array_filter($routes->get('GET'), static fn ($route): bool => $route->uri() === 'login');

    expect($routes->hasNamedRoute('firefly.security.login'))->toBeFalse()
        ->and($login)->toHaveCount(1);
});

it('signs in through the application\'s page: the entry point sends the browser there, the filter answers its POST', function () {
    /** @var ApplicationLoginPageCapstoneTestCase $this */
    $refused = $this->get('/home');
    $refused->assertRedirect('/login');

    $this->forgetSession();
    $page = $this->followSession($refused)->get('/login');
    $page->assertOk()->assertSee('Our own sign-in');

    $this->forgetSession();
    $login = $this->followSession($page)->post('/login', ['username' => 'ada', 'password' => 'secret', '_token' => $this->csrfTokenFrom($page)]);
    $login->assertRedirect('/home');

    $this->forgetSession();
    $this->followSession($login)->get('/home')->assertOk()->assertSee('Signed in as ada');
});
