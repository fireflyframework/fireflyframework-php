<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Generator;

use Firefly\Web\View\ModelAndView;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Contracts\Support\Responsable;
use JsonSerializable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;

/**
 * The success response of an action that hands ResponseFactory something OTHER than data.
 *
 * ResponseFactory has three branches ahead of its JSON converter, and this class answers for each of them in
 * the same order, so the document and the dispatcher cannot disagree about which one a return takes:
 *
 *   1. A Symfony Response — any Illuminate one included — is SENT AS IT IS. Its body, and for a redirect its
 *      status, were decided by the action, so the declared class is all there is to go on: a JsonResponse is
 *      JSON of a shape the action built, a file is a binary download, a redirect is a 302 with a Location,
 *      and anything else is a body of whatever media type it set.
 *   2. A Responsable builds its own response, of a media type only it knows.
 *   3. A ModelAndView, or a View / Renderable / Htmlable that is not ALSO data, is rendered as text/html. The
 *      "not also data" is ResponseFactory's own rule — a Laravel paginator is Htmlable and Arrayable, and is
 *      data.
 *
 * All three used to fall through to the JSON path, which documented the class's PUBLIC PROPERTIES: a
 * JsonResponse became an object with `original`, `exception` and a `headers` member referencing a
 * `ResponseHeaderBag` component, all required — a type the server never sends. `*` / `*` with the any-value
 * schema is the honest spelling of "a body, of a type decided at runtime"; an author who knows more states it
 * with #[ApiResponse].
 */
final readonly class RenderedResponse
{
    /**
     * @param  int|null  $status  the status the returned object carries itself, overriding the #[Mapping]'s,
     *                            or null when the #[Mapping]'s is the one sent
     * @param  array<string, mixed>  $response  the Response Object
     */
    private function __construct(
        public ?int $status,
        public array $response,
    ) {}

    /**
     * The response for an action declared to return $class, or null when $class is data — a value the JSON
     * converter writes, which the schema factories document.
     *
     * @param  string  $prose  the author's description of the response, from the `@return` line
     */
    public static function for(string $class, string $prose): ?self
    {
        $described = static fn (string $fallback): string => $prose === '' ? $fallback : $prose;
        $body = static fn (string $mediaType, array $schema): array => [
            'description' => $described('Successful response.'),
            'content' => [$mediaType => ['schema' => $schema]],
        ];

        return match (true) {
            is_a($class, RedirectResponse::class, true) => new self(302, [
                'description' => $described('A redirect to the URL in the Location header.'),
                'headers' => ['Location' => ['description' => 'Where to go instead.', 'schema' => ['type' => 'string', 'format' => 'uri-reference']]],
            ]),
            is_a($class, BinaryFileResponse::class, true) => new self(null, $body('application/octet-stream', ['type' => 'string', 'format' => 'binary'])),
            is_a($class, JsonResponse::class, true), is_a($class, StreamedJsonResponse::class, true) => new self(null, $body('application/json', [])),
            is_a($class, Response::class, true), is_a($class, Responsable::class, true) => new self(null, $body('*/*', [])),
            is_a($class, ModelAndView::class, true), self::markup($class) => new self(null, $body('text/html', ['type' => 'string'])),
            default => null,
        };
    }

    /** ResponseFactory's HTML arms: something that renders, and is not ALSO something the converter writes. */
    private static function markup(string $class): bool
    {
        if (is_a($class, Arrayable::class, true) || is_a($class, JsonSerializable::class, true)) {
            return false;
        }

        return is_a($class, Renderable::class, true) || is_a($class, Htmlable::class, true);
    }
}
