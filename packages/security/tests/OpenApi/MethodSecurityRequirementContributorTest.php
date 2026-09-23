<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\OpenApi\Security\SecurityRequirement;
use Firefly\Security\Access\Method\SecurityMethodDescriptor;
use Firefly\Security\Access\Method\SecurityMethodManifest;
use Firefly\Security\OpenApi\MethodSecurityRequirementContributor;
use Firefly\Web\Route\RouteDescriptor;
use Illuminate\Config\Repository;

/**
 * The second half of "a path that is protected says so", on its own: a controller action carrying
 * #[PreAuthorize]/#[Secured]/#[RolesAllowed] is refused by the DISPATCHER, not by a URL rule, so
 * `firefly.security.http` may be empty and the operation still be protected.
 *
 * Every case is asserted through the three-valued answer the port defines — a list, an empty list, null —
 * because the difference between "this contributor says nothing" and "this contributor says public" is the
 * whole reason `requirementsFor()` returns `?array`: a null that became `[]` would publish
 * `security: []` — "no authentication required" — over a guarded path.
 *
 * The helpers are named for this file: the suite runs every package's tests in one process, `securityConfig()`
 * is already declared by this package's own WebSettingsTest and `route()` is one of Laravel's global helpers.
 *
 * @param  array<string, mixed>  $security
 */
function methodSecurityOpenApiConfig(array $security): Config
{
    return new Config(new Repository(['firefly' => ['security' => $security]]));
}

/**
 * @param  array<string, mixed>  $security
 * @param  list<SecurityMethodDescriptor>  $rules
 */
function methodSecurityOpenApiContributor(array $security, array $rules): MethodSecurityRequirementContributor
{
    return new MethodSecurityRequirementContributor(new SecurityMethodManifest($rules), methodSecurityOpenApiConfig($security));
}

function methodSecurityOpenApiRoute(string $method = 'show'): RouteDescriptor
{
    return new RouteDescriptor('GET', '/api/orders/{id}', 'App\\Http\\OrderController', $method, 200, null, []);
}

function methodSecurityOpenApiRule(string $expression, string $method = 'show', ?string $postExpression = null, ?string $postFilter = null): SecurityMethodDescriptor
{
    return new SecurityMethodDescriptor(
        'App\\Http\\OrderController',
        $method,
        $expression,
        ['id'],
        postExpression: $postExpression,
        postFilter: $postFilter,
    );
}

/**
 * The requirements for the fixture route, with the "no opinion" answer ruled out first — so every assertion
 * below reads as a claim about what the contributor SAID rather than about whether it said anything.
 *
 * @param  array<string, mixed>  $security
 * @param  list<SecurityMethodDescriptor>  $rules
 * @return list<SecurityRequirement>
 */
function methodSecurityOpenApiRequirements(array $security, array $rules): array
{
    $requirements = methodSecurityOpenApiContributor($security, $rules)->requirementsFor(methodSecurityOpenApiRoute());

    expect($requirements)->not->toBeNull();

    return $requirements ?? [];
}

it('has no opinion when the security master flag is off', function (): void {
    $contributor = methodSecurityOpenApiContributor(
        ['enabled' => false, 'jwt' => ['enabled' => true]],
        [methodSecurityOpenApiRule("hasAnyRole('ADMIN')")],
    );

    expect($contributor->requirementsFor(methodSecurityOpenApiRoute()))->toBeNull();
});

it('still requires the scheme when only firefly.security.method.enabled is off, because the controller dispatcher keeps enforcing the rule', function (): void {
    // That flag stands down the PROXY LINK alone: SecurityWiringPass installs MethodSecurityControllerGuard
    // once past the master flag whatever it says, the guard reads only the manifest, and the skeleton config
    // documents it in as many words. A contributor that fell silent here would publish an action the
    // dispatcher answers 401/403 to with no `security` member at all.
    $requirements = methodSecurityOpenApiRequirements(
        ['enabled' => true, 'method' => ['enabled' => false], 'jwt' => ['enabled' => true]],
        [methodSecurityOpenApiRule("hasAnyRole('ADMIN')")],
    );

    expect($requirements)->toHaveCount(1)
        ->and($requirements[0]->toArray())->toBe(['bearerAuth' => []]);
});

