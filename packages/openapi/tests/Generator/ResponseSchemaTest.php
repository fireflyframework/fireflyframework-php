<?php

declare(strict_types=1);

use Firefly\OpenApi\Tests\Support\FixtureDocument;

/**
 * What an operation RETURNS, which the generated document did not state.
 *
 * Every success response in every document this package produced was `{"type": "object"}` — an object with
 * no members. A viewer renders that as a blank panel; `openapi-generator` turns it into `any`. So the most
 * useful sentence an API document contains ("here is what you get back") was the one sentence missing, for
 * every endpoint, in every application.
 *
 * The shape was never unavailable. It was in the `@return` one line above the method, where PHPStan at level
 * max already checks it against the code on every build — which is exactly what makes reading it safe, and
 * is the same reason RouteScanner reads `@param` to compile the table ArgumentResolver hydrates from.
 */
$document = static fn (): array => FixtureDocument::generatorFor('ResponseFixture')->generate();

it('expands an array-shape return into a real object schema', function () use ($document) {
    $schema = FixtureDocument::resolve($document(), '#/paths/~1api~1consignments/get/responses/200/content/application~1json/schema');

    expect($schema)->toBe([
        'type' => 'object',
        'properties' => [
            'page' => ['type' => 'integer', 'minimum' => 1],
            'size' => ['type' => 'integer', 'minimum' => 1],
            'total' => ['type' => 'integer'],
            'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Consignment']],
        ],
        'required' => ['page', 'size', 'total', 'items'],
        // A shape names every member it has, and saying so is what lets a generator emit a struct rather
        // than a struct plus a bag. An author who means otherwise writes the `...`.
        'additionalProperties' => false,
    ]);
});

it('refs a class return rather than flattening it', function () use ($document) {
    expect(FixtureDocument::resolve($document(), '#/paths/~1api~1consignments~1{reference}/get/responses/200/content/application~1json/schema'))
        ->toBe(['$ref' => '#/components/schemas/Consignment']);
});

it('builds a response component from the wire shape, not from the property list', function () use ($document) {
    /** @var array{properties: array<string, mixed>, description: string} $schema */
    $schema = FixtureDocument::resolve($document(), '#/components/schemas/Consignment');

    // Consignment is JsonSerializable, so `json_encode` emits what jsonSerialize() RETURNS. That is neither
    // the constructor's parameters nor the public properties: `weightGrams` is a derived method that IS
    // published, and `$auditTrail`/`$parcelGrams` are private and are NOT. Reflecting properties would get
    // both halves wrong in the same schema.
    expect(array_keys($schema['properties']))
        ->toBe(['reference', 'declaredValue', 'shipments', 'weightGrams'])
        ->and($schema['properties']['weightGrams'])->toBe(['type' => 'integer', 'minimum' => 1])
        ->and($schema['properties']['declaredValue'])->toBe(['$ref' => '#/components/schemas/Money'])
        ->and($schema['properties']['shipments'])->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Shipment']])
        ->and($schema['description'])->toStartWith('A consignment as the API publishes it.');
});

it('reflects public properties for a class that declares no shape', function () use ($document) {
    /** @var array{properties: array<string, mixed>, required: list<string>} $schema */
    $schema = FixtureDocument::resolve($document(), '#/components/schemas/Shipment');

    // No JsonSerializable, so the wire shape IS the public property list — which is what json_encode walks.
    // A nullable member stays REQUIRED and widens its type instead: a response member is present or absent,
    // and `?string $tracking` is always present and sometimes null. Marking it optional would tell a client
    // to expect its absence, and it never is.
    expect($schema['properties']['carrier'])->toBe(['type' => 'string'])
        ->and($schema['properties']['tracking'])->toBe(['type' => ['string', 'null']])
        ->and($schema['properties']['expectedAt'])->toBe(['type' => ['string', 'null'], 'format' => 'date-time'])
        ->and($schema['properties']['checkpoints'])->toBe([
            'description' => 'where it has been scanned, oldest first',
            'type' => 'array',
            'items' => ['type' => 'string'],
        ])
        ->and($schema['required'])->toBe(['carrier', 'tracking', 'checkpoints', 'expectedAt']);
});

it('types a bare list return and takes its description from the same line', function () use ($document) {
    /** @var array{description: string, content: array<string, array<string, mixed>>} $response */
    $response = FixtureDocument::resolve($document(), '#/paths/~1api~1consignments~1{reference}~1shipments/get/responses/200');

    // The prose after a type expression is the only response description an author ever actually writes, so
    // it is split off the same line rather than discarded.
    expect($response['description'])->toBe('newest first')
        ->and($response['content']['application/json']['schema'])
        ->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Shipment']]);
});

it('documents a string-keyed map as an object, not as an array', function () use ($document) {
    // `array<string, Money>` is a JSON object. Publishing it as `type: array` — which is what a bare PHP
    // `array` degrades to — is not merely vague, it is the wrong JSON type, and a generated client fails to
    // decode the payload the server actually sends.
    expect(FixtureDocument::resolve($document(), '#/paths/~1api~1consignments~1totals/get/responses/200/content/application~1json/schema'))
        ->toBe(['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Money']]);
});

it('keeps type: object for an array return that says nothing about itself', function () use ($document) {
    // `@return array<string, mixed>` parses fine and means "an object, members unknown" — strictly less than
    // nothing, since accepting it would suppress whatever the declared type knew. The old behaviour is the
    // FALLBACK now rather than the answer, and this is the case it is still correct for.
    expect(FixtureDocument::resolve($document(), '#/paths/~1api~1consignments/post/responses/201/content/application~1json/schema'))
        ->toBe(['type' => 'object']);
});

it('lets #[ApiResponse] name a class or a full type expression', function () use ($document) {
    /** @var array<array-key, array{content: array<string, array<string, mixed>>}> $responses */
    $responses = FixtureDocument::operation($document(), '/api/consignments', 'post')['responses'];

    expect($responses[409]['content']['application/json']['schema'])
        ->toBe(['$ref' => '#/components/schemas/Consignment'])
        ->and($responses[202]['content']['application/json']['schema'])
        ->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Shipment']]);
});

it('still writes no content for a 204', function () use ($document) {
    expect(FixtureDocument::resolve($document(), '#/paths/~1api~1consignments~1{reference}/delete/responses/204'))
        ->toBe(['description' => 'No content.']);
});
