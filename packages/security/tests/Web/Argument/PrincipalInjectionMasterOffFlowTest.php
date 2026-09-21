<?php

declare(strict_types=1);

use Firefly\Security\Jwt\JwtService;
use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;

/**
 * The same fixtures with the master flag at its documented safe default (firefly.security.enabled=false):
 * the injection annotations must be INERT — null, the anonymous context, or an honest 401 for a parameter
 * that cannot take null — and never a fallback that reads the "principal" from the query string or a 500
 * from the container trying to build a token. A parameter the controller declared as the signed-in principal
 * is not attacker-controlled input while the feature is off.
 *
 * The local-JWT filter is on as well, because it is documented as independent of the master flag: a bearer
 * it verifies is a principal in the holder, and the annotations answer what the holder holds — the resolver
 * follows the holder, not the flag.
 */
abstract class PrincipalMasterOffCapstoneTestCase extends SecurityCapstoneTestCase
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
            'firefly.security.enabled' => false,
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

uses(PrincipalMasterOffCapstoneTestCase::class, SecurityFlows::class);

it('hands null to a #[AuthenticationPrincipal] parameter and never reads it from the query string while security is off', function () {
    /** @var PrincipalMasterOffCapstoneTestCase $this */
    $this->getJson('/open/whoami')->assertOk()->assertJson(['principal' => null]);
    $this->getJson('/open/whoami?principal=admin')->assertOk()->assertJson(['principal' => null]);
    $this->getJson('/open/sub')->assertOk()->assertJson(['sub' => null]);
    $this->getJson('/open/sub?sub=admin')->assertOk()->assertJson(['sub' => null]);
});

it('hands the anonymous context to #[CurrentSecurityContext] and null to a nullable user or token while security is off', function () {
    /** @var PrincipalMasterOffCapstoneTestCase $this */
    $this->getJson('/open/context')->assertOk()->assertJson(['authenticated' => false, 'name' => null]);
    $this->getJson('/open/user')->assertOk()->assertJson(['user' => null]);
    $this->getJson('/open/greeting')->assertOk()->assertJson(['who' => 'stranger']);
});

it('answers 401, not 500, for a non-nullable Authentication parameter while security is off', function () {
    /** @var PrincipalMasterOffCapstoneTestCase $this */
    $this->getJson('/open/required')
        ->assertStatus(401)
        ->assertJson(['code' => 'AUTHENTICATION_FAILED']);
});

it('injects the principal a master-independent bearer filter established, master flag or not', function () {
    /** @var PrincipalMasterOffCapstoneTestCase $this */
    $bearer = $this->bearerFor('svc-42');

    $this->withHeader('Authorization', $bearer)->getJson('/open/sub')->assertOk()->assertJson(['sub' => 'svc-42']);
    $this->withHeader('Authorization', $bearer)->getJson('/open/whoami?principal=admin')->assertOk()->assertJson(['principal' => 'svc-42']);
    $this->withHeader('Authorization', $bearer)->getJson('/open/context')->assertOk()->assertJson(['authenticated' => true, 'name' => 'svc-42']);
    $this->withHeader('Authorization', $bearer)->getJson('/open/required')->assertOk()->assertJson(['name' => 'svc-42']);
    // A bare `sub` is not a UserDetails: the typed, nullable user is null even though someone is signed in.
    $this->withHeader('Authorization', $bearer)->getJson('/open/user')->assertOk()->assertJson(['user' => null]);
});
