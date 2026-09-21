<?php

declare(strict_types=1);

use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;

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
        return ['firefly.security.form_login.enabled' => true];
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
