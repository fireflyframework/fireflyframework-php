<?php

declare(strict_types=1);

use Firefly\OpenApi\Generator\OpenApiGenerator;
use Firefly\OpenApi\Generator\OperationFactory;
use Firefly\OpenApi\Schema\SchemaRegistry;
use Firefly\OpenApi\Tests\NestedFixture\CreateOrderRequest;
use Firefly\OpenApi\Tests\NestedFixture\LineOptionRequest;
use Firefly\OpenApi\Tests\Support\FixtureDocument;
use Firefly\Web\Route\RouteDescriptor;
use Firefly\Web\Route\RouteManifest;

/**
 * The nested-payload half of the document, asserted on the EMITTED DOCUMENT — and, where the flaw was a
 * serialisation one, on the emitted JSON TEXT, because PHP cannot tell `[]` from `{}` once it has been
 * decoded and the whole defect lived in that distinction.
 *
 * Three defects, reproduced against a running application, all with the same root: the generator described
 * the payload's SURFACE and stopped. `#[Valid] array $lines` became `{"type": "array", "default": {}}` — no
 * `items`, so a client generator emitted `Array<any>` for the one member that most needed a type;
 * OrderLineRequest never appeared as a component at all, though the framework had already resolved it well
 * enough to HYDRATE it; and the PHP default `[]` was encoded as a JSON object, contradicting the `type: array`
 * on the line above it.
 *
 * Every assertion here runs against fixtures reached the way an application reaches them — a real
 * #[RestController] scanned by the real RouteScanner, whose compiled `dtos` table is the element-type source
 * — so a change to that table's shape breaks these tests rather than an application's generated client.
 * Members are read with FixtureDocument::resolve(), which is the document's own `$ref` resolver: a test that
 * cannot reach a node the same way a client would is testing something else.
 */
it('gives a list of DTOs an items $ref and emits the element as its own component', function () {
    $document = FixtureDocument::generatorFor('NestedFixture')->generate();

    // THE DEFECT: this was `['type' => 'array', 'default' => []]` and nothing else.
    expect(FixtureDocument::resolve($document, '#/components/schemas/CreateOrderRequest/properties/lines'))
        ->toBe([
            'description' => 'The lines to order, at least one.',
            'type' => 'array',
            'items' => ['$ref' => '#/components/schemas/OrderLineRequest'],
            'default' => [],
        ])
        // ...and the element type it names has to actually be there, or the pointer dangles and every client
        // generator aborts on it.
        ->and(FixtureDocument::resolve($document, '#/components/schemas/OrderLineRequest'))
        ->not->toBeNull();
});

it('emits a component for a DTO reachable only two lists deep', function () {
    $document = FixtureDocument::generatorFor('NestedFixture')->generate();

    // CreateOrderRequest -> lines[] -> options[] -> LineOptionRequest: a depth no #[Valid] cascade reaches,
    // since ConstraintScanner flattens exactly one level and never through an `array` member at all.
    expect(FixtureDocument::resolve($document, '#/components/schemas/OrderLineRequest/properties/options/items'))
        ->toBe(['$ref' => '#/components/schemas/LineOptionRequest'])
        ->and(FixtureDocument::resolve($document, '#/components/schemas/LineOptionRequest/properties/code'))
        ->toBe(['type' => 'string', 'pattern' => '\S']);
});

it('gives a nested component the required list its OWN constraints state', function () {
    $document = FixtureDocument::generatorFor('NestedFixture')->generate();

    // Each list is that class's own contract, not an echo of its parent's: #[NotBlank] sku, #[Min(1)]
    // quantity and a non-nullable enum with no default on the line; #[NotBlank] code on the option, whose
    // surchargeMinor has a default and so can never be omitted-and-fail.
    expect(FixtureDocument::resolve($document, '#/components/schemas/OrderLineRequest/required'))
        ->toBe(['sku', 'quantity', 'fulfilment'])
        ->and(FixtureDocument::resolve($document, '#/components/schemas/LineOptionRequest/required'))
        ->toBe(['code'])
        ->and(FixtureDocument::resolve($document, '#/components/schemas/CreateOrderRequest/required'))
        ->toBe(['reference', 'customerEmail', 'totalMinor']);
});

