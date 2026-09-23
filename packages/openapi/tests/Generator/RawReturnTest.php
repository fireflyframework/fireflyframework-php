<?php

declare(strict_types=1);

use Firefly\OpenApi\Tests\Support\FixtureDocument;

/*
 | ResponseFactory hands three kinds of return straight past the JSON converter: a Symfony/Illuminate Response
 | the action built itself (sent as it is), a Responsable (asked to build its own), and markup — a View, a
 | Renderable or an Htmlable that is not also data (rendered as text/html). The document described all three as
 | JSON objects built from their PUBLIC PROPERTIES: a JsonResponse became a component with `original`,
 | `exception` and a `headers` member pointing at a `ResponseHeaderBag` component, every one marked required.
 | A client generator turned that into a type the server never sends.
 |
 | What each one sends is now documented as far as the declared class can say it, and no further.
 */
$document = static fn (): array => FixtureDocument::generatorFor('ReturnFixture')->generate();

/**
 * @param  array<string, mixed>  $document
 * @return array<array-key, mixed>
 */
function rawResponses(array $document, string $path): array
{
    $responses = FixtureDocument::resolve($document, '#/paths/~1raw'.str_replace('/', '~1', $path).'/get/responses');

    return is_array($responses) ? $responses : [];
}

it('documents a JsonResponse as JSON whose shape the action built itself', function () use ($document) {
    expect(rawResponses($document(), '/json')[200])->toBe([
        'description' => 'Successful response.',
        'content' => ['application/json' => ['schema' => []]],
    ]);
});

it('documents a file response as a binary download, keeping the author\'s description', function () use ($document) {
    expect(rawResponses($document(), '/file')[200])->toBe([
        'description' => 'the stored manifest, as uploaded',
        'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
    ]);
});

it('documents a response of unknown media type as any media type', function () use ($document) {
    $doc = $document();
    $opaque = ['description' => 'Successful response.', 'content' => ['*/*' => ['schema' => []]]];

    // A stream, a plain Response and a Responsable all decide their own Content-Type at runtime.
    expect(rawResponses($doc, '/stream')[200])->toBe($opaque)
        ->and(rawResponses($doc, '/plain')[200])->toBe($opaque)
        ->and(rawResponses($doc, '/receipt')[200])->toBe($opaque);
});

it('documents a redirect at the status the redirect carries, with its Location', function () use ($document) {
    $responses = rawResponses($document(), '/redirect');

    // The #[Mapping]'s 200 never reaches the wire: a returned response is sent as it is, and a
    // RedirectResponse's own status is 302.
    expect($responses)->not->toHaveKey(200)
        ->and($responses[302])->toBe([
            'description' => 'A redirect to the URL in the Location header.',
            'headers' => ['Location' => ['description' => 'Where to go instead.', 'schema' => ['type' => 'string', 'format' => 'uri-reference']]],
        ]);
});

it('documents markup and a ModelAndView as text/html', function () use ($document) {
    $doc = $document();
    $html = ['description' => 'Successful response.', 'content' => ['text/html' => ['schema' => ['type' => 'string']]]];

    expect(rawResponses($doc, '/banner')[200])->toBe($html)
        ->and(rawResponses($doc, '/page')[200])->toBe($html);
});

it('documents a union of two responses as a body decided at runtime, not as an anyOf of their internals', function () use ($document) {
    // `JsonResponse|RedirectResponse` reflects as a union, so the single-named-type test the factory used to
    // make answered no and the declared-type reader documented `anyOf: [$ref JsonResponse, $ref
    // RedirectResponse]` — two components built from `original`, `exception` and a ResponseHeaderBag. No arm
    // is THE answer either: a redirect carries 302 and no body where the JsonResponse carries 200 and JSON,
    // which is exactly what `*/*` with the any-value schema says.
    expect(rawResponses($document(), '/either')[200])->toBe([
        'description' => 'Successful response.',
        'content' => ['*/*' => ['schema' => []]],
    ]);
});

it('documents a response an action names only in its `@return`, with no declared type to read', function () use ($document) {
    $responses = rawResponses($document(), '/documented-redirect');

    // The comment is the only thing that names what is sent, and it names a redirect — status and all,
    // exactly as a declared `: RedirectResponse` is documented.
    expect($responses)->not->toHaveKey(200)
        ->and($responses[302])->toBe([
            'description' => 'the page that replaced this one',
            'headers' => ['Location' => ['description' => 'Where to go instead.', 'schema' => ['type' => 'string', 'format' => 'uri-reference']]],
        ]);
});

it('builds no component for a response class an #[ApiResponse] names', function () use ($document) {
    // The rule is at the schema factory's door rather than at the one caller that used to pre-filter for it,
    // so a class reaching it through the attribute is refused exactly as a declared return type is.
    expect(rawResponses($document(), '/attributed')[200])->toBe([
        'description' => 'A body the action built itself.',
        'content' => ['application/json' => ['schema' => []]],
    ]);
});

it('mints no component out of a response object\'s internals', function () use ($document) {
    /** @var array{schemas: array<string, mixed>} $components */
    $components = $document()['components'];

    foreach (['JsonResponse', 'BinaryFileResponse', 'StreamedResponse', 'RedirectResponse', 'Response', 'ResponseHeaderBag', 'Receipt', 'Banner', 'ModelAndView'] as $name) {
        expect($components['schemas'])->not->toHaveKey($name);
    }
});
