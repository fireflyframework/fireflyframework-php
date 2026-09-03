<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Generator;

use Firefly\OpenApi\Attributes\ApiIgnore;
use Firefly\OpenApi\Attributes\ApiOperation;
use Firefly\OpenApi\Attributes\ApiParameter;
use Firefly\OpenApi\Attributes\ApiResponse;
use Firefly\OpenApi\Attributes\ApiTag;
use Firefly\Web\Route\RouteDescriptor;
use ReflectionClass;
use ReflectionMethod;

/**
 * The one place the three documentation sources are merged, and the one place this package reflects a
 * controller.
 *
 * PRECEDENCE, stated once and applied nowhere else: ATTRIBUTE beats DOCBLOCK beats DERIVED DEFAULT.
 *
 *   summary       #[ApiOperation(summary:)]      -> docblock's first sentence   -> humanised method name
 *   description   #[ApiOperation(description:)]  -> the docblock's remaining paragraphs -> nothing
 *   operationId   #[ApiOperation(operationId:)]  -> the #[Mapping]'s route name  -> `orderShow`
 *   deprecated    #[ApiOperation(deprecated:)]   -> the docblock's @deprecated   -> false
 *   tags          #[ApiOperation(tags:)]         -> #[ApiTag(name:)]             -> `Order` from the class
 *   tag prose     #[ApiTag(description:)]        -> the CLASS docblock           -> nothing
 *   presence      #[ApiIgnore]                   -> (no docblock spelling)      -> documented
 *
 * The operationId row is the one whose LAST TWO columns are applied elsewhere: this class returns only what
 * the attribute said, because the fall-through has to happen next to the uniqueness ledger that suffixes a
 * collision, and that ledger lives in OpenApiGenerator. There is deliberately no docblock spelling of
 * #[ApiIgnore] — a `@internal` tag means something to static analysers already and quietly repurposing it to
 * delete routes from a published contract would be a trap.
 *
 * An omitted attribute member falls THROUGH to the next source rather than blanking it, which is why the
 * string members here are compared against '' rather than against null: an attribute an author did not write
 * and an attribute member they left at its default are the same statement, and both must let the docblock
 * win. `deprecated` is the documented exception — it is a ?bool precisely so that `deprecated: false` can
 * mean "not deprecated, whatever the docblock says" rather than "not stated".
 *
 * WHY REFLECTING HERE IS ALLOWED, WHEN packages/web FORBIDS IT. The framework's invariant is that nothing on
 * the CACHED REQUEST PATH reflects: RouteScanner and ConstraintScanner run at `firefly:cache` time and
 * compile their findings into manifests, and ArgumentResolver dispatches off those manifests without ever
 * touching Reflection, because per-request reflection is the single largest avoidable cost in a PHP
 * dispatcher and because reflecting per request means a controller edit changes behaviour without a rebuild.
 * NEITHER concern applies to this class. It runs in exactly two situations: inside the short-lived
 * `firefly:openapi` process, which exists to produce a file; and on the first hit to the spec route, whose
 * result OpenApiGenerator memoises for the life of the process (including under Octane, where the worker
 * outlives thousands of requests). It is never on the path of an application request, and no application
 * request's behaviour depends on it — the worst a mistake here can do is produce a wrong description.
 *
 * The alternative was to teach RouteScanner to compile summaries and descriptions into every RouteDescriptor.
 * That was rejected on the same grounds DtoSchemaFactory rejects it for property types: it would grow the
 * compiled route manifest of EVERY application — parsed and hydrated on every cold boot — to carry prose that
 * only one optional package ever reads.
 *
 * CACHED PER INSTANCE, keyed by class and by class::method. A document with forty routes across eight
 * controllers would otherwise re-reflect and re-parse the same class docblock forty times; the generator
 * builds one of these per document, so the cache lives exactly as long as it is useful.
 */
final class ApiDocs
{
    /** @var array<string, OperationDoc> */
    private array $operations = [];

    /** @var array<string, TagDoc> */
    private array $tags = [];

    /** @var array<string, bool> */
    private array $ignored = [];

    /**
     * Whether this route is documented at all. Checked by OpenApiGenerator alongside the config-driven path
     * exclusions, because both answer the same question and a route that fails either one must never reach
     * the operation factory — a half-built operation for a hidden route would still register its body DTO as
     * a component and leave an orphan schema in the document.
     */
    public function ignores(RouteDescriptor $route): bool
    {
        $key = $route->controllerClass.'::'.$route->methodName;

        return $this->ignored[$key] ??= $this->reflectIgnored($route);
    }

    public function operation(RouteDescriptor $route): OperationDoc
    {
        return $this->operations[$route->controllerClass.'::'.$route->methodName] ??= $this->merge($route);
    }

    /**
     * The tag one controller's operations belong to. Keyed by class rather than by route so that every
     * operation on a controller resolves to the identical TagDoc instance, which is what guarantees the
     * operation-level `tags` member and the root `tags` entry can never disagree about spelling.
     */
    public function tag(string $controllerClass): TagDoc
    {
        return $this->tags[$controllerClass] ??= $this->reflectTag($controllerClass);
    }

