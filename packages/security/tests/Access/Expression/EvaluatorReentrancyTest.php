<?php

declare(strict_types=1);

use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\Expression\SecurityExpressionRoot;
use Firefly\Security\Access\PermissionEvaluator;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SimpleGrantedAuthority;

/**
 * SecurityExpressionEvaluator is a Singleton bean holding MUTABLE parse state ($tokens, $pos, $root).
 * hasPermission() is the one dispatch path that calls application code — a user-supplied
 * PermissionEvaluator, the extension point the book explicitly recommends — and that code can evaluate an
 * expression of its own on the same singleton. The inner call used to overwrite the outer parse state, so
 * when it returned, the outer parseExpression() resumed against the INNER token stream, saw eof, and returned
 * the inner result: everything after `hasPermission(...)` in the outer expression was silently dropped.
 *
 * That fails OPEN. `hasPermission(#id,'read') and hasRole('ADMIN')` returned true for a principal with no
 * ROLE_ADMIN.
 */
final class ReentrantPermissionEvaluator implements PermissionEvaluator
{
    public function __construct(private readonly SecurityExpressionEvaluator $evaluator) {}

    public function hasPermission(Authentication $authentication, mixed $target, string $permission): bool
    {
        // Application code re-entering the singleton — e.g. delegating to another policy expression.
        $root = new SecurityExpressionRoot($authentication, new RoleHierarchy([]), new AllowAllPermissions, []);
        $this->evaluator->evaluate('permitAll()', $root);

        return true;
    }
}

final class AllowAllPermissions implements PermissionEvaluator
{
    public function hasPermission(Authentication $authentication, mixed $target, string $permission): bool
    {
        return true;
    }
}

it('does not drop the rest of the expression when application code re-enters the evaluator', function () {
    $evaluator = new SecurityExpressionEvaluator;

    // A principal that is authenticated but holds NO authorities at all.
    $authentication = Authentication::authenticated('alice', 'alice', []);

    $root = new SecurityExpressionRoot(
        $authentication,
        new RoleHierarchy([]),
        new ReentrantPermissionEvaluator($evaluator),
        ['id' => 7],
    );

    // hasPermission() returns true, but the principal has no ROLE_ADMIN, so the conjunction must be FALSE.
    expect($evaluator->evaluate("hasPermission(#id, 'read') and hasRole('ADMIN')", $root))->toBeFalse();
});

it('still evaluates a conjunction correctly when the principal does hold the role', function () {
    $evaluator = new SecurityExpressionEvaluator;
    $authentication = Authentication::authenticated('root', 'root', [new SimpleGrantedAuthority('ROLE_ADMIN')]);

    $root = new SecurityExpressionRoot(
        $authentication,
        new RoleHierarchy([]),
        new ReentrantPermissionEvaluator($evaluator),
        ['id' => 7],
    );

    expect($evaluator->evaluate("hasPermission(#id, 'read') and hasRole('ADMIN')", $root))->toBeTrue();
});
