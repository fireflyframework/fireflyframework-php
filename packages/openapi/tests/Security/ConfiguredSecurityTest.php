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

it('reads the leading slash off a manifest path before matching the rule patterns', function (): void {
    $security = new ConfiguredSecurity(openApiSecurityConfig([
        'enabled' => true,
        'jwt' => ['enabled' => true],
        'http' => ['enabled' => true, 'rules' => [['pattern' => '/actuator/health', 'access' => 'permitAll']]],
    ]));

    // RouteManifest paths carry a leading slash and HttpSecurityFilter matches against `$request->path()`,
    // which never does. Both sides are normalised, so a rule written either way covers the same operation
    // the filter covers.
    expect($security->requirementsFor(openApiSecurityRoute('/actuator/health')))->toBe([]);
});

it('names the resource server ahead of the local filter when both are somehow configured', function (): void {
    $security = new ConfiguredSecurity(openApiSecurityConfig([
        'enabled' => true,
        'jwt' => ['enabled' => true],
        'http_basic' => ['enabled' => true],
        'oauth2' => ['resource_server' => ['enabled' => true, 'jwks_uri' => 'https://idp.example.com/jwks']],
        'http' => ['enabled' => true, 'rules' => [['pattern' => '*', 'access' => 'authenticated']]],
    ]));

    $requirements = $security->requirementsFor(openApiSecurityRoute('/orders')) ?? [];

    expect(($requirements[0] ?? null)?->scheme)->toBe('oauth2ResourceServer');
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
