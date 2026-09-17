<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Access\DenyAllPermissionEvaluator;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\Method\SecurityMethodDescriptor;
use Firefly\Security\Access\Method\SecurityMethodManifest;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\Web\MethodSecurityControllerGuard;
use Orchestra\Testbench\TestCase;
use Psr\Log\AbstractLogger;

uses(TestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

/**
 * @param  list<SecurityMethodDescriptor>  $rules
 */
function guard(array $rules): MethodSecurityControllerGuard
{
    return new MethodSecurityControllerGuard(
        new SecurityMethodManifest($rules),
        new SecurityExpressionEvaluator, RoleHierarchy::fromRules(['ROLE_ADMIN > ROLE_USER']), new DenyAllPermissionEvaluator,
    );
}

it('allows a method with no rule', function () {
    guard([])->check('App\\Ctrl', 'index', []);
    expect(true)->toBeTrue();
});

it('403s an authenticated-but-denied method', function () {
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('u', 'u', [new SimpleGrantedAuthority('ROLE_USER')])
    ));

    guard([new SecurityMethodDescriptor('App\\Ctrl', 'admin', "hasRole('ADMIN')", [])])
        ->check('App\\Ctrl', 'admin', []);
})->throws(AuthorizationException::class);

it('401s an anonymous denied method', function () {
    guard([new SecurityMethodDescriptor('App\\Ctrl', 'admin', "hasRole('ADMIN')", [])])
        ->check('App\\Ctrl', 'admin', []);
})->throws(AuthenticationException::class);

it('binds #param positional args by name', function () {
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('a', 'a', [new SimpleGrantedAuthority('ROLE_ADMIN')])
    ));

    // DenyAll permission evaluator ⇒ hasPermission is false ⇒ 403, proving #id resolved and reached the evaluator.
    guard([new SecurityMethodDescriptor('App\\Ctrl', 'show', "hasPermission(#id, 'READ')", ['id'])])
        ->check('App\\Ctrl', 'show', [42]);
})->throws(AuthorizationException::class);

/*
 * WHAT A REFUSED PERSON READS. The denial used to be "Access is denied for [App\Ctrl::admin]." — a PHP class
 * and a method name on the wire, which one real application's consultant read verbatim on a panel. The
 * class and method are for the operator and go to the LOG; the wire gets a sentence written for a person,
 * the authorities the rule asked for as an RFC 9457 extension member, and — when the attribute names them —
 * the application's own product code and sentence.
 */
it('refuses with a client sentence, never the class name, and names the required authorities', function () {
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('u', 'u', [new SimpleGrantedAuthority('ROLE_USER')])
    ));

    try {
        guard([new SecurityMethodDescriptor('App\\Ctrl', 'admin', "hasRole('ADMIN') or hasAnyAuthority('orders:admin', 'orders:write')", [])])
            ->check('App\\Ctrl', 'admin', []);
        throw new LogicException('not refused');
    } catch (AuthorizationException $e) {
        expect($e->getMessage())->toBe(MethodSecurityControllerGuard::REFUSAL)
            ->not->toContain('\\')
            ->not->toContain('Ctrl')
            ->and($e->errorCode())->toBe('ACCESS_DENIED')
            ->and($e->extensions())->toBe(['requiredAuthorities' => ['ROLE_ADMIN', 'orders:admin', 'orders:write']]);
    }
});

it('refuses with the rule\'s own code and sentence when the attribute carries them', function () {
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('u', 'u', [new SimpleGrantedAuthority('ROLE_USER')])
    ));

    try {
        guard([new SecurityMethodDescriptor('App\\Ctrl', 'start', "hasAnyRole('MANAGER', 'TENANT_ADMIN')", [], 'RUN_ROLE_REQUIRED', 'Only a manager may start a run.')])
            ->check('App\\Ctrl', 'start', []);
        throw new LogicException('not refused');
    } catch (AuthorizationException $e) {
        expect($e->getMessage())->toBe('Only a manager may start a run.')
            ->and($e->errorCode())->toBe('RUN_ROLE_REQUIRED')
            ->and($e->extensions())->toBe(['requiredAuthorities' => ['ROLE_MANAGER', 'ROLE_TENANT_ADMIN']]);
    }
});

it('logs the class, the method and the principal the wire never sees', function () {
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('ada', 'ada', [new SimpleGrantedAuthority('ROLE_USER')])
    ));

    $log = new class extends AbstractLogger
    {
        /** @var list<array{0: string, 1: array<mixed>}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = [(string) $message, $context];
        }
    };

    $guard = new MethodSecurityControllerGuard(
        new SecurityMethodManifest([new SecurityMethodDescriptor('App\\Ctrl', 'admin', "hasRole('ADMIN')", [])]),
        new SecurityExpressionEvaluator, RoleHierarchy::fromRules([]), new DenyAllPermissionEvaluator,
        $log,
    );

    expect(fn () => $guard->check('App\\Ctrl', 'admin', []))->toThrow(AuthorizationException::class);

    expect($log->records)->toHaveCount(1)
        ->and($log->records[0][0])->toContain('App\\Ctrl::admin')
        ->and($log->records[0][1]['principal'])->toBe('ada')
        ->and($log->records[0][1]['requiredAuthorities'])->toBe(['ROLE_ADMIN']);
});
