<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Generator;

use Firefly\OpenApi\OpenApiProperties;
use Firefly\OpenApi\Schema\ProblemSchema;
use Firefly\OpenApi\Schema\SchemaRegistry;
use Firefly\Web\Route\RouteDescriptor;
use Firefly\Web\Route\RouteManifest;
use stdClass;

/**
 * The whole point of the package: an OpenAPI 3.1 document assembled from artifacts the application ALREADY
 * has, with nothing to keep in sync by hand.
 *
 * RouteManifest supplies the paths, verbs, statuses, route names and the per-parameter binding plan;
 * ConstraintManifest (reached through DtoSchemaFactory) supplies the request-body schemas and their `required`
 * lists; packages/kernel's ErrorResponse supplies the error component; and the controllers' own PHPDoc
 * supplies the prose (see ApiDocs). A LaraFly app therefore gets a typed client for free the moment it
 * installs this package, and the document cannot drift from the server, because every fact in it is read from
 * the same compiled artifact the dispatcher reads.
 *
 * THERE IS AN ANNOTATION DIALECT, and it is deliberately optional. Firefly\OpenApi\Attributes exists for the
 * things no manifest and no docblock can state — a hand-picked operationId, a 404 that only the controller's
 * body knows about, an example value — and for nothing else. Every one of its members falls through to the
 * docblock and then to a derivation when omitted, so an application that adopts none of it still gets a
 * document written in its own words rather than in placeholders.
 *
 * ORDERING IS DETERMINISTIC AND THAT IS DELIBERATE. Paths are sorted, verbs within a path are sorted into the
 * canonical OpenAPI order, and SchemaRegistry sorts components by name. Route discovery order depends on
 * filesystem iteration, so an unsorted document would reshuffle itself between machines and turn every
 * regeneration into an unreviewable diff — which is exactly what makes teams stop committing the generated
 * file, which is what makes it go stale.
 *
 * MEMOISED PER INSTANCE. The generator is a container singleton, the manifests behind it are immutable for
 * the life of the process, and generation reflects every body DTO once. Rebuilding on every hit to the spec
 * route would repeat that work for an answer that cannot have changed — including under Octane, where the
 * worker outlives thousands of requests. `firefly:openapi` constructs its own short-lived process, so the
 * cache never outlives a source edit there either.
 */
final class OpenApiGenerator
{
    /**
     * The order the OpenAPI specification itself lists the Path Item Object's operation fields in. Anything
     * a RouteDescriptor carries that is not in this list (an exotic verb) is appended afterwards in
     * alphabetical order rather than dropped.
     */
    private const array VERB_ORDER = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /** @var array<string, mixed>|null */
    private ?array $document = null;

    /**
     * $info carries the OPTIONAL Info Object members (summary, termsOfService, contact, license) that
     * OpenApiProperties does not. It is last and nullable so every existing three-argument construction —
     * OpenApiAutoConfiguration's #[Bean], an application's own override bean, the fixtures — keeps compiling
     * and keeps producing exactly the document it produced before.
     */
    public function __construct(
        private readonly RouteManifest $routes,
        private readonly OpenApiProperties $properties,
        private readonly OperationFactory $operations,
        private readonly ?DocumentInfo $info = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        return $this->document ??= $this->build();
    }

