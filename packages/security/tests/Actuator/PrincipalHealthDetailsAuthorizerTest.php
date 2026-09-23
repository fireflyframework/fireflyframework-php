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
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

uses(TestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

/**
 * @param  mixed  $roles  the RAW value of firefly.management.endpoint.health.roles — a list, a CSV string, or
 *                        the junk a typo leaves behind; `mixed` rather than `array` because a value that is
 *                        not a list at all is exactly what these tests have to be able to express
 * @param  list<string>  $rules  the role-hierarchy rules
 */
function principalHealthAuthorizer(mixed $roles, bool $securityEnabled = true, array $rules = [], ?LoggerInterface $logger = null): PrincipalHealthDetailsAuthorizer
{
    $config = new Config(new Repository([
        'firefly' => [
            'security' => ['enabled' => $securityEnabled],
            'management' => ['endpoint' => ['health' => ['roles' => $roles]]],
        ],
    ]));

    return new PrincipalHealthDetailsAuthorizer($config, RoleHierarchy::fromRules($rules), $logger);
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

it('refuses everyone when a configured list survives normalisation as nothing', function () {
    healthPrincipal('ada', ['ROLE_USER']);

    expect(principalHealthAuthorizer([''])->mayReadDetails())->toBeFalse()
        ->and(principalHealthAuthorizer(['   '])->mayReadDetails())->toBeFalse()
        ->and(principalHealthAuthorizer([null])->mayReadDetails())->toBeFalse()
        ->and(principalHealthAuthorizer([123])->mayReadDetails())->toBeFalse()
        ->and(principalHealthAuthorizer([['ACTUATOR']])->mayReadDetails())->toBeFalse();
});

it('refuses the ADMIN too, because an unusable list is a broken rule and not a role mismatch', function () {
    healthPrincipal('root', ['ROLE_ADMIN']);

    expect(principalHealthAuthorizer([null], rules: ['ROLE_ADMIN > ROLE_ACTUATOR'])->mayReadDetails())->toBeFalse();
});

it('reads a CSV string the way the sibling firefly.management.* list keys read theirs', function () {
    healthPrincipal('ops', ['ROLE_ACTUATOR']);

    expect(principalHealthAuthorizer('ACTUATOR')->mayReadDetails())->toBeTrue()
        ->and(principalHealthAuthorizer('ROLE_ACTUATOR')->mayReadDetails())->toBeTrue()
        ->and(principalHealthAuthorizer('ADMIN,ACTUATOR')->mayReadDetails())->toBeTrue()
        ->and(principalHealthAuthorizer('ADMIN, ACTUATOR')->mayReadDetails())->toBeTrue();
});

it('grants a CSV string exactly what the equivalent list grants, and no more', function () {
    healthPrincipal('ada', ['ROLE_USER']);

    expect(principalHealthAuthorizer('ADMIN,ACTUATOR')->mayReadDetails())
        ->toBe(principalHealthAuthorizer(['ADMIN', 'ACTUATOR'])->mayReadDetails())
        ->toBeFalse();
});

it('applies the hierarchy to a CSV entry too, because the spelling is not the rule', function () {
    healthPrincipal('root', ['ROLE_ADMIN']);

    expect(principalHealthAuthorizer('ACTUATOR', rules: ['ROLE_ADMIN > ROLE_ACTUATOR'])->mayReadDetails())->toBeTrue();
});

/**
 * The read must not THROW. Config::array() raises a ConfigurationException on a type mismatch, and this read
 * runs on every `when-authorized` scrape from a code path outside HealthEndpoint::readFailSafe() — a scalar
 * here used to answer 500 instead of `{"status":"UP"}`, which a liveness probe reads as DOWN. Refusing is the
 * documented behaviour for a restriction that cannot be read; 500 is not a behaviour, it is an outage.
 */
it('refuses, rather than throwing, on a value that is no list of role names at all', function () {
    healthPrincipal('root', ['ROLE_ADMIN']);

    expect(principalHealthAuthorizer('')->mayReadDetails())->toBeFalse()
        ->and(principalHealthAuthorizer('   ')->mayReadDetails())->toBeFalse()
        ->and(principalHealthAuthorizer(',')->mayReadDetails())->toBeFalse()
        ->and(principalHealthAuthorizer(0)->mayReadDetails())->toBeFalse()
        ->and(principalHealthAuthorizer(123)->mayReadDetails())->toBeFalse()
        ->and(principalHealthAuthorizer(false)->mayReadDetails())->toBeFalse()
        ->and(principalHealthAuthorizer(true)->mayReadDetails())->toBeFalse();
});

it('reads an absent restriction as the empty list, however the key spells its absence', function () {
    healthPrincipal('ada', ['ROLE_USER']);

    // A dangling `'roles' => null` is Laravel's own spelling of "not set" — Config::required() has always
    // mapped it to the default, and it must keep meaning what `roles: []` means rather than becoming the
    // unreadable-restriction case: nothing was written there to narrow anything.
    expect(principalHealthAuthorizer(null)->mayReadDetails())->toBeTrue()
        ->and(principalHealthAuthorizer([])->mayReadDetails())->toBeTrue();
});

it('logs the refusal once, naming the key, for a string restriction as well as a list one', function () {
    healthPrincipal('root', ['ROLE_ADMIN']);

    $logger = new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $warnings = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->warnings[] = (string) $message;
        }
    };

    $authorizer = principalHealthAuthorizer('', logger: $logger);

    expect($authorizer->mayReadDetails())->toBeFalse()
        ->and($authorizer->mayReadDetails())->toBeFalse()
        ->and($logger->warnings)->toHaveCount(1)
        ->and($logger->warnings[0])->toContain('firefly.management.endpoint.health.roles');
});
