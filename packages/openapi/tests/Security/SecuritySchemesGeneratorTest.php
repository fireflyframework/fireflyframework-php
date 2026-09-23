<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\OpenApi\Generator\OpenApiGenerator;
use Firefly\OpenApi\Generator\OperationFactory;
use Firefly\OpenApi\Security\ConfiguredSecurity;
use Firefly\OpenApi\Security\SecurityModel;
use Firefly\OpenApi\Security\SecurityRequirement;
use Firefly\OpenApi\Security\SecurityRequirementContributor;
use Firefly\OpenApi\Security\SecurityScheme;
use Firefly\OpenApi\Security\SecuritySchemeContributor;
use Firefly\OpenApi\Tests\Support\FixtureDocument;
use Firefly\Web\Route\RouteDescriptor;
use Illuminate\Config\Repository;

/**
 * The schemes and the per-operation requirements as the GENERATOR emits them — over the real fixture
 * manifest, through the real OperationFactory, out of the real serialiser.
 *
 * The assertions that matter are the two negatives. `security` must be ABSENT from the permitAll operation
 * rather than `[]`, because an empty array in OpenAPI is the positive claim "this operation needs no
 * authentication"; and `components.securitySchemes` must be absent entirely from a document generated
 * without a SecurityModel, because an empty map is the same claim about the whole server.
 *
 * @param  array<string, mixed>  $security
 */
function schemesGeneratorConfig(array $security, bool $publish = true): Config
{
    return new Config(new Repository([
        'firefly' => ['security' => $security, 'openapi' => ['security' => ['enabled' => $publish]]],
    ]));
}

/**
 * The generator over the shared fixture manifest, with the URL rules the tests below are written against:
 * `/api/orders` is public, everything under `/api/orders/` needs a token.
 */
function schemesGenerator(bool $publish = true): OpenApiGenerator
{
    $config = schemesGeneratorConfig([
        'enabled' => true,
        'jwt' => ['enabled' => true],
        'http' => ['enabled' => true, 'rules' => [
            ['pattern' => 'api/orders', 'access' => 'permitAll'],
            ['pattern' => 'api/orders/*', 'access' => 'hasRole:ADMIN'],
        ]],
    ], $publish);

    $configured = new ConfiguredSecurity($config);
    $model = new SecurityModel([$configured], [$configured], $config);

    return new OpenApiGenerator(
        FixtureDocument::routes(),
        FixtureDocument::properties(),
        new OperationFactory(FixtureDocument::schemas(), security: $model),
        null,
        $model,
    );
}

it('publishes the configured scheme under components.securitySchemes', function () {
    /** @var array<string, array<string, mixed>> $components */
    $components = schemesGenerator()->generate()['components'];

    expect($components)->toHaveKey('securitySchemes')
        ->and($components['securitySchemes'])->toBe([
            'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
        ]);
});

it('requires the scheme on the operation a deny-by-default rule covers', function () {
    $show = FixtureDocument::operation(schemesGenerator()->generate(), '/api/orders/{id}', 'get');

    expect($show['security'])->toBe([['bearerAuth' => []]]);
});

it('leaves the security member OFF the operation a permitAll rule covers', function () {
    $create = FixtureDocument::operation(schemesGenerator()->generate(), '/api/orders', 'post');

    // `security: []` would be the claim "this needs no authentication" — true here, but a claim only worth
    // making against a document-level default this generator does not emit.
    expect($create)->not->toBeEmpty()
        ->and(array_key_exists('security', $create))->toBeFalse();
});

it('round-trips both through toJson with the requirement as a JSON array of objects', function () {
    /** @var array<string, mixed> $document */
    $document = json_decode(schemesGenerator()->toJson(), true, flags: JSON_THROW_ON_ERROR);

    /** @var array<string, array<string, mixed>> $components */
    $components = $document['components'];

    expect($components['securitySchemes'])->toHaveKey('bearerAuth')
        ->and(FixtureDocument::operation($document, '/api/orders/{id}', 'get')['security'])->toBe([['bearerAuth' => []]])
        ->and(array_key_exists('security', FixtureDocument::operation($document, '/api/orders', 'post')))->toBeFalse();
});

