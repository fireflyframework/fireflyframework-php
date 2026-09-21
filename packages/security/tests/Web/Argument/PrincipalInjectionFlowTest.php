<?php

declare(strict_types=1);

use Firefly\Security\Jwt\JwtService;
use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;

/**
 * Principal injection through the real pipeline, with form login (a UserDetails principal) and the local-JWT
 * filter (a bare `sub` string principal) both on, so every declared parameter shape is fed both kinds.
 */
abstract class PrincipalCapstoneTestCase extends SecurityCapstoneTestCase
{
    protected function fixturePaths(): array
    {
        return [
            ...parent::fixturePaths(),
            'Firefly\\Security\\Tests\\Fixtures\\Principal\\' => dirname(__DIR__, 2).'/Fixtures/Principal',
        ];
    }

    protected function securityOverrides(): array
    {
        return [
            'firefly.security.form_login.enabled' => true,
            'firefly.security.jwt.enabled' => true,
            'firefly.security.jwt.secret' => str_repeat('s', 40),
        ];
    }

    protected function bearerFor(string $subject): string
    {
        /** @var JwtService $jwt */
        $jwt = $this->app()->make(JwtService::class);

        return 'Bearer '.$jwt->encode(['sub' => $subject, 'authorities' => ['ROLE_USER']], 3600);
    }
}

uses(PrincipalCapstoneTestCase::class, SecurityFlows::class);

it('injects the authentication, the user, the principal and the context into a controller action', function () {
    /** @var PrincipalCapstoneTestCase $this */
    $page = $this->get('/login');
    $this->forgetSession();
    $login = $this->followSession($page)->post('/login', ['username' => 'ada', 'password' => 'secret', '_token' => $this->csrfTokenFrom($page)]);

    $this->forgetSession();
    $this->followSession($login)->getJson('/profile')
        ->assertOk()
        ->assertJson([
            'name' => 'ada',
            'authorities' => ['ROLE_USER'],
            'user' => 'ada',
            'principal' => 'details:ada',
            'authenticated' => true,
        ]);
});

it('hands null to a nullable parameter when nobody is signed in', function () {
    /** @var PrincipalCapstoneTestCase $this */
    $this->getJson('/open/greeting')->assertOk()->assertJson(['who' => 'stranger']);
});

it('hands a JWT subject to a nullable scalar principal, and null to it when nobody is signed in', function () {
    /** @var PrincipalCapstoneTestCase $this */
    // A `?string` principal is the natural declaration for a JWT `sub`; the scanner plans a scalar as a query
    // parameter, and the resolver must still see that it allows null rather than answer a 401.
    $this->getJson('/open/sub')->assertOk()->assertJson(['sub' => null]);
    $this->withHeader('Authorization', $this->bearerFor('svc-42'))->getJson('/open/sub')->assertOk()->assertJson(['sub' => 'svc-42']);
    $this->withHeader('Authorization', $this->bearerFor('svc-42'))->getJson('/open/whoami')->assertOk()->assertJson(['principal' => 'svc-42']);
});

it('hands an attributed principal over only when it fits the parameter, whichever mechanism signed the request in', function () {
    /** @var PrincipalCapstoneTestCase $this */
    // A JWT's principal is its `sub` string, not a UserDetails: `#[AuthenticationPrincipal] ?UserDetails` is the
    // null it allowed for, not the string a `?UserDetails` parameter would refuse with a TypeError (a 500).
    $this->withHeader('Authorization', $this->bearerFor('svc-42'))->getJson('/open/principal-user')->assertOk()->assertJson(['user' => null]);

    // A form login's principal is the User: the same parameter receives it, and the `?string $sub` that took
    // the JWT subject is null for it — the mirror image of the case above, through the same session. The
    // bearer header is dropped first: withHeader() persists for the whole test, and a request carrying both
    // would be the JWT filter's, not the session's.
    $this->flushHeaders();
    $page = $this->get('/login');
    $this->forgetSession();
    $login = $this->followSession($page)->post('/login', ['username' => 'ada', 'password' => 'secret', '_token' => $this->csrfTokenFrom($page)]);

    $this->forgetSession();
    $this->followSession($login)->getJson('/open/principal-user')->assertOk()->assertJson(['user' => 'ada']);
    $this->forgetSession();
    $this->followSession($login)->getJson('/open/sub')->assertOk()->assertJson(['sub' => null]);
    $this->forgetSession();
    $this->followSession($login)->getJson('/open/whoami')->assertOk()->assertJson(['principal' => 'details:ada']);
});

it('never reads a principal from the query string, whoever asks', function () {
    /** @var PrincipalCapstoneTestCase $this */
    $this->getJson('/open/whoami?principal=admin')->assertOk()->assertJson(['principal' => null]);
    $this->getJson('/open/sub?sub=admin')->assertOk()->assertJson(['sub' => null]);
});
