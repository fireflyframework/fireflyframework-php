<?php

declare(strict_types=1);

use Firefly\OpenApi\Generator\OpenApiGenerator;
use Firefly\OpenApi\Generator\OperationFactory;
use Firefly\OpenApi\Schema\ConstraintSchemaMapper;
use Firefly\OpenApi\Schema\DtoSchemaFactory;
use Firefly\OpenApi\Tests\Support\FixtureDocument;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Web\Route\RouteManifest;
use Firefly\Web\Route\RouteScanner;

/**
 * The two document-level invariants the main fixture surface cannot exercise, because neither shape occurs
 * in it: a DUPLICATE operationId candidate, and an OPTIONAL path variable.
 *
 * Both are load-bearing rather than cosmetic. A repeated operationId is the single flaw that makes most
 * client generators abort outright rather than degrade, and `{slug?}` is not a legal OpenAPI path template
 * at all — a consumer that sees the literal `?` either rejects the document or emits a parameter named
 * `slug?`. Neither can be caught by a `$ref`-resolution or schema-shape assertion, so they get their own
 * fixture namespace here, still scanned by the REAL RouteScanner (FixtureDocument's rule), so the test
 * asserts what the framework actually produces rather than what its author believed it produces.
 */
/**
 * @return array<string, mixed>
 */
function edgeDocument(): array
{
    $routes = new RouteManifest((new RouteScanner)->scan([
        'Firefly\\OpenApi\\Tests\\EdgeFixture\\' => dirname(__DIR__).'/EdgeFixture',
    ]));

    return (new OpenApiGenerator(
        $routes,
        FixtureDocument::properties(),
        new OperationFactory(new DtoSchemaFactory(ConstraintManifest::fromArray([]), new ConstraintSchemaMapper)),
    ))->generate();
}

/**
 * @param  array<string, mixed>  $document
 * @return list<string>
 */
function edgeOperationIds(array $document): array
{
    $ids = [];

    /** @var array<string, array<string, array<string, mixed>>> $paths */
    $paths = $document['paths'];
    foreach ($paths as $item) {
        foreach ($item as $operation) {
            expect($operation)->toHaveKey('operationId');

            $id = $operation['operationId'];
            expect($id)->toBeString();

            /** @var string $id */
            $ids[] = $id;
        }
    }

    return $ids;
}

it('suffixes a duplicate operationId instead of letting one operation overwrite the other', function () {
    $document = edgeDocument();

    // Both controllers are named ReportController::index, so derivedId() offers `reportIndex` twice. Which
    // route claims the bare id depends on filesystem scan order, so the assertion is on the SET, not on the
    // assignment: two operations survive, both are present, and the ids are distinct.
    expect($document['paths'])->toHaveCount(2);

    $ids = edgeOperationIds($document);

    expect($ids)->toHaveCount(2)
        ->and(array_unique($ids))->toHaveCount(2)
        ->and(array_values(array_unique($ids)))->toEqualCanonicalizing(['reportIndex', 'reportIndex_2']);
});

it('templates a Laravel optional path variable into a legal, required OpenAPI parameter', function () {
    $document = edgeDocument();

    /** @var array<string, array<string, array<string, mixed>>> $paths */
    $paths = $document['paths'];

    // The RouteScanner really does emit `/alpha/reports/{slug?}` (that is Laravel's optional spelling); the
    // generator must publish it without the marker, because OpenAPI has no optional path parameter.
    expect($paths)->toHaveKey('/alpha/reports/{slug}')
        ->and($paths)->not->toHaveKey('/alpha/reports/{slug?}');

    foreach (array_keys($paths) as $path) {
        expect($path)->not->toContain('?');
    }

    /** @var list<array<string, mixed>> $parameters */
    $parameters = $paths['/alpha/reports/{slug}']['get']['parameters'];
    $slug = array_values(array_filter($parameters, static fn (array $p): bool => $p['name'] === 'slug'));

    expect($slug)->toHaveCount(1)
        // A path parameter is required in OpenAPI, full stop — even when the PHP signature defaults it to
        // null. Publishing `required: false` here produces a document a strict validator rejects.
        ->and($slug[0]['in'])->toBe('path')
        ->and($slug[0]['required'])->toBeTrue();
});