it('closes the cycle on a self-referential DTO whose recursion runs through a list', function () {
    $document = FixtureDocument::generatorFor('NestedFixture')->generate();

    // `items` pointing back at the component being built is what makes this terminate at all — the
    // alternative is an expansion that never ends. Reaching this assertion is most of the test.
    expect(FixtureDocument::resolve($document, '#/components/schemas/CategoryNode/properties/children'))
        ->toBe([
            'description' => 'Sub-categories, to any depth.',
            'type' => 'array',
            'items' => ['$ref' => '#/components/schemas/CategoryNode'],
            'default' => [],
        ]);
});

it('registers each reachable DTO exactly once, however many members point at it', function () {
    $document = FixtureDocument::generatorFor('NestedFixture')->generate();

    // CategoryNode is reached twice — a nullable member of the body, and its own `children` list — and
    // appears once. Duplication is what makes a client generator mint two structurally identical types.
    expect(array_keys(FixtureDocument::resolve($document, '#/components/schemas') ?? []))->toBe([
        'CategoryNode', 'CreateOrderRequest', 'LineOptionRequest', 'OrderLineRequest', 'ProblemDetails',
    ]);
});

it('resolves every $ref in the nested document against the document itself', function () {
    $document = FixtureDocument::generatorFor('NestedFixture')->generate();

    $refs = FixtureDocument::refs($document);

    expect($refs)->not->toBeEmpty();

    foreach ($refs as $ref) {
        expect(FixtureDocument::resolve($document, $ref))->not->toBeNull("dangling pointer {$ref}");
    }
});

it('inlines a list of backed enums instead of minting a component for it', function () {
    $document = FixtureDocument::generatorFor('NestedFixture')->generate();

    // An enum has no members to reflect a component out of, and a named type per enum is noise in every
    // generated client — so the accepted set is stated inline, where a reader of the list sees it.
    expect(FixtureDocument::resolve($document, '#/components/schemas/CreateOrderRequest/properties/channels'))
        ->toBe([
            'type' => 'array',
            'items' => ['type' => 'string', 'enum' => ['standard', 'express']],
            'default' => [],
        ])
        ->and(FixtureDocument::resolve($document, '#/components/schemas/Fulfilment'))->toBeNull();
});

it('states an enum-typed member as the exact set of cases it accepts', function () {
    $document = FixtureDocument::generatorFor('NestedFixture')->generate();

    expect(FixtureDocument::resolve($document, '#/components/schemas/OrderLineRequest/properties/fulfilment'))
        ->toBe(['type' => 'string', 'enum' => ['standard', 'express']]);
});

it('spells a nullable member the way OpenAPI 3.1 does, never with the 3.0 nullable keyword', function () {
    $document = FixtureDocument::generatorFor('NestedFixture')->generate();

    // 3.1 IS JSON Schema 2020-12, which dropped 3.0's `nullable: true` in favour of a type UNION. A `$ref`
    // cannot be widened by a sibling `type` there — validation keywords beside a reference are applied WITH
    // it, so `type: 'null'` would have to hold as well as the reference and never could — which is why a
    // nullable nested DTO is spelled as the union it actually is.
    expect(FixtureDocument::resolve($document, '#/components/schemas/CreateOrderRequest/properties/catalogue'))
        ->toBe(['anyOf' => [['$ref' => '#/components/schemas/CategoryNode'], ['type' => 'null']]])
        ->and(FixtureDocument::generatorFor('NestedFixture')->toJson())->not->toContain('"nullable"');
});

