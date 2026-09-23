<?php

declare(strict_types=1);

use Firefly\OpenApi\Tests\Support\FixtureDocument;
use Firefly\OpenApi\Tests\Support\SecuredSchemesCapstoneTestCase;

uses(SecuredSchemesCapstoneTestCase::class);

/**
 * @return array<string, mixed>
 */
function securedSchemesDocument(SecuredSchemesCapstoneTestCase $test): array
{
    /** @var array<string, mixed> $document */
    $document = json_decode($test->responseBody($test->get('/openapi.json')->assertStatus(200)), true, flags: JSON_THROW_ON_ERROR);

    return $document;
}

it('publishes every scheme the running application authenticates with', function () {
    /** @var SecuredSchemesCapstoneTestCase $this */
    /** @var array<string, array<string, mixed>> $components */
    $components = securedSchemesDocument($this)['components'];

    expect($components['securitySchemes'])->toBe([
        'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
        'httpBasic' => ['type' => 'http', 'scheme' => 'basic'],
    ]);
});

it('requires it on the path the filter protects and not on the one it lets through', function () {
    /** @var SecuredSchemesCapstoneTestCase $this */
    $document = securedSchemesDocument($this);

    $show = FixtureDocument::operation($document, '/api/orders/{id}', 'get');
    $create = FixtureDocument::operation($document, '/api/orders', 'post');

    // BOTH mechanisms, because the running server really accepts either: HttpBasicFilter (-91) and
    // JwtAuthenticationFilter (-90) each skip an Authorization header belonging to the other scheme, so a
    // document naming one of them would be withholding a credential the server would have honoured.
    expect($show['security'])->toBe([['bearerAuth' => []], ['httpBasic' => []]])
        ->and(array_key_exists('security', $create))->toBeFalse();
});

it('serves scope lists as JSON arrays, so the requirement is valid 3.1 on the wire', function () {
    /** @var SecuredSchemesCapstoneTestCase $this */
    $body = $this->responseBody($this->get('/openapi.json')->assertStatus(200));

    // The assertion above cannot see this and neither can any assoc-mode decode: `json_decode($body, true)`
    // maps `{}` to `[]`, so a requirement served as `{"bearerAuth": {}}` — invalid under the 3.1 meta-schema,
    // which types the patterned field `[string]`, and unreadable by Swagger UI — decodes to exactly what a
    // correct one does. The bytes are therefore checked as bytes, on the document the route really serves.
    expect($body)->toContain('"bearerAuth": []')
        ->and($body)->toContain('"httpBasic": []')
        ->and($body)->not->toContain('"bearerAuth": {}')
        ->and($body)->not->toContain('"httpBasic": {}');

    expect(FixtureDocument::rawValue($body, 'paths', '/api/orders/{id}', 'get', 'security'))->toBeArray()
        ->and(FixtureDocument::rawValue($body, 'paths', '/api/orders/{id}', 'get', 'security', 0))
        ->toBeInstanceOf(stdClass::class)
        ->and(FixtureDocument::rawValue($body, 'paths', '/api/orders/{id}', 'get', 'security', 0, 'bearerAuth'))
        ->toBeArray()
        ->and(FixtureDocument::rawValue($body, 'paths', '/api/orders/{id}', 'get', 'security', 1, 'httpBasic'))
        ->toBeArray();
});

it('leaves no published scheme unreferenced and names no scheme it did not publish', function () {
    /** @var SecuredSchemesCapstoneTestCase $this */
    $document = securedSchemesDocument($this);

    /** @var array<string, array<string, mixed>> $components */
    $components = $document['components'];
    /** @var array<string, mixed> $published */
    $published = $components['securitySchemes'];

    $named = [];
    /** @var array<string, array<string, mixed>> $paths */
    $paths = $document['paths'];
    foreach ($paths as $operations) {
        foreach ($operations as $operation) {
            if (! is_array($operation) || ! isset($operation['security']) || ! is_array($operation['security'])) {
                continue;
            }
            foreach ($operation['security'] as $entry) {
                foreach (array_keys((array) $entry) as $scheme) {
                    $named[(string) $scheme] = true;
                }
            }
        }
    }

    // An orphan in either direction is a lie a generated client acts on: a scheme published but never
    // required offers a caller an option no path declares usable, and a scheme required but never published
    // is a dangling reference no tooling can resolve.
    expect(array_keys($named))->toBe(array_keys($published));
});

it('agrees with the filter it was generated beside', function () {
    /** @var SecuredSchemesCapstoneTestCase $this */
    // The document says GET /api/orders/{id} needs a credential; the running filter refuses an anonymous
    // one. The claim and the behaviour are the same fact read twice.
    $this->get('/api/orders/7')->assertStatus(401);

    // And the spec route, whose permitAll rule is spelled `/openapi.json` while the filter matches against
    // `$request->path()` — `openapi.json`, no slash. The 200 is the whole leading-slash agreement: an
    // un-normalised pattern would be a dead rule, deny-by-default would take the request, and this would be
    // the same 401 as the line above.
    $this->get('/openapi.json')->assertStatus(200);
});

it('still serves a document that is valid JSON with every $ref resolving', function () {
    /** @var SecuredSchemesCapstoneTestCase $this */
    $document = securedSchemesDocument($this);

    expect($document['openapi'])->toBe('3.1.0');

    foreach (FixtureDocument::refs($document) as $ref) {
        expect(FixtureDocument::resolve($document, $ref))->not->toBeNull("dangling \$ref {$ref}");
    }
});
