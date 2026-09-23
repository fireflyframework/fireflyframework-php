<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Generator;

use Firefly\Web\View\ModelAndView;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Resources\Json\JsonResource;
use JsonSerializable;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
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
 *   2. A Responsable builds its own response, of a media type only it knows — except a Laravel API resource,
 *      whose response is JSON of a shape ResourceSchema can read, and which therefore takes the JSON path.
 *   3. A ModelAndView, or a View / Renderable / Htmlable that is not ALSO data, is rendered as text/html. The
 *      "not also data" is ResponseFactory's own rule — a Laravel paginator is Htmlable and Arrayable, and is
 *      data.
 *
 * All three used to fall through to the JSON path, which documented the class's PUBLIC PROPERTIES: a
 * JsonResponse became an object with `original`, `exception` and a `headers` member referencing a
 * `ResponseHeaderBag` component, all required — a type the server never sends. `*` / `*` with the any-value
 * schema is the honest spelling of "a body, of a type decided at runtime"; an author who knows more states it
 * with #[ApiResponse].
 *
 * THE CLASS TEST IS PUBLISHED, through isRendered(), because one caller deciding it was never enough. The
 * factory used to ask for() about a SINGLE NAMED declared return type only, so every other way a response
 * class reaches a schema — an arm of a `JsonResponse|RedirectResponse` union, a `@return` line on an action
 * with no declared type, an `#[ApiResponse(type: 'JsonResponse')]` — walked straight past this class into
 * the property reflector and minted exactly the component the paragraph above rules out. ResponseSchemaFactory
 * now asks the same question at ITS entry point, which every one of those paths goes through, so the rule
 * holds wherever a class is turned into a schema rather than only where someone remembered to pre-filter.
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
        return match (true) {
            is_a($class, RedirectResponse::class, true) => new self(302, [
                'description' => $prose === '' ? 'A redirect to the URL in the Location header.' : $prose,
                'headers' => ['Location' => ['description' => 'Where to go instead.', 'schema' => ['type' => 'string', 'format' => 'uri-reference']]],
            ]),
            is_a($class, BinaryFileResponse::class, true) => new self(null, self::body($prose, 'application/octet-stream', ['type' => 'string', 'format' => 'binary'])),
            is_a($class, JsonResponse::class, true), is_a($class, StreamedJsonResponse::class, true) => new self(null, self::body($prose, 'application/json', [])),
            is_a($class, Response::class, true) => new self(null, self::body($prose, '*/*', [])),
            // A Laravel API resource is the one Responsable whose response is knowable: ResourceSchema reads it.
            is_a($class, Responsable::class, true) && ! is_a($class, JsonResource::class, true) => new self(null, self::body($prose, '*/*', [])),
            is_a($class, ModelAndView::class, true), self::markup($class) => new self(null, self::body($prose, 'text/html', ['type' => 'string'])),
            default => null,
        };
    }

    /**
     * The response for an action's DECLARED return type, read as PHP declared it rather than as a single
     * class name — which is the difference between covering `: JsonResponse` and covering every way a
     * response type can be written.
     *
     * A UNION IS THE CASE THAT USED TO ESCAPE. `: JsonResponse|RedirectResponse` reflects as a
     * ReflectionUnionType, the caller's "is this one named class" test answered no, and the declared-type
     * schema reader documented `anyOf` of the two classes' INTERNALS. What the server actually sends there
     * is one of several responses the action picks at runtime, and no arm's media type or status is THE one
     * sent — a redirect carries 302 and no body, the JsonResponse carries 200 and JSON — so the honest
     * answer is the same `*` / `*` any-value body a plain Response gets. One rendered arm is enough to make
     * that true: a `Parcel|JsonResponse` is still a body whose media type only the request decides.
     *
     * A single named type keeps its exact answer, redirect status and all, because there the class really is
     * the whole of what is sent.
     *
     * @param  string  $prose  the author's description of the response, from the `@return` line
     */
    public static function forType(?ReflectionType $type, string $prose): ?self
    {
        if ($type instanceof ReflectionNamedType) {
            return self::for($type->getName(), $prose);
        }

        if (! $type instanceof ReflectionUnionType) {
            return null;
        }

        foreach ($type->getTypes() as $arm) {
            if ($arm instanceof ReflectionNamedType && self::isRendered($arm->getName())) {
                return new self(null, self::body($prose, '*/*', []));
            }
        }

        return null;
    }

    /**
     * Whether $class is one ResponseFactory hands past its JSON converter — the single question every caller
     * that must NOT document a class by its properties is asking, answered by the same branches for() picks
     * its response from, so the two cannot drift apart.
     */
    public static function isRendered(string $class): bool
    {
        return self::for($class, '') !== null;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function body(string $prose, string $mediaType, array $schema): array
    {
        return [
            'description' => $prose === '' ? 'Successful response.' : $prose,
            'content' => [$mediaType => ['schema' => $schema]],
        ];
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
