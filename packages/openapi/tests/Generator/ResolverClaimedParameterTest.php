<?php

declare(strict_types=1);

use Firefly\OpenApi\Generator\OpenApiGenerator;
use Firefly\OpenApi\Generator\OperationFactory;
use Firefly\OpenApi\Schema\ConstraintSchemaMapper;
use Firefly\OpenApi\Schema\DtoSchemaFactory;
use Firefly\OpenApi\Tests\ResolverFixture\Tag;
use Firefly\OpenApi\Tests\Support\FixtureDocument;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Web\Dispatch\HandlerMethodArgumentResolver;
use Firefly\Web\Dispatch\HandlerMethodArgumentResolvers;
use Firefly\Web\Route\RouteManifest;
use Firefly\Web\Route\RouteScanner;
use Illuminate\Http\Request;

/**
 * A parameter a registered HandlerMethodArgumentResolver claims is answered by that resolver, never by the
 * request — ArgumentResolver asks the registry BEFORE the binding kind — so it is not part of the HTTP
 * contract whatever kind its type alone planned it as. `#[AuthenticationPrincipal] ?string $sub` plans as a
 * `query` binding, and a document that read only the kind published it as a REQUIRED `?sub=` with a 400 for
 * omitting it: the exact opposite of what the server does with that parameter (the query string is never
 * read; the principal comes from the holder). The factory now applies the same test the dispatcher applies,
 * through the same registry, so the two agree by construction.
 *
 * The resolver here is a stand-in with the shape of firefly/security's; the rule under test is the
 * registry's, and it holds for any package that adds a resolver.
 *
 * @return array<string, mixed>
 */
function taggedDocument(?HandlerMethodArgumentResolvers $resolvers): array
{
    $routes = new RouteManifest((new RouteScanner)->scan([
        'Firefly\\OpenApi\\Tests\\ResolverFixture\\' => dirname(__DIR__).'/ResolverFixture',
    ]));

    return (new OpenApiGenerator(
        $routes,
        FixtureDocument::properties(),
        new OperationFactory(new DtoSchemaFactory(ConstraintManifest::fromArray([]), new ConstraintSchemaMapper), resolvers: $resolvers),
    ))->generate();
}

function tagResolvers(): HandlerMethodArgumentResolvers
{
    $resolvers = new HandlerMethodArgumentResolvers;
    $resolvers->add(new class implements HandlerMethodArgumentResolver
    {
        public function supports(array $binding): bool
        {
            /** @var list<string> $attributes */
            $attributes = $binding['attributes'] ?? [];

            return in_array(Tag::class, $attributes, true);
        }

        public function resolve(array $binding, Request $request): mixed
        {
            return 'tagged';
        }
    });

    return $resolvers;
}

/**
 * The operation's parameters keyed by name, in document order — [] when it publishes none.
 *
 * @param  array<string, mixed>  $operation
 * @return array<string, array<string, mixed>>
 */
function parametersByName(array $operation): array
{
    $byName = [];
    /** @var list<array<string, mixed>> $parameters */
    $parameters = $operation['parameters'] ?? [];
    foreach ($parameters as $parameter) {
        /** @var string $name */
        $name = $parameter['name'];
        $byName[$name] = $parameter;
    }

    return $byName;
}

// The negative control: with no registry the plan alone speaks, and a `mixed`/`?string` attributed
// parameter IS a required query parameter to it. This is the document the fix exists to stop.
it('publishes an attributed scalar as a required query parameter when no resolver registry is consulted', function () {
    $document = taggedDocument(null);
    $show = FixtureDocument::operation($document, '/tagged', 'get');

    expect(array_keys(parametersByName($show)))->toBe(['tag', 'label'])
        ->and($show['responses'])->toHaveKey('400');
});

it('leaves a parameter a registered resolver claims out of the document, and out of the 400 derivation', function () {
    $document = taggedDocument(tagResolvers());
    $show = FixtureDocument::operation($document, '/tagged', 'get');

    expect($show)->not->toHaveKey('parameters')
        ->and($show['responses'])->not->toHaveKey('400')
        ->and($show['responses'])->toHaveKeys(['200', 'default']);
});

it('keeps the parameters beside a claimed one exactly as they were', function () {
    $document = taggedDocument(tagResolvers());
    $search = FixtureDocument::operation($document, '/tagged/search', 'get');

    // `q` is a plain required string query parameter: still published, still required, still the reason a
    // 400 is documented — the claimed `label` beside it changes nothing about it.
    $parameters = parametersByName($search);

    expect(array_keys($parameters))->toBe(['q'])
        ->and($parameters['q'])->toMatchArray(['in' => 'query', 'required' => true])
        ->and($search['responses'])->toHaveKey('400');
});
