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
 * The whole point of the package: an OpenAPI 3.1 document assembled from manifests the framework ALREADY
 * holds in memory, with no annotation dialect of its own to learn and nothing to keep in sync by hand.
 *
 * RouteManifest supplies the paths, verbs, statuses, route names and the per-parameter binding plan;
 * ConstraintManifest (reached through DtoSchemaFactory) supplies the request-body schemas and their `required`
 * lists; packages/kernel's ErrorResponse supplies the error component. A LaraFly app therefore gets a typed
 * client for free the moment it installs this package, and the document cannot drift from the server, because
 * every fact in it is read from the same compiled artifact the dispatcher reads.
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

    public function __construct(
        private readonly RouteManifest $routes,
        private readonly OpenApiProperties $properties,
        private readonly OperationFactory $operations,
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

        $paths = [];
        $operationIds = [];

        foreach ($this->routes->all() as $route) {
            if ($this->excluded($route)) {
                continue;
            }

            $path = $this->template($route->path);
            $verb = strtolower($route->httpMethod);

            $paths[$path][$verb] = $this->operations->create($route, $this->operationId($route, $operationIds), $registry);
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

        return $document;
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

        return $info;
    }

    /**
     * A route is left out of the document when its path is excluded by configuration, or when it was
     * declared by the HTML stereotype.
     *
     * #[Controller] routes render web pages. They are part of the application's HTTP surface, but they are
     * not JSON API operations, and describing one as `application/json` would have a generator emit a typed
     * client for a response that is a page — the welcome page was in the spec exactly that way. Set
     * `firefly.openapi.include-html` to document them anyway; the operation is then produced with
     * `text/html` content rather than a JSON schema.
     */
    private function excluded(RouteDescriptor $route): bool
    {
        if ($route->html && ! $this->properties->includeHtml) {
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
    private function operationId(RouteDescriptor $route, array &$used): string
    {
        $candidate = $route->name ?? $this->derivedId($route);

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