it('emits neither member when firefly.openapi.security.enabled is off', function () {
    $document = schemesGenerator(publish: false)->generate();

    /** @var array<string, array<string, mixed>> $components */
    $components = $document['components'];

    expect(array_key_exists('securitySchemes', $components))->toBeFalse()
        ->and(array_key_exists('security', FixtureDocument::operation($document, '/api/orders/{id}', 'get')))->toBeFalse();
});

it('emits no securitySchemes at all for a generator built without a security model', function () {
    /** @var array<string, array<string, mixed>> $components */
    $components = FixtureDocument::generator()->generate()['components'];

    expect(array_key_exists('securitySchemes', $components))->toBeFalse();
});

it('merges two contributors by name, first writer winning, sorted', function () {
    $first = new class implements SecuritySchemeContributor
    {
        public function schemes(): array
        {
            return [new SecurityScheme('oauth2AuthorizationCode', ['type' => 'oauth2'])];
        }
    };

    $second = new class implements SecuritySchemeContributor
    {
        public function schemes(): array
        {
            return [
                new SecurityScheme('oauth2AuthorizationCode', ['type' => 'apiKey']),
                new SecurityScheme('httpBasic', ['type' => 'http', 'scheme' => 'basic']),
            ];
        }
    };

    $model = new SecurityModel([$first, $second], [], schemesGeneratorConfig([]));

    expect($model->schemes())->toBe([
        'httpBasic' => ['type' => 'http', 'scheme' => 'basic'],
        'oauth2AuthorizationCode' => ['type' => 'oauth2'],
    ]);
});

it('ORs two contributors requirements and de-duplicates an identical one', function () {
    $requiring = function (string $scheme): SecurityRequirementContributor {
        return new class($scheme) implements SecurityRequirementContributor
        {
            public function __construct(private readonly string $scheme) {}

            /** @return list<SecurityRequirement> */
            public function requirementsFor(RouteDescriptor $route): array
            {
                return [new SecurityRequirement($this->scheme)];
            }
        };
    };

    $silent = new class implements SecurityRequirementContributor
    {
        public function requirementsFor(RouteDescriptor $route): ?array
        {
            return null;
        }
    };

    $route = new RouteDescriptor('GET', '/orders', 'App\\Http\\DemoController', 'show', 200, null, []);

    expect((new SecurityModel([], [$requiring('bearerAuth'), $silent, $requiring('httpBasic')], schemesGeneratorConfig([])))->requirementsFor($route))
        ->toBe([['bearerAuth' => []], ['httpBasic' => []]])
        ->and((new SecurityModel([], [$requiring('bearerAuth'), $requiring('bearerAuth')], schemesGeneratorConfig([])))->requirementsFor($route))
        ->toBe([['bearerAuth' => []]]);
});

it('lets a permitAll contributor win over one that would protect the path', function () {
    $public = new class implements SecurityRequirementContributor
    {
        /** @return list<SecurityRequirement> */
        public function requirementsFor(RouteDescriptor $route): array
        {
            return [];
        }
    };

    $protecting = new class implements SecurityRequirementContributor
    {
        /** @return list<SecurityRequirement> */
        public function requirementsFor(RouteDescriptor $route): array
        {
            return [new SecurityRequirement('bearerAuth')];
        }
    };

    $route = new RouteDescriptor('GET', '/orders', 'App\\Http\\DemoController', 'show', 200, null, []);

    // A path the framework lets through unauthenticated is public whatever anyone else believes, because
    // that is what happens at runtime — and the order the container hands the contributors over in must not
    // change the answer.
    expect((new SecurityModel([], [$public, $protecting], schemesGeneratorConfig([])))->requirementsFor($route))->toBe([])
        ->and((new SecurityModel([], [$protecting, $public], schemesGeneratorConfig([])))->requirementsFor($route))->toBe([]);
});
