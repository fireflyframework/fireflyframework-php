<?php

declare(strict_types=1);

use Firefly\Security\Access\DenyAllPermissionEvaluator;
use Firefly\Security\Access\Expression\ExpressionParseException;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\Expression\SecurityExpressionRoot;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SimpleGrantedAuthority;

/**
 * @param  list<string>  $authorities
 * @param  array<string,mixed>  $args
 */
function evalRoot(array $authorities, array $args = []): SecurityExpressionRoot
{
    $auth = Authentication::authenticated('alice', 'alice', array_map(
        fn (string $a) => new SimpleGrantedAuthority($a), $authorities,
    ));

    return new SecurityExpressionRoot($auth, RoleHierarchy::fromRules(['ROLE_ADMIN > ROLE_USER']), new DenyAllPermissionEvaluator, $args);
}

$evaluator = new SecurityExpressionEvaluator;

it('evaluates boolean compositions of whitelisted calls', function () use ($evaluator) {
    expect($evaluator->evaluate("hasRole('ADMIN')", evalRoot(['ROLE_ADMIN'])))->toBeTrue()
        ->and($evaluator->evaluate("hasRole('ADMIN') and hasAuthority('x')", evalRoot(['ROLE_ADMIN'])))->toBeFalse()
        ->and($evaluator->evaluate("hasRole('ADMIN') or hasAuthority('x')", evalRoot(['ROLE_ADMIN'])))->toBeTrue()
        ->and($evaluator->evaluate("not hasRole('ADMIN')", evalRoot(['ROLE_USER'])))->toBeTrue()
        ->and($evaluator->evaluate("hasAnyRole('STAFF','USER')", evalRoot(['ROLE_ADMIN'])))->toBeTrue()
        ->and($evaluator->evaluate('permitAll()', evalRoot([])))->toBeTrue()
        ->and($evaluator->evaluate('denyAll()', evalRoot(['ROLE_ADMIN'])))->toBeFalse()
        ->and($evaluator->evaluate('isAuthenticated() && (hasRole(\'USER\') || denyAll())', evalRoot(['ROLE_ADMIN'])))->toBeTrue();
});

it('resolves #param references inside hasPermission', function () use ($evaluator) {
    // DenyAll default ⇒ false, but the point is #id resolves without error and reaches the evaluator.
    expect($evaluator->evaluate("hasPermission(#id, 'READ')", evalRoot(['ROLE_ADMIN'], ['id' => 7])))->toBeFalse();
});

it('DENIES (never executes) a non-whitelisted function — the injection guard', function () use ($evaluator) {
    expect($evaluator->evaluate("system('rm -rf /')", evalRoot(['ROLE_ADMIN'])))->toBeFalse()
        ->and($evaluator->evaluate("hasRole('ADMIN') or phpinfo()", evalRoot(['ROLE_ADMIN'])))->toBeFalse()
        ->and($evaluator->evaluate('1 + 1', evalRoot(['ROLE_ADMIN'])))->toBeFalse()
        ->and($evaluator->evaluate("hasRole('ADMIN'", evalRoot(['ROLE_ADMIN'])))->toBeFalse(); // unbalanced
});

it('DENIES every other hostile-input shape the tokenizer/parser can see — inert data, never execution', function () use ($evaluator) {
    expect($evaluator->evaluate('`rm -rf /`', evalRoot(['ROLE_ADMIN'])))->toBeFalse() // backticks: unexpected character
        ->and($evaluator->evaluate('$x', evalRoot(['ROLE_ADMIN'])))->toBeFalse() // variable syntax: unexpected character
        ->and($evaluator->evaluate('${1+1}', evalRoot(['ROLE_ADMIN'])))->toBeFalse() // ${...}: unexpected character
        ->and($evaluator->evaluate("hasRole('ADMIN", evalRoot(['ROLE_ADMIN'])))->toBeFalse() // unterminated string literal
        ->and($evaluator->evaluate('T(System).exit()', evalRoot(['ROLE_ADMIN'])))->toBeFalse(); // SpEL type-reference syntax: unknown ident/unexpected char
});

it('parse() fails loud on malformed input for build-time validation', function () use ($evaluator) {
    $evaluator->parse("nope('x')");
})->throws(ExpressionParseException::class);

it('fails closed (never fatals) on a pathologically long expression via evaluate()', function () use ($evaluator) {
    expect($evaluator->evaluate(str_repeat('a', 3000), evalRoot(['ROLE_ADMIN'])))->toBeFalse();
});

it('parse() throws ExpressionParseException on a pathologically long expression', function () use ($evaluator) {
    $evaluator->parse(str_repeat('a', 3000));
})->throws(ExpressionParseException::class);

it('still evaluates a normal, well-under-the-cap expression correctly', function () use ($evaluator) {
    expect($evaluator->evaluate("hasRole('ADMIN') and isAuthenticated()", evalRoot(['ROLE_ADMIN'])))->toBeTrue();
});
