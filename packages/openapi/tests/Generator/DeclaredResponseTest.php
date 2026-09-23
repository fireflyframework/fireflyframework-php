<?php

declare(strict_types=1);

use Firefly\OpenApi\Tests\Support\FixtureDocument;

/*
 | #[ApiResponse] with no `type` used to mean "bodiless" for every status, and that was wrong in both
 | directions an author actually uses the attribute for.
 |
 | On the SUCCESS status it erased the body. The attribute's own docblock promised the opposite — re-declaring
 | a derived status is how an author "put[s] real prose on the 200 … without losing the derived response's
 | content type" — but the declared entry replaced the derived one wholesale, so adding a sentence to a 200
 | deleted the schema of everything the action returns.
 |
 | On an ERROR status it denied a body the server always sends. Every FireflyException renders through
 | ProblemDetailsRenderer as application/problem+json, so a documented `404` with no content told a client
 | generator to expect an empty 404 — and to discard the `code` it exists to branch on.
 */
$document = static fn (): array => FixtureDocument::generatorFor('ReturnFixture')->generate();

it('keeps the derived body when an #[ApiResponse] only re-words the success status', function () use ($document) {
    expect(FixtureDocument::resolve($document(), '#/paths/~1declared~1{barcode}/get/responses/200'))->toBe([
        'description' => 'The parcel as last scanned.',
        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Parcel']]],
    ]);
});

it('documents an error status declared without a type as the problem body the server renders', function () use ($document) {
    $problem = ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/ProblemDetails']]];

    expect(FixtureDocument::resolve($document(), '#/paths/~1declared~1{barcode}/get/responses/404'))
        ->toBe(['description' => 'No parcel carries that barcode.', 'content' => $problem])
        ->and(FixtureDocument::resolve($document(), '#/paths/~1declared~1{barcode}/get/responses/default'))
        ->toBe(['description' => 'Anything else the depot refuses.', 'content' => $problem])
        // OpenAPI's status RANGE spelling is an error status too.
        ->and(FixtureDocument::resolve($document(), '#/paths/~1declared~1{barcode}/get/responses/5XX'))
        ->toBe(['description' => 'The depot is unreachable.', 'content' => $problem]);
});

it('still documents a non-error status the generator did not derive, declared without a type, as bodiless', function () use ($document) {
    // Nothing to borrow a body from and nothing the framework renders for it: a 202 the author mentions only
    // in prose is exactly the case "no type means no body" was written for.
    expect(FixtureDocument::resolve($document(), '#/paths/~1declared~1{barcode}/get/responses/202'))
        ->toBe(['description' => 'Queued for a rescan; ask again later.']);
});
