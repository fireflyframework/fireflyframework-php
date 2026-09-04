<?php

declare(strict_types=1);

use Firefly\OpenApi\Tests\Support\FixtureDocument;
use Firefly\OpenApi\Tests\Support\OpenApiCapstoneTestCase;

uses(OpenApiCapstoneTestCase::class);

it('serves the generated document at the configured spec path', function () {
    /** @var OpenApiCapstoneTestCase $this */
    $response = $this->get('/openapi.json');

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toBe('application/json');

    /** @var array<string, mixed> $document */
    $document = json_decode($this->responseBody($response), true, flags: JSON_THROW_ON_ERROR);

    expect($document['openapi'])->toBe('3.1.0')
        ->and($document['info'])->toMatchArray(['title' => 'Orders API', 'version' => '1.2.3'])
        // The manifests were populated by AppScan running the real scanners over firefly.scan.paths — the
        // uncached development path — so this proves the package works without `firefly:cache` having run.
        ->and($document['paths'])->toHaveKeys(['/api/orders', '/api/orders/{id}']);
});

it('serves a document whose every $ref resolves inside itself', function () {
    /** @var OpenApiCapstoneTestCase $this */
    /** @var array<string, mixed> $document */
    $document = json_decode($this->responseBody($this->get('/openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    $refs = array_unique(FixtureDocument::refs($document));

    expect($refs)->not->toBeEmpty();
    foreach ($refs as $ref) {
        expect(FixtureDocument::resolve($document, $ref))->not->toBeNull("dangling \$ref {$ref}");
    }
});

it('serves the viewer as HTML that points back at the spec route', function () {
    /** @var OpenApiCapstoneTestCase $this */
    $response = $this->get('/openapi');

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toBe('text/html; charset=UTF-8');

    $html = $this->responseBody($response);

    expect($html)->toStartWith('<!DOCTYPE html>')
        ->and($html)->toContain('/openapi.json')
        ->and($html)->toContain('Orders API');
});

it('does not document its own two routes', function () {
    /** @var OpenApiCapstoneTestCase $this */
    /** @var array<string, mixed> $document */
    $document = json_decode($this->responseBody($this->get('/openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    /** @var array<string, mixed> $paths */
    $paths = $document['paths'];

    // Both routes are mounted natively on the Router from a BootPass, so they never enter the RouteManifest
    // the generator reads. That is a consequence of the configurable-path design, not an extra filter — and
    // it is the reason this package ships no #[RestController] of its own.
    expect($paths)->not->toHaveKey('/openapi.json')
        ->and($paths)->not->toHaveKey('/openapi');
});

it('still dispatches the application routes it documents', function () {
    /** @var OpenApiCapstoneTestCase $this */
    // Mounting the framework's own two routes must not disturb M6's route wiring for the app's controllers.
    $this->getJson('/api/orders/abc?expand=1')
        ->assertStatus(200)
        ->assertJsonPath('id', 'abc')
        ->assertJsonPath('expand', true);
});
