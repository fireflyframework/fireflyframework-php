<?php

declare(strict_types=1);

use Firefly\OpenApi\Schema\ProblemSchema;
use Firefly\OpenApi\Tests\Support\FixtureDocument;

/**
 * Structural assertions, never a snapshot. A blob comparison would fail on every harmless wording change and
 * would still not tell anyone WHICH invariant broke; these assert the properties a consumer actually depends
 * on — the document declares 3.1, every operation the manifest describes is present under the right verb and
 * path, every `$ref` resolves, `required` says what the constraints say, and the serialisation is valid JSON
 * with maps encoded as maps.
 */
it('emits a 3.1 document with the required top-level members', function () {
    $document = FixtureDocument::generator()->generate();

    expect($document['openapi'])->toBe('3.1.0')
        ->and($document)->toHaveKeys(['openapi', 'info', 'paths', 'components'])
        ->and($document['info'])->toBe(['title' => 'Orders API', 'version' => '1.2.3', 'description' => 'The fixture API.']);
});

it('maps every scanned route onto a path item keyed by its lowercased verb', function () {
    /** @var array<string, array<string, mixed>> $paths */
    $paths = FixtureDocument::generator()->generate()['paths'];

    expect(array_keys($paths))->toBe(['/api/orders', '/api/orders/{id}'])
        ->and(array_keys($paths['/api/orders']))->toBe(['post'])
        ->and(array_keys($paths['/api/orders/{id}']))->toBe(['get', 'delete']);
});

it('prefers the route name as the operationId and derives a unique one otherwise', function () {
    /** @var array<string, array<string, array<string, mixed>>> $paths */
    $paths = FixtureDocument::generator()->generate()['paths'];

    expect($paths['/api/orders']['post']['operationId'])->toBe('orders.create')
        ->and($paths['/api/orders/{id}']['get']['operationId'])->toBe('orderShow')
        ->and($paths['/api/orders/{id}']['delete']['operationId'])->toBe('orderCancel');
});

it('turns path, query and header bindings into parameters and leaves service bindings out', function () {
    /** @var array<string, array<string, array<string, mixed>>> $paths */
    $paths = FixtureDocument::generator()->generate()['paths'];
    /** @var list<array<string, mixed>> $parameters */
    $parameters = $paths['/api/orders/{id}']['get']['parameters'];

    $byName = [];
    foreach ($parameters as $parameter) {
        /** @var string $name */
        $name = $parameter['name'];
        $byName[$name] = $parameter;
    }

    expect(array_keys($byName))->toBe(['id', 'expand', 'X-Tenant'])
        ->and($byName['id']['in'])->toBe('path')
        ->and($byName['id']['required'])->toBeTrue()
        ->and($byName['expand']['in'])->toBe('query')
        ->and($byName['expand']['required'])->toBeFalse()
        ->and($byName['expand']['schema'])->toBe(['type' => 'boolean', 'default' => false])
        ->and($byName['X-Tenant']['in'])->toBe('header');
});

it('references the body DTO by $ref rather than inlining it', function () {
    /** @var array<string, array<string, array<string, mixed>>> $paths */
    $paths = FixtureDocument::generator()->generate()['paths'];
    /** @var array<string, mixed> $body */
    $body = $paths['/api/orders']['post']['requestBody'];

    expect($body['required'])->toBeTrue()
        ->and($body['content'])->toBe([
            'application/json' => ['schema' => ['$ref' => '#/components/schemas/CreateOrderRequest']],
        ]);
});

it('derives the body schema properties and required list from the compiled constraints', function () {
    /** @var array<string, array<string, array<string, mixed>>> $components */
    $components = FixtureDocument::generator()->generate()['components'];
    /** @var array<string, mixed> $schema */
    $schema = $components['schemas']['CreateOrderRequest'];

    /** @var array<string, array<string, mixed>> $properties */
    $properties = $schema['properties'];

    expect($schema['type'])->toBe('object')
        // #[NotBlank] and #[NotNull] make a member required; a nullable member with a default does not.
        ->and($schema['required'])->toBe(['reference', 'email', 'quantity', 'amount', 'currency', 'shipTo'])
        // #[Size(max: 64)] compiles to a Size rule OBJECT and must measure LENGTH, never magnitude.
        ->and($properties['reference'])->toMatchArray(['type' => 'string', 'maxLength' => 64])
        ->and($properties['email'])->toMatchArray(['type' => 'string', 'format' => 'email'])
        // #[Min]/#[Max] emit `numeric` + gte/lte; the DECLARED int must survive that widening.
        ->and($properties['quantity'])->toBe(['type' => 'integer', 'minimum' => 1, 'maximum' => 999])
        ->and($properties['amount'])->toBe(['type' => 'number', 'exclusiveMinimum' => 0, 'multipleOf' => 0.01])
        // A backed enum is documented from the TYPE — no constraint states this set anywhere.
        ->and($properties['currency'])->toBe(['type' => 'string', 'enum' => ['EUR', 'USD']])
        // Jakarta's null contract: a nullable member is a type UNION in 3.1, not a `nullable` keyword.
        ->and($properties['coupon']['type'])->toBe(['string', 'null'])
        ->and($properties['coupon']['pattern'])->toBe('^[A-Z]{3}-\d{4}$');
});