it('encodes an array default as a JSON array and every empty map as a JSON object', function () {
    $json = FixtureDocument::generatorFor('NestedFixture')->toJson();

    // Asserted on the TEXT because that is the only place the distinction survives: json_decode() with
    // associative arrays reads both `[]` and `{}` back as the same empty PHP array, which is the very
    // ambiguity that produced the defect. A client generator reads the text.
    //
    // THE DEFECT: `"default": {}` on a member the same schema declares `type: array` two lines above. A
    // generated client either fails to compile against its own type or ships a wrong default.
    expect($json)->toContain('"default": []')
        ->and($json)->not->toContain('"default": {}')
        // The structural rewrite this document has always needed is still in force: `paths` and an
        // unconstrained schema must serialise as maps, never as `[]`.
        ->and(json_decode($json, true, 512, JSON_THROW_ON_ERROR))->toBeArray();
});

it('leaves the Responses Object default alone even though `default` is an instance keyword', function () {
    $document = FixtureDocument::generatorFor('NestedFixture')->generate();

    // `default` names a STATUS here, not a payload value, and the node it sits in declares no `type` — which
    // is exactly the guard that keeps the array-default rule from firing outside a Schema Object.
    expect(FixtureDocument::resolve($document, '#/paths/~1api~1orders/post/responses/default'))
        ->toBe(['$ref' => '#/components/responses/Problem']);
});

it('resolves element types by reflection for a DTO reached without a compiled binding', function () {
    // #[ApiResponse(type:)] and a direct ref() both arrive with no `dtos` table — a RESPONSE has no binding
    // plan at all — and so does a route manifest compiled before RouteScanner emitted the key, which is a
    // supported state. The document must not silently lose `items` in any of the three, so the fallback path
    // is asserted to produce exactly what the compiled table produces.
    $registry = new SchemaRegistry;
    FixtureDocument::schemas('NestedFixture')->ref(CreateOrderRequest::class, $registry);

    $document = ['components' => ['schemas' => $registry->all()]];

    expect(FixtureDocument::resolve($document, '#/components/schemas/CreateOrderRequest/properties/lines/items'))
        ->toBe(['$ref' => '#/components/schemas/OrderLineRequest'])
        ->and(FixtureDocument::resolve($document, '#/components/schemas/CreateOrderRequest/properties/channels/items'))
        ->toBe(['type' => 'string', 'enum' => ['standard', 'express']])
        ->and(array_keys($registry->all()))
        ->toBe(['CategoryNode', 'CreateOrderRequest', 'LineOptionRequest', 'OrderLineRequest']);
});

it('types a list of scalars identically down the compiled path and the reflected one', function () {
    // `list<string>` names no class, so it is absent from RouteScanner's hydration table AND from
    // ElementTypes' reflection mirror of that table. It used to be published as a bare `type: array` for
    // exactly that reason, and the stated justification was drift: an `items` that only one of the two paths
    // could produce would be two implementations of one rule disagreeing about the same member.
    //
    // The type expression is therefore read where that cannot happen — AFTER both element-type paths have
    // declined, in DtoSchemaFactory, so the same step runs whichever path was taken. This asserts the
    // property the old test was protecting, rather than the missing `items` it was protecting it with: the
    // two paths agree. They now agree on `items: string` instead of on nothing.
    $compiled = FixtureDocument::resolve(
        FixtureDocument::generatorFor('NestedFixture')->generate(),
        '#/components/schemas/LineOptionRequest/properties/notes',
    );

    $registry = new SchemaRegistry;
    FixtureDocument::schemas('NestedFixture')->ref(CreateOrderRequest::class, $registry);
    $reflected = FixtureDocument::resolve(
        ['components' => ['schemas' => $registry->all()]],
        '#/components/schemas/LineOptionRequest/properties/notes',
    );

    expect($compiled)->toBe(['type' => 'array', 'items' => ['type' => 'string'], 'default' => []])
        ->and($reflected)->toBe($compiled);
});