it('has no opinion about a route the manifest holds no rule for', function (): void {
    $contributor = methodSecurityOpenApiContributor(
        ['enabled' => true, 'jwt' => ['enabled' => true]],
        [methodSecurityOpenApiRule("hasAnyRole('ADMIN')", 'destroy')],
    );

    expect($contributor->requirementsFor(methodSecurityOpenApiRoute('show')))->toBeNull();
});

it('has no opinion about a permitAll() rule rather than declaring the path public', function (): void {
    $contributor = methodSecurityOpenApiContributor(
        ['enabled' => true, 'jwt' => ['enabled' => true]],
        [methodSecurityOpenApiRule('permitAll()')],
    );

    expect($contributor->requirementsFor(methodSecurityOpenApiRoute()))->toBeNull();
});

it('requires the scheme for a permitAll() pre rule carrying a #[PostAuthorize], which really does refuse the caller', function (): void {
    // `permitAll()` is what the scanner compiles for a method whose only rule is a post one, and
    // MethodSecurityEvaluator::after() throws through the same deny() — a 401 for an anonymous caller.
    $requirements = methodSecurityOpenApiRequirements(
        ['enabled' => true, 'jwt' => ['enabled' => true]],
        [methodSecurityOpenApiRule('permitAll()', postExpression: "hasRole('ADMIN')")],
    );

    expect($requirements)->toHaveCount(1)
        ->and($requirements[0]->toArray())->toBe(['bearerAuth' => []]);
});

it('has no opinion about a permitAll() pre rule whose only other rule is a #[PostFilter], which narrows rather than refuses', function (): void {
    $contributor = methodSecurityOpenApiContributor(
        ['enabled' => true, 'jwt' => ['enabled' => true]],
        [methodSecurityOpenApiRule('permitAll()', postFilter: "hasRole('ADMIN')")],
    );

    expect($contributor->requirementsFor(methodSecurityOpenApiRoute()))->toBeNull();
});

it('carries a scope a #[PostAuthorize] demands, because the post rule is enforced exactly as the pre one is', function (): void {
    $requirements = methodSecurityOpenApiRequirements(
        ['enabled' => true, 'oauth2' => ['resource_server' => ['enabled' => true]]],
        [methodSecurityOpenApiRule('permitAll()', postExpression: "hasScope('orders.read')")],
    );

    expect($requirements[0]->toArray())->toBe(['oauth2ResourceServer' => ['orders.read']]);
});

it('has no opinion when nothing it can name is configured, rather than naming a scheme the server never challenges for', function (): void {
    $contributor = methodSecurityOpenApiContributor(
        ['enabled' => true, 'session' => ['enabled' => true]],
        [methodSecurityOpenApiRule("hasAnyRole('ADMIN')")],
    );

    expect($contributor->requirementsFor(methodSecurityOpenApiRoute()))->toBeNull();
});

it('requires the configured scheme, with no scopes, for a role rule', function (): void {
    $requirements = methodSecurityOpenApiRequirements(
        ['enabled' => true, 'jwt' => ['enabled' => true]],
        [methodSecurityOpenApiRule("hasAnyRole('ADMIN')")],
    );

    expect($requirements)->toHaveCount(1)
        ->and($requirements[0]->toArray())->toBe(['bearerAuth' => []]);
});