it('gives a nested #[Valid] DTO its own component and reaches it by $ref', function () {
    $document = FixtureDocument::generator()->generate();
    /** @var array<string, array<string, array<string, mixed>>> $components */
    $components = $document['components'];

    /** @var array<string, array<string, mixed>> $properties */
    $properties = $components['schemas']['CreateOrderRequest']['properties'];

    expect($properties['shipTo'])->toBe(['$ref' => '#/components/schemas/AddressPayload'])
        ->and($components['schemas'])->toHaveKey('AddressPayload')
        ->and($components['schemas']['AddressPayload']['required'])->toBe(['line1', 'postcode']);
});

it('attaches the shared problem response to every operation', function () {
    $document = FixtureDocument::generator()->generate();
    /** @var array<string, array<string, array<string, mixed>>> $paths */
    $paths = $document['paths'];

    foreach ($paths as $item) {
        foreach ($item as $operation) {
            /** @var array<string, mixed> $responses */
            $responses = $operation['responses'];
            expect($responses['default'])->toBe(['$ref' => ProblemSchema::RESPONSE_REF]);
        }
    }

    /** @var array<string, array<string, mixed>> $components */
    $components = $document['components'];

    expect($components['responses'])->toHaveKey(ProblemSchema::RESPONSE_NAME)
        ->and($components['schemas'])->toHaveKey(ProblemSchema::NAME);
});

it('documents 422 only where a binding carries #[Valid], and 400 only where binding can fail', function () {
    /** @var array<string, array<string, array<string, mixed>>> $paths */
    $paths = FixtureDocument::generator()->generate()['paths'];

    /** @var array<array-key, mixed> $create */
    $create = $paths['/api/orders']['post']['responses'];
    /** @var array<array-key, mixed> $show */
    $show = $paths['/api/orders/{id}']['get']['responses'];
    /** @var array<array-key, mixed> $cancel */
    $cancel = $paths['/api/orders/{id}']['delete']['responses'];

    // Status keys are compared as strings because PHP silently coerces the numeric ones to INTEGER array
    // keys — '201' becomes 201 the moment it is written. That coercion is harmless in the document itself
    // (a map whose keys are 201/400/'default' is not a PHP list, so json_encode still writes an object), but
    // it is a real trap for anyone asserting against generate()'s raw array, so the tests normalise rather
    // than quietly expecting ints.
    $statuses = static fn (array $responses): array => array_map(strval(...), array_keys($responses));

    expect($statuses($create))->toBe(['201', '400', '422', 'default'])
        // A bool query parameter must be coerced out of the wire's string, so 400 is reachable.
        ->and($statuses($show))->toBe(['200', '400', 'default'])
        // A single `string` path variable cannot fail binding at all — no phantom 400.
        ->and($statuses($cancel))->toBe(['204', 'default'])
        ->and($cancel[204])->toBe(['description' => 'No content.']);
});

it('resolves every local $ref it emits', function () {
    $document = FixtureDocument::generator()->generate();
    $refs = FixtureDocument::refs($document);

    expect($refs)->not->toBeEmpty();

    foreach (array_unique($refs) as $ref) {
        expect(FixtureDocument::resolve($document, $ref))->not->toBeNull("dangling \$ref {$ref}");
    }
});

it('never emits an empty required array', function () {
    $document = FixtureDocument::generator()->generate();

    $walk = function (mixed $node) use (&$walk): void {
        if (! is_array($node)) {
            return;
        }
        if (array_key_exists('required', $node) && $node['required'] === []) {
            throw new RuntimeException('an empty `required` array is invalid under the OpenAPI 3.1 meta-schema');
        }
        foreach ($node as $child) {
            $walk($child);
        }
    };

    expect(fn () => $walk($document))->not->toThrow(RuntimeException::class);
});

it('serialises an empty map as a JSON object, never as an empty array', function () {
    // An app with no documented routes is the case that catches this: PHP spells an empty map [], and
    // `"paths": []` is a type error against the OpenAPI 3.1 meta-schema that a strict validator rejects
    // outright. toJson() is the only serialisation that guarantees the fix, which is why it exists.
    $json = FixtureDocument::generator(FixtureDocument::properties(exclude: ['/api']))->toJson();

    expect($json)->toContain('"paths": {}')
        ->and($json)->not->toContain('"paths": []');

    /** @var stdClass $decoded */
    $decoded = json_decode($json, false, flags: JSON_THROW_ON_ERROR);

    expect($decoded)->toBeInstanceOf(stdClass::class)
        ->and($decoded->paths)->toBeInstanceOf(stdClass::class);
});

it('omits servers when none are configured and emits them when they are', function () {
    expect(FixtureDocument::generator()->generate())->not->toHaveKey('servers');

    $document = FixtureDocument::generator(FixtureDocument::properties(servers: [['url' => 'https://api.test', 'description' => 'prod']]))->generate();

    expect($document['servers'])->toBe([['url' => 'https://api.test', 'description' => 'prod']]);
});

it('drops routes under a configured exclude prefix', function () {
    $document = FixtureDocument::generator(FixtureDocument::properties(exclude: ['/api/orders']))->generate();

    expect($document['paths'])->toBe([]);
});

it('is deterministic across generations', function () {
    expect(FixtureDocument::generator()->toJson())->toBe(FixtureDocument::generator()->toJson());
});