it('reads element types out of a manifest that has been through the compiled array form', function () {
    // `firefly:cache` var_exports the manifest and production loads it back; the generator then runs against
    // THAT, never against a freshly reflected one. The `dtos` table has to survive the round trip, or a
    // cached application would document `Array<any>` while a development one documented the element type —
    // the worst possible split, because the published spec is generated from the cached side.
    $compiled = array_map(
        static fn (RouteDescriptor $route): array => $route->toArray(),
        FixtureDocument::routes('NestedFixture')->all(),
    );

    $document = (new OpenApiGenerator(
        new RouteManifest(array_map(RouteDescriptor::fromArray(...), $compiled)),
        FixtureDocument::properties(),
        new OperationFactory(FixtureDocument::schemas('NestedFixture')),
    ))->generate();

    expect(FixtureDocument::resolve($document, '#/components/schemas/CreateOrderRequest/properties/lines/items'))
        ->toBe(['$ref' => '#/components/schemas/OrderLineRequest']);
});

it('writes the document from the binding\'s compiled dtos table rather than re-reading the docblock', function () {
    $compiled = array_map(
        static fn (RouteDescriptor $route): array => $route->toArray(),
        FixtureDocument::routes('NestedFixture')->all(),
    );

    // ONE entry of the REAL compiled table is repointed at a different class. The docblock still says
    // `list<OrderLineRequest>`; the table now says LineOptionRequest. The document must follow the TABLE,
    // because the table is the statement ArgumentResolver hydrates from — a document written from a second,
    // independent reading of the docblock could describe a payload the server would refuse to build, and
    // would also pass every assertion in this file while the table was never consulted at all.
    $repointed = 0;
    foreach ($compiled as $i => $route) {
        foreach ($route['bindings'] as $j => $binding) {
            $table = $binding['dtos'] ?? [];

            if (! isset($table[CreateOrderRequest::class]['lines'])) {
                continue;
            }

            $table[CreateOrderRequest::class]['lines'] = ['class' => LineOptionRequest::class, 'list' => true];
            $binding['dtos'] = $table;
            $compiled[$i]['bindings'][$j] = $binding;
            $repointed++;
        }
    }

    // Guards the guard: if `dtos` ever stops surviving toArray()/fromArray(), this test must fail loudly
    // rather than quietly assert nothing.
    expect($repointed)->toBe(1);

    $document = (new OpenApiGenerator(
        new RouteManifest(array_map(RouteDescriptor::fromArray(...), $compiled)),
        FixtureDocument::properties(),
        new OperationFactory(FixtureDocument::schemas('NestedFixture')),
    ))->generate();

    expect(FixtureDocument::resolve($document, '#/components/schemas/CreateOrderRequest/properties/lines/items'))
        ->toBe(['$ref' => '#/components/schemas/LineOptionRequest']);
});

it('keeps a properties map a map even when a member is named after a JSON Schema keyword', function () {
    $json = FixtureDocument::generatorFor('KeywordFixture')->toJson();

    // `default`, `enum`, `example` and `type` are all legal PHP property names, and a `properties` map is
    // keyed by property name. The instance-keyword rule must never fire on that map — it is not a Schema
    // Object — and the test for that has to run against a node where the two readings DISAGREE.
    //
    // THE DEFECT: an unconstrained member named `type` gives the properties map the schema `[]`, which
    // satisfied every part of the Schema-Object shape test except emptiness. The map was then read as a
    // Schema Object declaring no type, and the sibling member named `enum` was emitted as `"enum": []` — a
    // JSON array where the meta-schema requires a Schema Object, which is exactly the flaw the empty-array
    // rewrite exists to prevent.
    expect($json)->toContain('"enum": {}')
        ->and($json)->not->toContain('"enum": []')
        ->and(FixtureDocument::resolve(
            FixtureDocument::generatorFor('KeywordFixture')->generate(),
            '#/components/schemas/KeywordRequest/required',
        ))->toBe(['reference']);
});
