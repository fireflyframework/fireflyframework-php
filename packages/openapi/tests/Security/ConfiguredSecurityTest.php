<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\OpenApi\Security\ConfiguredSecurity;
use Firefly\Web\Route\RouteDescriptor;
use Illuminate\Config\Repository;

/**
 * The config-driven contributor on its own: what `firefly.security.*` says, turned into the two things an
 * OpenAPI document can say about authentication — the schemes in `components.securitySchemes` and the
 * per-operation `security` requirement.
 *
 * Every case here is a CONFIGURATION the framework accepts, asserted against the document it must produce.
 * The three-valued requirement answer (a list, an empty list, null) is asserted explicitly, because the
 * difference between "public" and "no opinion" is the whole reason the port returns `?array`.
 *
 * The helpers are named for this file rather than `securityConfig()`/`route()`: the suite runs every
 * package's tests in one process, `securityConfig()` is already declared by firefly/security's own
 * WebSettingsTest, and `route()` is one of Laravel's global helpers — either spelling would be a fatal
 * redeclaration the moment the whole suite ran.
 *
 * @param  array<string, mixed>  $security
 */
function openApiSecurityConfig(array $security): Config
{
    return new Config(new Repository(['firefly' => ['security' => $security, 'openapi' => ['security' => ['enabled' => true]]]]));
}

function openApiSecurityRoute(string $path): RouteDescriptor
{
    return new RouteDescriptor('GET', $path, 'App\\Http\\DemoController', 'show', 200, null, []);
}

it('emits nothing when security is off', function (): void {
    expect((new ConfiguredSecurity(openApiSecurityConfig(['enabled' => false, 'http_basic' => ['enabled' => true]])))->schemes())->toBe([]);
});

it('emits an httpBasic scheme for http_basic', function (): void {
    $schemes = (new ConfiguredSecurity(openApiSecurityConfig(['enabled' => true, 'http_basic' => ['enabled' => true]])))->schemes();

    expect($schemes)->toHaveCount(1)
        ->and($schemes[0]->name)->toBe('httpBasic')
        ->and($schemes[0]->definition)->toBe(['type' => 'http', 'scheme' => 'basic']);
});

it('emits a bearerAuth scheme with bearerFormat JWT for the local jwt filter', function (): void {
    $schemes = (new ConfiguredSecurity(openApiSecurityConfig(['enabled' => true, 'jwt' => ['enabled' => true]])))->schemes();

    expect($schemes[0]->definition)->toBe(['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT']);
});

it('emits an oauth2ResourceServer scheme carrying the issuer when one is configured', function (): void {
    $schemes = (new ConfiguredSecurity(openApiSecurityConfig([
        'enabled' => true,
        'oauth2' => ['resource_server' => ['enabled' => true, 'issuer' => 'https://idp.example.com', 'jwks_uri' => 'https://idp.example.com/jwks']],
    ])))->schemes();

    expect($schemes[0]->name)->toBe('oauth2ResourceServer')
        ->and($schemes[0]->definition['type'])->toBe('http')
        ->and($schemes[0]->definition['bearerFormat'])->toBe('JWT')
        ->and($schemes[0]->definition['description'])->toContain('https://idp.example.com');
});

it('requires the scheme on a path a deny-by-default rule covers', function (): void {
    $security = new ConfiguredSecurity(openApiSecurityConfig([
        'enabled' => true,
        'jwt' => ['enabled' => true],
        'http' => ['enabled' => true, 'rules' => [
            ['pattern' => 'actuator/health', 'access' => 'permitAll'],
            ['pattern' => 'orders/*', 'access' => 'hasRole:ADMIN'],
        ]],
    ]));

    $requirements = $security->requirementsFor(openApiSecurityRoute('orders/{id}')) ?? [];

    expect($requirements)->toHaveCount(1)
        ->and(($requirements[0] ?? null)?->scheme)->toBe('bearerAuth');
});

it('leaves a permitAll path with no requirement at all', function (): void {
    $security = new ConfiguredSecurity(openApiSecurityConfig([
        'enabled' => true,
        'jwt' => ['enabled' => true],
        'http' => ['enabled' => true, 'rules' => [['pattern' => 'actuator/health', 'access' => 'permitAll']]],
    ]));

    expect($security->requirementsFor(openApiSecurityRoute('actuator/health')))->toBe([]);
});

it('requires the scheme on a path NO rule matches, because the rules are deny by default', function (): void {
    $security = new ConfiguredSecurity(openApiSecurityConfig([
        'enabled' => true,
        'jwt' => ['enabled' => true],
        'http' => ['enabled' => true, 'rules' => [['pattern' => 'actuator/health', 'access' => 'permitAll']]],
    ]));

    expect($security->requirementsFor(openApiSecurityRoute('orders')))->toHaveCount(1);
});

it('carries the scope as the requirement\'s scope list for a hasScope rule', function (): void {
    $security = new ConfiguredSecurity(openApiSecurityConfig([
        'enabled' => true,
        'oauth2' => ['resource_server' => ['enabled' => true, 'jwks_uri' => 'https://idp.example.com/jwks']],
        'http' => ['enabled' => true, 'rules' => [['pattern' => 'orders/*', 'access' => 'hasScope:orders.read']]],
    ]));

    $requirements = $security->requirementsFor(openApiSecurityRoute('orders/{id}')) ?? [];

    expect(($requirements[0] ?? null)?->scopes)->toBe(['orders.read']);
});