    /**
     * The tag names this operation is filed under — the #[ApiOperation(tags:)] override when there is one,
     * and the controller's single derived tag otherwise.
     *
     * @return list<string>
     */
    public function tagNames(RouteDescriptor $route): array
    {
        return $this->operation($route)->tags ?? [$this->tag($route->controllerClass)->name];
    }

    private function merge(RouteDescriptor $route): OperationDoc
    {
        $method = $this->method($route);
        $doc = DocBlock::parse($method?->getDocComment());
        $attribute = $this->attribute($method, ApiOperation::class);

        return new OperationDoc(
            summary: $this->first(
                $attribute->summary ?? '',
                $doc->summary,
                $this->humanise($route->methodName),
            ),
            description: $this->first($attribute->description ?? '', $doc->description),
            operationId: $attribute?->operationId,
            deprecated: $attribute->deprecated ?? $doc->has('deprecated'),
            tags: $this->overriddenTags($attribute),
            responses: $this->repeated($method, ApiResponse::class),
            parameters: $this->parameters($method),
        );
    }

    private function reflectTag(string $controllerClass): TagDoc
    {
        if (! class_exists($controllerClass)) {
            return new TagDoc($this->shortName($controllerClass), '');
        }

        $class = new ReflectionClass($controllerClass);
        $attributes = $class->getAttributes(ApiTag::class);
        $attribute = $attributes === [] ? null : $attributes[0]->newInstance();
        $doc = DocBlock::parse($class->getDocComment());

        return new TagDoc(
            name: $this->first($attribute->name ?? '', $this->shortName($controllerClass)),
            // The class docblock describes the CLASS, and for a controller that is the same subject as the
            // tag — "the wallet endpoints" — which is why it is a usable fallback at all. Summary and
            // remaining paragraphs are joined rather than only the summary taken, because a tag description
            // is rendered as a block in every viewer and has room for the whole thing.
            description: $this->first($attribute->description ?? '', $doc->prose()),
        );
    }

    private function reflectIgnored(RouteDescriptor $route): bool
    {
        if (! class_exists($route->controllerClass)) {
            return false;
        }

        if ((new ReflectionClass($route->controllerClass))->getAttributes(ApiIgnore::class) !== []) {
            return true;
        }

        $method = $this->method($route);

        return $method !== null && $method->getAttributes(ApiIgnore::class) !== [];
    }

    /**
     * The tag list an #[ApiOperation] imposed, or null when it stated none. Null rather than an empty list
     * because the two mean opposite things downstream: null keeps the controller-derived tag, whereas an
     * empty list would file the operation under no tag at all and hide it from every viewer's navigation.
     *
     * @return list<string>|null
     */
    private function overriddenTags(?ApiOperation $attribute): ?array
    {
        $tags = array_values(array_filter(
            $attribute->tags ?? [],
            static fn (string $tag): bool => trim($tag) !== '',
        ));

        return $tags === [] ? null : $tags;
    }

    /**
     * `#[ApiParameter]` instances keyed by the wire name they claim. A repeated name keeps the FIRST
     * declaration, matching every other first-writer-wins rule in this package (MapperState, SchemaRegistry)
     * rather than inventing a second convention for one attribute.
     *
     * @return array<string, ApiParameter>
     */
    private function parameters(?ReflectionMethod $method): array
    {
        $parameters = [];

        foreach ($this->repeated($method, ApiParameter::class) as $parameter) {
            $parameters[$parameter->name] ??= $parameter;
        }

        return $parameters;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $attribute
     * @return T|null
     */
    private function attribute(?ReflectionMethod $method, string $attribute): ?object
    {
        $found = $method?->getAttributes($attribute) ?? [];

        return $found === [] ? null : $found[0]->newInstance();
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $attribute
     * @return list<T>
     */
    private function repeated(?ReflectionMethod $method, string $attribute): array
    {
        $instances = [];

        foreach ($method?->getAttributes($attribute) ?? [] as $found) {
            $instances[] = $found->newInstance();
        }

        return $instances;
    }

    private function method(RouteDescriptor $route): ?ReflectionMethod
    {
        if (! class_exists($route->controllerClass) || ! method_exists($route->controllerClass, $route->methodName)) {
            return null;
        }

        return new ReflectionMethod($route->controllerClass, $route->methodName);
    }

    /**
     * The first non-empty candidate, which is the precedence rule in one expression.
     */
    private function first(string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if (trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    /**
     * The tag a viewer groups this controller's operations under: the class's short name with a trailing
     * "Controller" removed, so `Lumen\Web\WalletController` reads as "Wallet".
     */
    private function shortName(string $class): string
    {
        $short = str_contains($class, '\\') ? substr($class, (int) strrpos($class, '\\') + 1) : $class;

        return str_ends_with($short, 'Controller') && $short !== 'Controller'
            ? substr($short, 0, -strlen('Controller'))
            : $short;
    }

    /**
     * `getBalance` reads as "Get balance". The last-resort summary, used only when a method carries no
     * #[ApiOperation] AND no prose in its docblock — a method name is the one human-authored label every
     * route is guaranteed to have (a #[Mapping]'s name is a Laravel route name, not prose).
     */
    private function humanise(string $method): string
    {
        $words = preg_split('/(?=[A-Z])/', $method);
        $sentence = strtolower(trim(implode(' ', $words === false ? [$method] : $words)));

        return $sentence === '' ? $method : ucfirst($sentence);
    }
}
