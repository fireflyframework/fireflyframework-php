<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

use Firefly\Validation\Constraint\ContainerElementType;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Answers the one question a PHP `array` type cannot: WHAT IS IN IT.
 *
 * `#[Valid] public readonly array $lines = []` documented itself as `{"type": "array"}` with no `items`,
 * which a client generator faithfully turns into `Array<any>` — a typed client with an untyped hole in it,
 * for a payload whose element type the framework has known all along. `list<OrderLineRequest>` is written in
 * the constructor docblock, and packages/web already reads it: RouteScanner::dtoShapes() resolves it at
 * `firefly:cache` time and compiles it into the body binding's `dtos` table so ArgumentResolver can hydrate
 * the nested payload without reflecting. That table is a `class => member => {class, list}` map covering
 * EVERY class reachable from the body DTO, at any depth, and it is the first thing this class consults —
 * because it is not merely a copy of the answer, it is the answer the HYDRATOR uses, so a document generated
 * from it cannot describe a shape the server would refuse to build.
 *
 * WHY THERE IS A SECOND PATH, AND WHY IT IS ONLY EVER A FALLBACK. Three reachable shapes carry no compiled
 * table: a DTO named by #[ApiResponse(type:)] (a RESPONSE has no binding plan at all), a DTO handed straight
 * to DtoSchemaFactory::ref() by something other than a request body, and a route manifest compiled before
 * RouteScanner emitted the `dtos` key — which is a supported state, since that key is written only when a
 * body DTO actually nests and ArgumentResolver reads it with a `?? []` default. In all three the element type
 * is still sitting in the docblock, and the choice is between reading it and shipping `Array<any>` again.
 * The reading itself is not duplicated any more: RouteScanner, the constraint scanner and this fallback all
 * ask Firefly\Validation\Constraint\ContainerElementType — `#[Valid(each:)]`, then `@var`, then `@param` —
 * so the three cannot disagree about what a list holds. What this class keeps for itself is the restriction
 * to a parameter DECLARED `array`, the same one RouteScanner applies, so a member the hydrator would leave
 * alone is never given `items` here either.
 *
 * The fallback is deliberately not consulted when the table HAS a row for the class. A row is complete — the
 * scanner walked every constructor parameter to build it — so a member missing from it is a member the
 * hydrator will not treat as a list, and second-guessing that with reflection is exactly how the two paths
 * would drift into disagreeing about the same class.
 *
 * @phpstan-import-type PropertyPlan from \Firefly\Web\Route\RouteDescriptor
 */
final class ElementTypes
{
    /**
     * @param  array<string, array<string, PropertyPlan>>  $table  the body binding's compiled `dtos` table,
     *                                                             empty when the caller has none
     */
    public function __construct(private readonly array $table = []) {}

    /**
     * The members of $class that hold a LIST of some class, as member name => element class. A member holding
     * a list of scalars is absent: `list<string>` needs no element CLASS, and TypeSchema has nothing to
     * resolve for it that the constraint list does not already say.
     *
     * @return array<string, string>
     */
    public function forClass(string $class): array
    {
        $row = $this->table[$class] ?? null;

        if ($row === null) {
            return $this->reflect($class);
        }

        $elements = [];
        foreach ($row as $member => $plan) {
            if ($plan['list'] && $plan['class'] !== null) {
                $elements[$member] = $plan['class'];
            }
        }

        return $elements;
    }

    /**
     * The fallback path — see the class docblock for the three shapes that reach it and why it exists.
     *
     * @return array<string, string>
     */
    private function reflect(string $class): array
    {
        if (! class_exists($class)) {
            return [];
        }

        $constructor = (new ReflectionClass($class))->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $elements = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            // Only a parameter DECLARED `array` may take an element type from the resolver. A class-typed
            // member is a nested DTO the caller already resolves from the declared type, and `iterable` is
            // excluded because RouteScanner excludes it — a document that gave `items` to a member the
            // hydrator does not bind as a list would describe a request the server cannot accept.
            if (! $type instanceof ReflectionNamedType || $type->getName() !== 'array') {
                continue;
            }

            $element = ContainerElementType::of($parameter);
            if ($element !== null) {
                $elements[$parameter->getName()] = $element;
            }
        }

        return $elements;
    }
}
