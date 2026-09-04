<?php

declare(strict_types=1);

use Firefly\OpenApi\Tests\Support\FixtureDocument;

/**
 * The `content` map of one response on one operation, or [] when any step of the path is absent — so a
 * missing key fails the assertion that asked for it rather than a type error three lines earlier.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function responseContent(array $document, string $path, string $verb, string $status): array
{
    /** @var mixed $node */
    $node = $document['paths'] ?? [];

    foreach ([$path, $verb, 'responses', $status, 'content'] as $segment) {
        if (! is_array($node) || ! array_key_exists($segment, $node)) {
            return [];
        }
        /** @var mixed $node */
        $node = $node[$segment];
    }

    /** @var array<string, mixed> $content */
    $content = is_array($node) ? $node : [];

    return $content;
}

/**
 * A #[Controller] renders a web page. It is part of the application's HTTP surface, but it is not a JSON API
 * operation — and because #[Controller] extends #[RestController] it lands in the same RouteManifest as
 * every JSON route. Left alone, the generator documented the welcome page as `application/json`, which a
 * client generator would faithfully turn into a typed call expecting a deserialisable body.
 */
it('leaves HTML routes out of the document by default', function () {
    /** @var array<string, mixed> $paths */
    $paths = FixtureDocument::generator()->generate()['paths'];

    expect(array_keys($paths))->not->toContain('/welcome');
});

it('documents an HTML route as text/html when asked to include it', function () {
    $properties = FixtureDocument::properties(includeHtml: true);

    /** @var array<string, array<string, array<string, mixed>>> $paths */
    $paths = FixtureDocument::generator($properties)->generate()['paths'];

    expect($paths)->toHaveKey('/welcome');

    $content = responseContent(FixtureDocument::generator($properties)->generate(), '/welcome', 'get', '200');

    expect($content)->toHaveKey('text/html')
        ->and($content)->not->toHaveKey('application/json');
});

// The flag must not disturb the JSON operations that were always there.
it('still documents JSON routes as application/json either way', function () {
    foreach ([FixtureDocument::properties(), FixtureDocument::properties(includeHtml: true)] as $properties) {
        $content = responseContent(FixtureDocument::generator($properties)->generate(), '/api/orders', 'post', '201');

        expect($content)->toHaveKey('application/json');
    }
});
