<?php

declare(strict_types=1);

use Firefly\OpenApi\Tests\Support\FixtureDocument;

/**
 * The "documented by attributes" half of springdoc parity — the same merged-document assertions, over a
 * fixture whose docblocks deliberately DISAGREE with its attributes.
 *
 * That disagreement is the design of the fixture. A controller whose two sources say the same thing passes
 * whichever one the generator actually read, so InventoryController's class docblock and its `level()`
 * docblock both state text that must NOT appear anywhere in the output: the assertions below are as much
 * about what is absent as about what is present.
 *
 * @return array<string, mixed>
 */
function annotatedDocument(): array
{
    return FixtureDocument::generatorFor('AttributeFixture')->generate();
}

it('lets #[ApiOperation] beat the docblock for summary, description, operationId and tags', function () {
    $operation = FixtureDocument::operation(annotatedDocument(), '/inventory/{sku}', 'get');

    expect($operation['summary'])->toBe('Read one stock level')
        ->and($operation['description'])->toBe('Live and uncached: the number returned is the number the warehouse would pick against right now.')
        ->and($operation['operationId'])->toBe('stockLevel')
        ->and($operation['tags'])->toBe(['Warehouse', 'Reporting']);
});

it('leaves the docblock in place for every #[ApiOperation] member the author omitted', function () {
    // #[ApiOperation(deprecated: true)] and nothing else: an omitted member falls THROUGH rather than
    // blanking what the docblock said, which is the whole precedence rule in one operation.
    $operation = FixtureDocument::operation(annotatedDocument(), '/inventory/adjustments', 'post');

    expect($operation['summary'])->toBe('Apply a manual stock correction.')
        ->and($operation['description'])->toStartWith('The summary and description here survive')
        ->and($operation['deprecated'])->toBeTrue()
        // No operationId was stated, so the derivation still applies.
        ->and($operation['operationId'])->toBe('inventoryAdjust');
});

it('never leaks the docblock text an attribute overrode', function () {
    $json = FixtureDocument::generatorFor('AttributeFixture')->toJson();

    expect($json)->not->toContain('A summary the attribute overrides')
        ->and($json)->not->toContain('A class docblock that must NOT reach the document')
        ->and($json)->not->toContain('A property docblock the attribute beside it must beat');
});

it('names and describes the tag from #[ApiTag] rather than from the class', function () {
    /** @var list<array{name: string, description: string}> $tags */
    $tags = annotatedDocument()['tags'];

    expect($tags)->toBe([[
        'name' => 'Warehouse',
        'description' => 'Stock levels, movements and manual adjustments.',
    ]]);
});

it('lists a tag at the root only when something describes it', function () {
    $document = annotatedDocument();

    /** @var list<array{name: string, description: string}> $tags */
    $tags = $document['tags'];
    $names = array_map(static fn (array $tag): string => $tag['name'], $tags);

    // `Reporting` is used by an operation but nothing describes it, and a root entry carrying only a name
    // restates what the operation already says.
    expect(FixtureDocument::operation($document, '/inventory/{sku}', 'get')['tags'])->toContain('Reporting')
        ->and($names)->not->toContain('Reporting');
});

it('adds an #[ApiResponse] and lets one replace a derived response of the same status', function () {
    /** @var array<array-key, mixed> $responses */
    $responses = FixtureDocument::operation(annotatedDocument(), '/inventory/{sku}', 'get')['responses'];

    // Numeric statuses ascending, `default` last — never in the order the attributes happen to be written.
    expect(array_map(strval(...), array_keys($responses)))->toBe(['200', '404', 'default'])
        ->and($responses[200])->toBe([
            'description' => 'The current stock level.',
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/StockLevel']]],
        ])
        // No `type`, so the response is documented as bodiless rather than given an invented shape.
        ->and($responses[404])->toBe(['description' => 'No such stock-keeping unit.']);
});

it('registers an #[ApiResponse] payload type as a component like any body DTO', function () {
    $document = annotatedDocument();

    /** @var array<string, array<string, array<string, mixed>>> $components */
    $components = $document['components'];

    expect($components['schemas'])->toHaveKey('StockLevel')
        ->and($components['schemas']['StockLevel']['required'])->toBe(['sku', 'onHand']);

    foreach (array_unique(FixtureDocument::refs($document)) as $ref) {
        expect(FixtureDocument::resolve($document, $ref))->not->toBeNull("dangling \$ref {$ref}");
    }
});