    /**
     * The canonical serialisation, and the one that must be used to produce a FILE or an HTTP body.
     *
     * `generate()` returns plain PHP arrays because that is what is pleasant to assert against, but PHP
     * cannot tell an empty map from an empty list: `json_encode([])` is `[]`, so an app with no routes would
     * serialise `"paths": []` and an unconstrained property would serialise as `[]` — both of which are type
     * errors against the OpenAPI 3.1 meta-schema, and both of which make a strict validator reject an
     * otherwise perfect document. Every empty array is therefore re-encoded as `{}` here. That rewrite is
     * unconditionally safe in THIS document because nothing in it ever emits an empty LIST: `required`,
     * `tags`, `parameters`, `servers`, `allOf` and the constraint extension are each omitted entirely rather
     * than emitted empty (see DtoSchemaFactory and OperationFactory, which say so at each site).
     */
    public function toJson(): string
    {
        return json_encode(
            $this->objectify($this->generate()),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        $registry = new SchemaRegistry;
        $registry->put(ProblemSchema::NAME, ProblemSchema::schema());

        // Per-DOCUMENT, like the registry beside it: ApiDocs caches one reflection + docblock parse per class
        // and per method, which is worth a great deal across forty routes on eight controllers and worth
        // nothing once the document exists. Building it here also means the reflection it performs cannot
        // outlive generation — see that class for why reflecting at all is legitimate in this package.
        $docs = new ApiDocs;

        $paths = [];
        $operationIds = [];
        $used = [];
        $described = [];

        foreach ($this->routes->all() as $route) {
            if ($this->excluded($route, $docs)) {
                continue;
            }

            $path = $this->template($route->path);
            $verb = strtolower($route->httpMethod);

            $paths[$path][$verb] = $this->operations->create($route, $this->operationId($route, $operationIds, $docs), $registry, $docs);

            foreach ($docs->tagNames($route) as $tag) {
                $used[$tag] = true;
            }

            // Collected from SURVIVING routes only, which is what keeps an excluded controller from
            // contributing prose about a group nothing in the document belongs to — and, where two
            // controllers share a tag name, keeps the description that wins from depending on whether the
            // loser happened to be hidden.
            $tag = $docs->tag($route->controllerClass);
            if ($tag->description !== '') {
                $described[$tag->name] ??= $tag->description;
            }
        }

        ksort($paths);
        foreach ($paths as $path => $item) {
            $paths[$path] = $this->sortVerbs($item);
        }

        $document = [
            'openapi' => '3.1.0',
            'info' => $this->info(),
        ];

        if ($this->properties->servers !== []) {
            $document['servers'] = $this->properties->servers;
        }

        $document['paths'] = $paths;
        $document['components'] = [
            'schemas' => $registry->all(),
            'responses' => [ProblemSchema::RESPONSE_NAME => ProblemSchema::response()],
        ];

        $tags = $this->tags($described, $used);
        if ($tags !== []) {
            $document['tags'] = $tags;
        }

        return $document;
    }

    /**
     * The document's root `tags` array — the ONLY place OpenAPI lets a tag carry a description, because an
     * Operation Object's own `tags` member is a bare list of strings.
     *
     * Only tags that actually have a description are listed. A root entry is `{name, description}` and one
     * with nothing but a name restates what every operation already says, so emitting those would add a line
     * per controller to every generated file to convey nothing. A described tag that no surviving operation
     * references is skipped for a sharper reason: an #[ApiIgnore]d controller must not leave its tag prose
     * behind as the one trace that it exists.
     *
     * Sorted by name, for the same reason paths and components are — an unsorted array reshuffles itself
     * with filesystem scan order and turns every regeneration into an unreviewable diff.
     *
     * @param  array<string, string>  $described  tag name => its description, from the documented routes
     * @param  array<string, bool>  $used  tag names at least one documented operation is filed under
     * @return list<array{name: string, description: string}>
     */
    private function tags(array $described, array $used): array
    {
        $tags = array_intersect_key($described, $used);
        ksort($tags);

        return array_map(
            static fn (string $name): array => ['name' => $name, 'description' => $tags[$name]],
            array_keys($tags),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function info(): array
    {
        $info = ['title' => $this->properties->title, 'version' => $this->properties->version];

        if ($this->properties->description !== '') {
            $info['description'] = $this->properties->description;
        }

        return $this->info?->applyTo($info) ?? $info;
    }

    /**
     * A route is left out of the document when it carries #[ApiIgnore] (on its class or on itself), when its
     * path is excluded by configuration, or when it was declared by the HTML stereotype.
     *
     * #[Controller] routes render web pages. They are part of the application's HTTP surface, but they are
     * not JSON API operations, and describing one as `application/json` would have a generator emit a typed
     * client for a response that is a page — the welcome page was in the spec exactly that way. Set
     * `firefly.openapi.include-html` to document them anyway; the operation is then produced with
     * `text/html` content rather than a JSON schema.
     */
    private function excluded(RouteDescriptor $route, ApiDocs $docs): bool
    {
        if ($route->html && ! $this->properties->includeHtml) {
            return true;
        }

        if ($docs->ignores($route)) {
            return true;
        }

        foreach ($this->properties->excludePathPrefixes as $prefix) {
            if (str_starts_with($route->path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Laravel's optional-parameter spelling `{id?}` has no OpenAPI equivalent — a path parameter is required
     * there, full stop — so the marker is stripped and the parameter stays required. The alternative (two
     * Path Items, one with and one without the segment) would describe an API surface the router does not
     * actually expose as two routes, and would double every such operation in a generated client.
     */
    private function template(string $path): string
    {
        return str_replace('?}', '}', $path);
    }

    /**
     * @param  array<string, int>  $used  operationId => how many times it has been claimed
     */
    private function operationId(RouteDescriptor $route, array &$used, ApiDocs $docs): string
    {
        // Attribute beats route name beats derivation — the same precedence ApiDocs applies to prose, applied
        // here rather than in OperationFactory because the UNIQUENESS ledger lives here. An #[ApiOperation]
        // may choose the id; it does not get to hand two operations the same one.
        $candidate = $docs->operation($route)->operationId ?? $route->name ?? $this->derivedId($route);

        // operationId is REQUIRED to be unique across the whole document, and a duplicate is the one flaw
        // that makes most client generators abort rather than degrade. Two routes can legitimately collide
        // (the same method name on two controllers whose short names differ only by namespace, or a route
        // `name` reused by mistake), so a repeat claim is suffixed rather than allowed to overwrite.
        $used[$candidate] = ($used[$candidate] ?? 0) + 1;

        return $used[$candidate] === 1 ? $candidate : $candidate.'_'.$used[$candidate];
    }

    private function derivedId(RouteDescriptor $route): string
    {
        $class = $route->controllerClass;
        $short = str_contains($class, '\\') ? substr($class, (int) strrpos($class, '\\') + 1) : $class;
        $short = str_ends_with($short, 'Controller') && $short !== 'Controller'
            ? substr($short, 0, -strlen('Controller'))
            : $short;

        return lcfirst($short).ucfirst($route->methodName);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function sortVerbs(array $item): array
    {
        $sorted = [];

        foreach (self::VERB_ORDER as $verb) {
            if (array_key_exists($verb, $item)) {
                $sorted[$verb] = $item[$verb];
                unset($item[$verb]);
            }
        }

        ksort($item);

        return [...$sorted, ...$item];
    }

    /**
     * Recursively re-encodes empty arrays as empty JSON OBJECTS — see toJson() for why this is both
     * necessary and safe here.
     */
    private function objectify(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($value === []) {
            return new stdClass;
        }

        return array_map(fn (mixed $item): mixed => $this->objectify($item), $value);
    }
}