it('carries a hasScope rule\'s scope as the requirement\'s scope list', function (): void {
    $requirements = methodSecurityOpenApiRequirements(
        ['enabled' => true, 'oauth2' => ['resource_server' => ['enabled' => true]]],
        [methodSecurityOpenApiRule("hasScope('orders.read')")],
    );

    expect($requirements)->toHaveCount(1)
        ->and($requirements[0]->toArray())->toBe(['oauth2ResourceServer' => ['orders.read']]);
});

it('publishes a bare requirement for a hasAnyScope rule, because a requirement\'s scope list is conjunctive', function (): void {
    // A Security Requirement Object's scopes are ALL required; `hasAnyScope('a', 'b')` accepts either. Naming
    // both would send a generated client to the authorization server asking for a scope its registration may
    // not include, and the authorization request is refused — a document stricter than the server it
    // describes. The credential is the whole of what this document can state, as it already is for hasRole.
    $requirements = methodSecurityOpenApiRequirements(
        ['enabled' => true, 'jwt' => ['enabled' => true]],
        [methodSecurityOpenApiRule("hasAnyScope('orders.read', 'orders.write')")],
    );

    expect($requirements[0]->toArray())->toBe(['bearerAuth' => []]);
});

it('publishes a bare requirement for an OR of two hasScope calls, for the same reason', function (): void {
    $requirements = methodSecurityOpenApiRequirements(
        ['enabled' => true, 'jwt' => ['enabled' => true]],
        [methodSecurityOpenApiRule("hasScope('orders.read') or hasScope('orders.write')")],
    );

    expect($requirements[0]->toArray())->toBe(['bearerAuth' => []]);
});

it('carries every scope an AND of hasScope calls names, once, because all of them really are required', function (): void {
    $requirements = methodSecurityOpenApiRequirements(
        ['enabled' => true, 'jwt' => ['enabled' => true]],
        [methodSecurityOpenApiRule("hasScope('orders.read') and hasScope('orders.write') and hasScope('orders.read')")],
    );

    expect($requirements[0]->scopes)->toBe(['orders.read', 'orders.write']);
});

it('reads a scope name containing the word `or` as a name rather than as a disjunction', function (): void {
    // The operators are looked for with every quoted literal blanked out, so a vocabulary of this shape is
    // published rather than silently dropped.
    $requirements = methodSecurityOpenApiRequirements(
        ['enabled' => true, 'jwt' => ['enabled' => true]],
        [methodSecurityOpenApiRule("hasScope('read.or.write')")],
    );

    expect($requirements[0]->scopes)->toBe(['read.or.write']);
});

it('leaves a role or authority out of the scope list, because no token endpoint issues one', function (): void {
    $requirements = methodSecurityOpenApiRequirements(
        ['enabled' => true, 'jwt' => ['enabled' => true]],
        [methodSecurityOpenApiRule("hasAuthority('ORDERS_READ') and hasAnyRole('ADMIN')")],
    );

    expect($requirements[0]->scopes)->toBe([]);
});

it('names the authorization server\'s own scheme when this application is the authorization server', function (): void {
    $requirements = methodSecurityOpenApiRequirements(
        ['enabled' => true, 'oauth2' => ['server' => ['enabled' => true]]],
        [methodSecurityOpenApiRule("hasAnyRole('ADMIN')")],
    );

    expect($requirements)->toHaveCount(1)
        ->and($requirements[0]->scheme)->toBe('oauth2AuthorizationCode');
});

it('names EVERY configured scheme as an OR-list, because the runtime really accepts either credential', function (): void {
    $requirements = methodSecurityOpenApiRequirements(
        ['enabled' => true, 'jwt' => ['enabled' => true], 'http_basic' => ['enabled' => true]],
        [methodSecurityOpenApiRule("hasScope('orders.read')")],
    );

    expect(array_map(static fn (SecurityRequirement $requirement): array => $requirement->toArray(), $requirements))->toBe([
        ['bearerAuth' => ['orders.read']],
        // HTTP Basic has no scope vocabulary a generated client could ask a token endpoint for.
        ['httpBasic' => []],
    ]);
});
