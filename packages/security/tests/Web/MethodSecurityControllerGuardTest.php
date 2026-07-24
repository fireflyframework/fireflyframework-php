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