it('has NO opinion at all when URL authorization is off', function (): void {
    $security = new ConfiguredSecurity(openApiSecurityConfig(['enabled' => true, 'jwt' => ['enabled' => true]]));

    expect($security->requirementsFor(openApiSecurityRoute('orders')))->toBeNull();
});

it('matches a rule pattern exactly as the filter does, whichever spelling it is written in', function (): void {
    // Both sides are put in `$request->path()` form — the route because a RouteManifest path carries a
    // leading slash the request never does, the pattern because HttpSecurity::requestMatcher() normalises it
    // the same way for the filter (see packages/security/tests/Access/HttpSecurityTest.php). So `/x` and `x`
    // are ONE rule here for the same reason they are one rule at runtime, and the document's answer for
    // either spelling is the answer the filter will give.
    $slashed = new ConfiguredSecurity(openApiSecurityConfig([
        'enabled' => true,
        'jwt' => ['enabled' => true],
        'http' => ['enabled' => true, 'rules' => [['pattern' => '/actuator/health', 'access' => 'permitAll']]],
    ]));

    $bare = new ConfiguredSecurity(openApiSecurityConfig([
        'enabled' => true,
        'jwt' => ['enabled' => true],
        'http' => ['enabled' => true, 'rules' => [['pattern' => 'actuator/health', 'access' => 'permitAll']]],
    ]));

    expect($slashed->requirementsFor(openApiSecurityRoute('/actuator/health')))->toBe([])
        ->and($bare->requirementsFor(openApiSecurityRoute('/actuator/health')))->toBe([])
        // and neither spelling opens anything it does not name
        ->and($slashed->requirementsFor(openApiSecurityRoute('/actuator/env')))->toHaveCount(1)
        ->and($bare->requirementsFor(openApiSecurityRoute('/actuator/env')))->toHaveCount(1);
});

it('matches the root path the way `$request->path()` spells it', function (): void {
    // Laravel answers '/' for the root and never '', so a rule written '/' is the rule that covers it —
    // normalising that one pattern to an empty string would leave the home page matching nothing.
    $security = new ConfiguredSecurity(openApiSecurityConfig([
        'enabled' => true,
        'jwt' => ['enabled' => true],
        'http' => ['enabled' => true, 'rules' => [['pattern' => '/', 'access' => 'permitAll']]],
    ]));

    expect($security->requirementsFor(openApiSecurityRoute('/')))->toBe([]);
});

it('names EVERY configured scheme in the requirement, because the operation security array is an OR-list', function (): void {
    // http_basic beside a bearer scheme: the runtime accepts either credential (the two filters skip an
    // Authorization header belonging to the other), so the document must offer both. Naming one would leave
    // the other published under components.securitySchemes and referenced by nothing at all.
    $security = new ConfiguredSecurity(openApiSecurityConfig([
        'enabled' => true,
        'jwt' => ['enabled' => true],
        'http_basic' => ['enabled' => true],
        'http' => ['enabled' => true, 'rules' => [['pattern' => '*', 'access' => 'authenticated']]],
    ]));

    $requirements = $security->requirementsFor(openApiSecurityRoute('/orders')) ?? [];

    expect(array_map(static fn ($requirement): string => $requirement->scheme, $requirements))
        ->toBe(['bearerAuth', 'httpBasic'])
        // and every one of them IS in the schemes map: the two are built from one list, so an orphan entry
        // cannot be produced.
        ->and(array_map(static fn ($scheme): string => $scheme->name, $security->schemes()))
        ->toBe(['bearerAuth', 'httpBasic']);
});

it('puts a hasScope scope on the bearer entry only', function (): void {
    // A SCOPE_x authority is something a client asks the token endpoint for. HTTP Basic has no scope
    // vocabulary a generated client could request, so naming one beside it would publish a parameter
    // nobody can supply.
    $security = new ConfiguredSecurity(openApiSecurityConfig([
        'enabled' => true,
        'http_basic' => ['enabled' => true],
        'oauth2' => ['resource_server' => ['enabled' => true, 'jwks_uri' => 'https://idp.example.com/jwks']],
        'http' => ['enabled' => true, 'rules' => [['pattern' => 'orders/*', 'access' => 'hasScope:orders.read']]],
    ]));

    $requirements = $security->requirementsFor(openApiSecurityRoute('/orders/{id}')) ?? [];

    expect(array_map(static fn ($requirement): array => $requirement->toArray(), $requirements))
        ->toBe([['oauth2ResourceServer' => ['orders.read']], ['httpBasic' => []]]);
});

it('has no opinion when URL rules are on but no authentication scheme is configured at all', function (): void {
    $security = new ConfiguredSecurity(openApiSecurityConfig([
        'enabled' => true,
        'http' => ['enabled' => true, 'rules' => [['pattern' => '*', 'access' => 'authenticated']]],
    ]));

    // A path is protected by a session the document cannot describe as a scheme. Naming nothing is the
    // honest answer; naming a scheme that is not configured would be a lie a generated client acts on.
    expect($security->requirementsFor(openApiSecurityRoute('/orders')))->toBeNull();
});
