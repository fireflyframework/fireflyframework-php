<?php

declare(strict_types=1);

use Firefly\OpenApi\Tests\Support\FixtureDocument;
use Firefly\OpenApi\Tests\Support\SecuredOpenApiCapstoneTestCase;

uses(SecuredOpenApiCapstoneTestCase::class);

/**
 * @return array<string, mixed>
 */
function securedDocument(SecuredOpenApiCapstoneTestCase $test): array
{
    /** @var array<string, mixed> $document */
    $document = json_decode($test->responseBody($test->get('/openapi.json')->assertStatus(200)), true, flags: JSON_THROW_ON_ERROR);

    return $document;
}

it('leaves every principal-injected parameter out of the served document', function () {
    /** @var SecuredOpenApiCapstoneTestCase $this */
    $document = securedDocument($this);

    // `#[AuthenticationPrincipal] ?string $sub` and `mixed $principal` plan as query bindings by type, and
    // were published as REQUIRED `?sub=` / `?principal=` with a documented 400 — the opposite of what the
    // dispatcher does with them. `?UserDetails $user` is a service binding and was never published; all
    // three now go the same way because the same registry claims them.
    foreach (['/open/sub', '/open/whoami', '/open/user', '/open/principal-user', '/profile'] as $path) {
        $operation = FixtureDocument::operation($document, $path, 'get');

        expect($operation)->not->toBeEmpty("{$path} is in the document")
            ->and(array_key_exists('parameters', $operation))->toBeFalse("{$path} publishes a parameter")
            ->and(array_key_exists('400', (array) $operation['responses']))->toBeFalse("{$path} documents a 400");
    }
});

it('still publishes the request parameters of the routes beside them', function () {
    /** @var SecuredOpenApiCapstoneTestCase $this */
    $show = FixtureDocument::operation(securedDocument($this), '/api/orders/{id}', 'get');

    // The path variable, the query flag and the header the orders fixture declares — none claimed, none lost.
    $published = [];
    /** @var list<array<string, mixed>> $parameters */
    $parameters = $show['parameters'];
    foreach ($parameters as $parameter) {
        /** @var string $name */
        $name = $parameter['name'];
        $published[$name] = $parameter['in'];
    }

    expect($published)->toBe(['id' => 'path', 'expand' => 'query', 'X-Tenant' => 'header'])
        ->and($show['responses'])->toHaveKey('400');
});
