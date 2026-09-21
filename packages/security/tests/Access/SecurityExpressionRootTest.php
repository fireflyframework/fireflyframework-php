<?php

declare(strict_types=1);

use Firefly\Security\Access\DenyAllPermissionEvaluator;
use Firefly\Security\Access\Expression\SecurityExpressionRoot;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SimpleGrantedAuthority;

/**
 * @param  list<string>  $authorities
 * @param  array<string,mixed>  $args
 */
function root(array $authorities, array $args = []): SecurityExpressionRoot
{
    $auth = Authentication::authenticated('alice', 'alice', array_map(
        fn (string $a) => new SimpleGrantedAuthority($a), $authorities,
    ));

    return new SecurityExpressionRoot($auth, RoleHierarchy::fromRules(['ROLE_ADMIN > ROLE_USER']), new DenyAllPermissionEvaluator, $args);
}

it('resolves hasRole through the hierarchy and normalises the ROLE_ prefix', function () {
    expect(root(['ROLE_ADMIN'])->hasRole('USER'))->toBeTrue()   // ADMIN implies USER
        ->and(root(['ROLE_ADMIN'])->hasRole('ADMIN'))->toBeTrue()
        ->and(root(['ROLE_USER'])->hasRole('ADMIN'))->toBeFalse();
});

it('matches bare authorities and isAuthenticated', function () {
    expect(root(['orders:read'])->hasAuthority('orders:read'))->toBeTrue()
        ->and(root(['orders:read'])->hasAnyAuthority('a', 'orders:read'))->toBeTrue()
        ->and(root([])->isAuthenticated())->toBeTrue()
        ->and(root([])->permitAll())->toBeTrue()
        ->and(root([])->denyAll())->toBeFalse();
});

it('resolves #param arguments and defers hasPermission to the evaluator (deny default)', function () {
    $r = root(['ROLE_ADMIN'], ['id' => 42]);

    expect($r->arg('id'))->toBe(42)
        ->and($r->hasPermission(42, 'READ'))->toBeFalse(); // DenyAll default
});

it('resolves hasScope and hasAnyScope against SCOPE_ authorities, normalising the prefix', function () {
    expect(root(['SCOPE_orders:read'])->hasScope('orders:read'))->toBeTrue()
        ->and(root(['SCOPE_orders:read'])->hasScope('SCOPE_orders:read'))->toBeTrue()
        ->and(root(['SCOPE_orders:read'])->hasScope('orders:write'))->toBeFalse()
        ->and(root(['SCOPE_profile'])->hasAnyScope('email', 'profile'))->toBeTrue()
        ->and(root(['ROLE_ADMIN'])->hasAnyScope('email'))->toBeFalse();
});
