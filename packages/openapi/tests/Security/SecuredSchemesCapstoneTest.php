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

it('publishes the scheme the running application authenticates with', function () {
    /** @var SecuredSchemesCapstoneTestCase $this */
    /** @var array<string, array<string, mixed>> $components */
    $components = securedSchemesDocument($this)['components'];

    expect($components['securitySchemes'])->toBe([
        'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
    ]);
});

it('requires it on the path the filter protects and not on the one it lets through', function () {
    /** @var SecuredSchemesCapstoneTestCase $this */
    $document = securedSchemesDocument($this);

    $show = FixtureDocument::operation($document, '/api/orders/{id}', 'get');
    $create = FixtureDocument::operation($document, '/api/orders', 'post');

    expect($show['security'])->toBe([['bearerAuth' => []]])
        ->and(array_key_exists('security', $create))->toBeFalse();
});

it('agrees with the filter it was generated beside', function () {
    /** @var SecuredSchemesCapstoneTestCase $this */
    // The document says GET /api/orders/{id} needs a bearer token; the running filter refuses an anonymous
    // one. The claim and the behaviour are the same fact read twice.
    $this->get('/api/orders/7')->assertStatus(401);
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
