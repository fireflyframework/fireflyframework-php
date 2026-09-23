<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Actuator\PrincipalHealthDetailsAuthorizer;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Illuminate\Config\Repository;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

/**
 * @param  list<string>  $roles  the value of firefly.management.endpoint.health.roles
 * @param  list<string>  $rules  the role-hierarchy rules
 */
function principalHealthAuthorizer(array $roles, bool $securityEnabled = true, array $rules = []): PrincipalHealthDetailsAuthorizer
{
    $config = new Config(new Repository([
        'firefly' => [
            'security' => ['enabled' => $securityEnabled],
            'management' => ['endpoint' => ['health' => ['roles' => $roles]]],
        ],
    ]));

    return new PrincipalHealthDetailsAuthorizer($config, RoleHierarchy::fromRules($rules));
}

/**
 * Seed the holder with an authenticated principal. Named `healthPrincipal` rather than the obvious
 * `signInAs`: Pest runs every test file in ONE process, and MethodSecurityEvaluatorTest already declares a
 * global `signInAs()` with a different signature.
 *
 * @param  list<string>  $authorities
 */
function healthPrincipal(string $name, array $authorities): void
{
    SecurityContextHolder::setContext(new SecurityContext(Authentication::authenticated(
        $name,
        $name,
        array_map(static fn (string $a): SimpleGrantedAuthority => new SimpleGrantedAuthority($a), $authorities),
    )));
}

it('refuses while the security master flag is off, principal or no principal', function () {
    healthPrincipal('root', ['ROLE_ADMIN']);

    expect(principalHealthAuthorizer([], securityEnabled: false)->mayReadDetails())->toBeFalse();
});

it('refuses an anonymous caller', function () {
    expect(principalHealthAuthorizer([])->mayReadDetails())->toBeFalse();
});

it('refuses an unauthenticated token, which is not the same thing as no token at all', function () {
    SecurityContextHolder::setContext(new SecurityContext(Authentication::unauthenticated('ada', 'ada', 'secret')));

    expect(principalHealthAuthorizer([])->mayReadDetails())->toBeFalse();
});

it('admits any authenticated principal when no roles are listed', function () {
    healthPrincipal('ada', ['ROLE_USER']);

    expect(principalHealthAuthorizer([])->mayReadDetails())->toBeTrue();
});

it('refuses an authenticated principal without the listed role', function () {
    healthPrincipal('ada', ['ROLE_USER']);

    expect(principalHealthAuthorizer(['ACTUATOR'])->mayReadDetails())->toBeFalse();
});

it('reads a bare configured name as ROLE_<name>, the spelling hasRole: uses', function () {
    healthPrincipal('ops', ['ROLE_ACTUATOR']);

    expect(principalHealthAuthorizer(['ACTUATOR'])->mayReadDetails())->toBeTrue()
        ->and(principalHealthAuthorizer(['ROLE_ACTUATOR'])->mayReadDetails())->toBeTrue();
});

it('admits a principal whose role IMPLIES the listed one through the hierarchy', function () {
    healthPrincipal('root', ['ROLE_ADMIN']);

    expect(principalHealthAuthorizer(['ACTUATOR'], rules: ['ROLE_ADMIN > ROLE_ACTUATOR'])->mayReadDetails())->toBeTrue();
});

it('drops a blank entry without widening the check it sits beside', function () {
    healthPrincipal('ada', ['ROLE_USER']);

    expect(principalHealthAuthorizer(['  ', 'ACTUATOR'])->mayReadDetails())->toBeFalse();
});
