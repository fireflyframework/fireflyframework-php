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

function methodSecurityOpenApiRule(string $expression, string $method = 'show'): SecurityMethodDescriptor
{
    return new SecurityMethodDescriptor('App\\Http\\OrderController', $method, $expression, ['id']);
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

it('has no opinion when method security is switched off', function (): void {
    $contributor = methodSecurityOpenApiContributor(
        ['enabled' => true, 'method' => ['enabled' => false], 'jwt' => ['enabled' => true]],
        [methodSecurityOpenApiRule("hasAnyRole('ADMIN')")],
    );

    expect($contributor->requirementsFor(methodSecurityOpenApiRoute()))->toBeNull();
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

it('carries every scope a hasAnyScope rule names, once', function (): void {
    $requirements = methodSecurityOpenApiRequirements(
        ['enabled' => true, 'jwt' => ['enabled' => true]],
        [methodSecurityOpenApiRule("hasAnyScope('orders.read', 'orders.write') or hasScope('orders.read')")],
    );

    expect($requirements[0]->scopes)->toBe(['orders.read', 'orders.write']);
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
