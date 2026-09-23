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

/**
 * A stand-in for the package the scheme seam was cut for: an authorization server, which knows both the
 * `authorizationCode` flow it publishes and the scope list its registered clients are issued, and which has
 * no way of knowing which routes the requirement contributors will be asked about.
 *
 * @param  list<string>  $defaults
 */
function authorizationServerContributor(string $name = 'oauth2AuthorizationCode', array $defaults = ['orders.read']): SecuritySchemeContributor
{
    return new class($name, $defaults) implements SecuritySchemeContributor
    {
        /** @param list<string> $defaults */
        public function __construct(private readonly string $name, private readonly array $defaults) {}

        /** @return list<SecurityScheme> */
        public function schemes(): array
        {
            return [new SecurityScheme($this->name, ['type' => 'oauth2', 'flows' => ['authorizationCode' => []]], $this->defaults)];
        }
    };
}

/** @param list<string> $scopes */
function requiringContributor(string $scheme, array $scopes = []): SecurityRequirementContributor
{
    return new class($scheme, $scopes) implements SecurityRequirementContributor
    {
        /** @param list<string> $scopes */
        public function __construct(private readonly string $scheme, private readonly array $scopes) {}

        /** @return list<SecurityRequirement> */
        public function requirementsFor(RouteDescriptor $route): array
        {
            return [new SecurityRequirement($this->scheme, $this->scopes)];
        }
    };
}

it("gives a requirement the named scheme's default scopes when it states none of its own", function () {
    $config = schemesGeneratorConfig([]);
    $scheme = authorizationServerContributor();
    $route = new RouteDescriptor('GET', '/orders', 'App\\Http\\DemoController', 'show', 200, null, []);

    expect((new SecurityModel([$scheme], [requiringContributor('oauth2AuthorizationCode')], $config))->requirementsFor($route))
        ->toBe([['oauth2AuthorizationCode' => ['orders.read']]])
        // A requirement that states its own scopes keeps them: the operation's own claim is the specific one,
        // and overwriting it with the scheme's fallback is how a document ends up demanding every scope on
        // every path.
        ->and((new SecurityModel([$scheme], [requiringContributor('oauth2AuthorizationCode', ['orders.write'])], $config))->requirementsFor($route))
        ->toBe([['oauth2AuthorizationCode' => ['orders.write']]])
        // A name nobody contributed is published exactly as written. The document is invalid either way, and
        // quietly rewriting the entry would hide which contributor produced the dangling name.
        ->and((new SecurityModel([$scheme], [requiringContributor('schemeNobodyContributed')], $config))->requirementsFor($route))
        ->toBe([['schemeNobodyContributed' => []]])
        // A scheme with no defaults — every scheme this framework ships — leaves the empty list alone.
        ->and((new SecurityModel([authorizationServerContributor(defaults: [])], [requiringContributor('oauth2AuthorizationCode')], $config))->requirementsFor($route))
        ->toBe([['oauth2AuthorizationCode' => []]]);
});

it('takes the default scopes from the same contributor whose definition won the name', function () {
    // schemes() is first-writer-wins, and the scopes must come from that same writer: publishing one
    // contributor's flow beside another's scope list would name scopes the published flow never declares.
    $config = schemesGeneratorConfig([]);
    $route = new RouteDescriptor('GET', '/orders', 'App\\Http\\DemoController', 'show', 200, null, []);

    $model = new SecurityModel(
        [authorizationServerContributor(defaults: ['first.writer']), authorizationServerContributor(defaults: ['second.writer'])],
        [requiringContributor('oauth2AuthorizationCode')],
        $config,
    );

    expect($model->requirementsFor($route))->toBe([['oauth2AuthorizationCode' => ['first.writer']]]);
});

it('carries an inherited scope list all the way into the generated document', function () {
    // Through the real generator and the real serialiser, not only through the model: `firefly.security` is
    // absent here, so ConfiguredSecurity has no opinion about any route and the contributed pair is the
    // whole of the document's security.
    $config = schemesGeneratorConfig([]);
    $scheme = authorizationServerContributor();
    $model = new SecurityModel([$scheme], [requiringContributor('oauth2AuthorizationCode')], $config);

    $generator = new OpenApiGenerator(
        FixtureDocument::routes(),
        FixtureDocument::properties(),
        new OperationFactory(FixtureDocument::schemas(), security: $model),
        null,
        $model,
    );

    /** @var array<string, mixed> $document */
    $document = json_decode($generator->toJson(), true, flags: JSON_THROW_ON_ERROR);

    /** @var array<string, array<string, mixed>> $components */
    $components = $document['components'];

    expect(FixtureDocument::operation($document, '/api/orders/{id}', 'get')['security'])
        ->toBe([['oauth2AuthorizationCode' => ['orders.read']]])
        // The default scope list is NOT part of the Security Scheme Object: the scheme declares which scopes
        // exist, the requirement declares which ones this operation needs.
        ->and($components['securitySchemes']['oauth2AuthorizationCode'])
        ->toBe(['type' => 'oauth2', 'flows' => ['authorizationCode' => []]]);
});