it('enriches a bound parameter with #[ApiParameter] and ignores a name nothing binds', function () {
    /** @var list<array<string, mixed>> $parameters */
    $parameters = FixtureDocument::operation(annotatedDocument(), '/inventory/{sku}', 'get')['parameters'];

    $byName = [];
    foreach ($parameters as $parameter) {
        /** @var string $name */
        $name = $parameter['name'];
        $byName[$name] = $parameter;
    }

    // `tenant` was claimed by an #[ApiParameter] and bound by nothing, so it is dropped: the binding plan is
    // the only honest statement of what this endpoint reads, and a parameter the dispatcher never looks at
    // would document an API that does not exist.
    expect(array_keys($byName))->toBe(['sku', 'at'])
        ->and($byName['sku']['description'])->toBe('The stock-keeping unit to read.')
        ->and($byName['sku']['example'])->toBe('ACME-001')
        // The query parameter's PHP signature defaults it to null, so the binding plan says optional; the
        // attribute says otherwise and wins, because a document may be reshaped by its author.
        ->and($byName['at']['required'])->toBeTrue()
        ->and($byName['at']['example'])->toBe('2026-01-01T00:00:00Z');
});

it('keeps a path parameter required whatever an #[ApiParameter] claims', function () {
    /** @var list<array<string, mixed>> $parameters */
    $parameters = FixtureDocument::operation(annotatedDocument(), '/inventory/{sku}', 'get')['parameters'];

    // `required: false` on a path parameter is invalid under the 3.1 meta-schema, so the override is dropped
    // rather than allowed to produce a document a strict validator rejects.
    expect($parameters[0]['name'])->toBe('sku')
        ->and($parameters[0]['in'])->toBe('path')
        ->and($parameters[0]['required'])->toBeTrue();
});

it('enriches a DTO member with #[ApiProperty] and spells the example the 3.1 way', function () {
    /** @var array<string, array<string, array<string, mixed>>> $components */
    $components = annotatedDocument()['components'];
    /** @var array<string, array<string, mixed>> $properties */
    $properties = $components['schemas']['AdjustmentRequest']['properties'];

    expect($properties['sku']['description'])->toBe('The stock-keeping unit being corrected.')
        // 3.1 aligned the Schema Object with JSON Schema 2020-12 and deprecated the singular `example`.
        ->and($properties['sku']['examples'])->toBe(['ACME-001'])
        ->and($properties['sku'])->not->toHaveKey('example')
        ->and($properties['delta']['examples'])->toBe([-3]);
});

it('lets an #[ApiProperty] format overwrite the constraint-derived one and mark a member deprecated', function () {
    /** @var array<string, array<string, array<string, mixed>>> $components */
    $components = annotatedDocument()['components'];
    /** @var array<string, array<string, mixed>> $properties */
    $properties = $components['schemas']['AdjustmentRequest']['properties'];

    // #[Email] compiles to `format: email`; the author said something more precise and there is only one
    // `format` slot per schema.
    expect($properties['countedBy']['format'])->toBe('idn-email')
        ->and($properties['countedBy']['deprecated'])->toBeTrue()
        // Nothing described it, so nothing is invented in place of a description.
        ->and($properties['countedBy'])->not->toHaveKey('description');
});

it('leaves an #[ApiIgnore] method and an #[ApiIgnore] class out of the document entirely', function () {
    $document = annotatedDocument();

    /** @var array<string, mixed> $paths */
    $paths = $document['paths'];

    expect(array_keys($paths))->toBe(['/inventory/adjustments', '/inventory/{sku}', '/inventory/{sku}/closing/{period}']);

    // A hidden controller must leave NO trace: not a path, not an orphan tag description advertising the
    // group it was hidden to conceal.
    $json = FixtureDocument::generatorFor('AttributeFixture')->toJson();

    expect($json)->not->toContain('/internal')
        ->and($json)->not->toContain('reconciliation')
        ->and($json)->not->toContain('Back-office tooling');
});

it('still enforces operationId uniqueness over an id an attribute chose', function () {
    $document = annotatedDocument();

    // Both methods declare #[ApiOperation(operationId: 'stockLevel')]. A duplicate operationId is the single
    // flaw that makes most client generators abort rather than degrade, so the second claimant is suffixed:
    // the attribute picks the name, the document keeps its invariant.
    expect(FixtureDocument::operation($document, '/inventory/{sku}', 'get')['operationId'])->toBe('stockLevel')
        ->and(FixtureDocument::operation($document, '/inventory/{sku}/closing/{period}', 'get')['operationId'])->toBe('stockLevel_2');
});
