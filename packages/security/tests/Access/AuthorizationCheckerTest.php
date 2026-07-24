<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Access\AuthorizationChecker;
use Firefly\Security\Access\DenyAllPermissionEvaluator;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

function checker(): AuthorizationChecker
{
    return new AuthorizationChecker(new SecurityExpressionEvaluator, RoleHierarchy::fromRules([]), new DenyAllPermissionEvaluator);
}

it('grants against the current holder context', function () {
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('a', 'a', [new SimpleGrantedAuthority('ROLE_ADMIN')])
    ));

    expect(checker()->isGranted("hasRole('ADMIN')"))->toBeTrue()
        ->and(checker()->isGranted("hasRole('USER')"))->toBeFalse();
});

it('check() throws 401 when anonymous and 403 when authenticated-but-denied', function () {
    expect(fn () => checker()->check("hasRole('ADMIN')"))->toThrow(AuthenticationException::class);

    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('u', 'u', [new SimpleGrantedAuthority('ROLE_USER')])
    ));
    expect(fn () => checker()->check("hasRole('ADMIN')"))->toThrow(AuthorizationException::class);
});
