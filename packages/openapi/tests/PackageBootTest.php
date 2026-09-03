<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\OpenApi\Generator\DocumentInfo;
use Firefly\OpenApi\Generator\OpenApiGenerator;
use Firefly\OpenApi\OpenApiProperties;
use Firefly\OpenApi\OpenApiServiceProvider;
use Firefly\OpenApi\OpenApiWiringProvider;
use Firefly\OpenApi\Schema\ConstraintSchemaMapper;
use Firefly\OpenApi\Web\ViewerPage;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Web\Route\RouteManifest;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;

/**
 * A BARE skeleton: AutoConfigure plus this package's two providers, with the two cross-package manifests
 * stubbed through the harness's `bindings:` menu rather than by registering WebServiceProvider's whole boot
 * pipeline — the same "stub the seam, don't drag in the sibling's boot" idiom actuator's own PackageBootTest
 * uses. Whether firefly/web binds a REAL, populated RouteManifest is firefly/web's test to write (and the
 * HTTP capstone here proves the two compose); this test's only job is: given SOME manifests exist, does this
 * package's own wiring resolve without crashing eager singleton resolution?
 *
 * @param  array<string, mixed>  $openapi
 */
function bootOpenApiApp(array $openapi = []): Application
{
    return fireflyApplication(
        config: ['firefly' => ['openapi' => $openapi]],
        providers: [OpenApiServiceProvider::class, OpenApiWiringProvider::class],
        bindings: [
            RouteManifest::class => new RouteManifest([]),
            ConstraintManifest::class => new ConstraintManifest([]),
        ],
    );
}

it('boots a bare skeleton with the openapi providers registered', function () {
    expect(bootOpenApiApp()->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class);
});

it('binds every pipeline bean the compiled manifest describes', function () {
    $app = bootOpenApiApp();

    expect($app->make(OpenApiProperties::class))->toBeInstanceOf(OpenApiProperties::class)
        ->and($app->make(ConstraintSchemaMapper::class))->toBeInstanceOf(ConstraintSchemaMapper::class)
        ->and($app->make(OpenApiGenerator::class))->toBeInstanceOf(OpenApiGenerator::class)
        ->and($app->make(ViewerPage::class))->toBeInstanceOf(ViewerPage::class);
});

it('generates a valid, empty-but-well-formed document from empty manifests', function () {
    /** @var OpenApiGenerator $generator */
    $generator = bootOpenApiApp()->make(OpenApiGenerator::class);

    $json = $generator->toJson();

    // An app with no routes must still produce a document a validator accepts — `"paths": {}`, never `[]`.
    expect($json)->toContain('"paths": {}')
        ->and($generator->generate()['openapi'])->toBe('3.1.0');
});

it('mounts both routes on the router by default', function () {
    /** @var Router $router */
    $router = bootOpenApiApp()->make('router');

    $names = [];
    foreach ($router->getRoutes()->getRoutes() as $route) {
        $names[] = $route->getName();
    }

    expect($names)->toContain('firefly.openapi.spec')
        ->and($names)->toContain('firefly.openapi.viewer');
});

it('mounts nothing when the master gate is off', function () {
    /** @var Router $router */
    $router = bootOpenApiApp(['enabled' => false])->make('router');

    expect($router->getRoutes()->getRoutes())->toBe([]);
});

/**
 * The Info Object's optional members are read from config by a bean, not by a test helper. DocumentInfoTest
 * proves the OBJECT behaves; this proves the WIRING exists — that `firefly.openapi.license.name` in an
 * application's config file reaches the generated document at all.
 *
 * It is a separate test because the two can fail independently, and the interesting failure is the silent
 * one: DocumentInfo can be perfectly correct and perfectly unreachable if nothing constructs it. The
 * generator's fourth constructor argument is optional, so a bean that forgets to pass it still compiles,
 * still boots, and still produces a document — just never the configured one.
 */
it('feeds the configured Info Object members into the generated document', function () {
    /** @var OpenApiGenerator $generator */
    $generator = bootOpenApiApp([
        'title' => 'Warehouse API',
        'version' => '2.0.0',
        'summary' => 'Everything the warehouse exposes.',
        'terms-of-service' => 'https://example.test/terms',
        'contact' => ['name' => 'Platform Team', 'email' => 'api@example.test'],
        'license' => ['name' => 'Apache 2.0', 'identifier' => 'Apache-2.0'],
    ])->make(OpenApiGenerator::class);

    /** @var array<string, mixed> $info */
    $info = $generator->generate()['info'];

    expect($info)->toBe([
        'title' => 'Warehouse API',
        'summary' => 'Everything the warehouse exposes.',
        'termsOfService' => 'https://example.test/terms',
        'contact' => ['name' => 'Platform Team', 'email' => 'api@example.test'],
        'license' => ['name' => 'Apache 2.0', 'identifier' => 'Apache-2.0'],
        'version' => '2.0.0',
    ]);
});

it('resolves a DocumentInfo bean that an application has not configured', function () {
    // The bean must exist unconditionally, so that the generator's dependency is always satisfiable — an app
    // that has never heard of these keys still boots, and still gets the document it got before.
    $app = bootOpenApiApp();

    expect($app->make(DocumentInfo::class))->toBeInstanceOf(DocumentInfo::class)
        ->and($app->make(OpenApiGenerator::class)->generate()['info'])
        ->toBe(['title' => 'API', 'version' => '0.0.0']);
});
