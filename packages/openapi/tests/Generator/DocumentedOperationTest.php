<?php

declare(strict_types=1);

use Firefly\OpenApi\Tests\Support\FixtureDocument;

/**
 * The "documented only by PHPDoc" half of springdoc parity, asserted on the MERGED DOCUMENT rather than on
 * the parser.
 *
 * DocFixture carries not one attribute from Firefly\OpenApi\Attributes. Everything asserted here therefore
 * reached the document because the generator read comments a developer had already written for a human — the
 * exact claim being made. Where the old generator put `ucfirst()` of the method name into `summary` and the
 * literal string "Handled by App\Web\CatalogController::list()." into `description`, both of which are
 * asserted against by name below so a regression cannot pass quietly.
 *
 * @return array<string, mixed>
 */
function documentedDocument(): array
{
    return FixtureDocument::generatorFor('DocFixture')->generate();
}

it('takes the operation summary from the docblock and the rest of it as the description', function () {
    $operation = FixtureDocument::operation(documentedDocument(), '/catalog/{category}', 'get');

    expect($operation['summary'])->toBe('List the products in one category.')
        ->and($operation['description'])->toBe(
            "Withdrawn lines are never included, even when their category still exists.\n\n"
            .'The `page` cursor is opaque: echo back exactly what the previous response returned. Cursors '
            .'built by hand are not supported and may stop resolving at any time.'
        );
});

it('never emits the placeholder the description used to be', function () {
    $json = FixtureDocument::generatorFor('DocFixture')->toJson();

    expect($json)->not->toContain('Handled by ');
});

it('falls back to the humanised method name only when the docblock holds no prose', function () {
    // `undocumented()` carries `/** @return array<string, mixed> */` and nothing else — a docblock that is
    // present but says nothing about the operation must fall through exactly as an absent one does.
    $operation = FixtureDocument::operation(documentedDocument(), '/catalog/health', 'get');

    expect($operation['summary'])->toBe('Undocumented')
        ->and($operation)->not->toHaveKey('description');
});

it('marks an operation deprecated from the docblock @deprecated tag', function () {
    $document = documentedDocument();

    expect(FixtureDocument::operation($document, '/catalog/barcode/{code}', 'get')['deprecated'])->toBeTrue()
        // Emitted only where it is true: `deprecated` defaults to false in the specification, so a live
        // operation must not carry the key at all.
        ->and(FixtureDocument::operation($document, '/catalog/reservations', 'post'))->not->toHaveKey('deprecated');
});

it('describes the tag from the controller class docblock', function () {
    /** @var list<array{name: string, description: string}> $tags */
    $tags = documentedDocument()['tags'];

    expect($tags)->toHaveCount(1)
        ->and($tags[0]['name'])->toBe('Catalog')
        ->and($tags[0]['description'])->toStartWith('The public product catalogue.')
        // The whole class docblock, not just its first sentence: a tag description has one slot and a viewer
        // renders it as a block.
        ->and($tags[0]['description'])->toContain('readable without authentication');
});

it('describes a DTO schema from the DTO class docblock', function () {
    /** @var array<string, array<string, array<string, mixed>>> $components */
    $components = documentedDocument()['components'];
    /** @var array<string, mixed> $schema */
    $schema = $components['schemas']['ReservationRequest'];

    expect($schema['description'])->toStartWith('A request to hold stock for a shopper who has not paid yet.')
        ->and($schema['description'])->toContain('lapsed reservation');
});

it('describes each DTO member from its own docblock, falling back to the constructor @param', function () {
    /** @var array<string, array<string, array<string, mixed>>> $components */
    $components = documentedDocument()['components'];
    /** @var array<string, array<string, mixed>> $properties */
    $properties = $components['schemas']['ReservationRequest']['properties'];

    expect($properties['basket']['description'])->toBe("the shopper's basket, as returned by POST /baskets")
        ->and($properties['sku']['description'])->toBe('The catalogue line to hold. Exactly one line may be reserved per request.')
        // `minutes` has BOTH a promoted-property docblock and a `@param` line, and the closer one wins.
        ->and($properties['minutes']['description'])->toBe('How long to hold the stock for, in minutes from now.');
});

it('leaves the derived schema keywords untouched while adding prose', function () {
    /** @var array<string, array<string, array<string, mixed>>> $components */
    $components = documentedDocument()['components'];
    /** @var array<string, array<string, mixed>> $properties */
    $properties = $components['schemas']['ReservationRequest']['properties'];

    // A description is an annotation and must not disturb the type/constraint half of the schema, which is
    // still derived from the declared type and the compiled ConstraintManifest.
    expect($properties['minutes'])->toBe([
        'description' => 'How long to hold the stock for, in minutes from now.',
        'type' => 'integer',
        'minimum' => 1,
        'maximum' => 60,
        'default' => 15,
    ]);
});

it('still produces a document whose every local $ref resolves', function () {
    $document = documentedDocument();
    $refs = FixtureDocument::refs($document);

    expect($refs)->not->toBeEmpty();

    foreach (array_unique($refs) as $ref) {
        expect(FixtureDocument::resolve($document, $ref))->not->toBeNull("dangling \$ref {$ref}");
    }
});
