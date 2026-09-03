<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

use Firefly\Validation\Constraint\ConstraintManifest;
use Illuminate\Contracts\Validation\ValidationRule;
use ReflectionClass;
use ReflectionParameter;

/**
 * Builds the `components/schemas` entry for one request-body DTO, from two sources that each know half of it.
 *
 * ConstraintManifest knows the VALIDATION contract — which members are required, what shapes they must have —
 * but nothing about types, because a rule list is untyped by construction. The DTO's own constructor knows
 * the TYPE contract — `?int`, a backed enum, a nested DTO, a default value — but nothing about the
 * constraints, which live in attributes the compiled manifest has already digested. Neither alone produces a
 * usable schema: types-only documents `#[NotBlank] string $name` as an unbounded string, constraints-only
 * documents `int $quantity` as a string.
 *
 * THE REFLECTION HERE IS DELIBERATE, AND IT IS NOT ON THE RUNTIME PATH. packages/web's invariant is that
 * nothing on the CACHED REQUEST path reflects — RouteScanner and ConstraintScanner both run only at
 * `firefly:cache` time — and this class honours the same rule by living where they live: it runs when the
 * `firefly:openapi` command generates a file, or on a hit to the spec route, whose result the generator
 * memoises. It never runs while dispatching an application request. The alternative — teaching RouteScanner
 * to emit per-property types into RouteDescriptor's Binding — was rejected because it would grow the
 * compiled route manifest of every app for the benefit of one optional package.
 *
 * THE PROPERTY LIST is the constructor's parameter list, in declaration order, because that is EXACTLY what
 * ArgumentResolver hydrates from: it picks `binding['properties']` (itself `getConstructor()->getParameters()`,
 * captured at scan time) out of the decoded body and splats them as named arguments. Keys outside that list
 * are silently ignored rather than rejected, which is why NO `additionalProperties: false` is emitted — the
 * server genuinely accepts extra members, and a spec that said otherwise would make conforming clients fail
 * requests the server would have served. Rules keyed to a member with no constructor parameter are still
 * documented: BeanValidator validates the RAW decoded array, so such a member is enforced on input even
 * though it is never hydrated.
 *
 * NESTED DTOs become their own component and a `$ref`, never an inlined object — see SchemaRegistry. When the
 * nested class has its own manifest entry (the normal case: ConstraintManifestCompiler compiles every class
 * under the app's scan roots, not just body DTOs) its own rules are used. When it does not, the parent's
 * dotted `#[Valid]`-cascaded keys (`beneficiary.postcode`) are unflattened back into it, so a nested schema
 * is still constrained rather than a bare `type: object`.
 */
final class DtoSchemaFactory
{
    public function __construct(
        private readonly ConstraintManifest $constraints,
        private readonly ConstraintSchemaMapper $mapper,
    ) {}

    /**
     * @param  list<string>  $properties  the compiled binding's accepted key list, used when $class cannot be
     *                                    autoloaded in this process and reflection is therefore unavailable
     * @param  array<string, list<string|ValidationRule>>  $fallbackRules  a nested class's rules recovered
     *                                                                     from the parent's dotted keys
     */
    public function ref(string $class, SchemaRegistry $registry, array $properties = [], array $fallbackRules = []): string
    {
        return $registry->ref($class, fn (): array => $this->build($class, $registry, $properties, $fallbackRules));
    }

    /**
     * @param  list<string>  $properties
     * @param  array<string, list<string|ValidationRule>>  $fallbackRules
     * @return array<string, mixed>
     */
    private function build(string $class, SchemaRegistry $registry, array $properties, array $fallbackRules): array
    {
        $rules = $this->constraints->rulesFor($class);
        if ($rules === []) {
            $rules = $fallbackRules;
        }

        [$own, $nested] = $this->partition($rules);

        $fields = [];
        $required = [];

        foreach ($this->members($class, $properties, $own) as $name => $member) {
            $property = $this->property($member, $own[$name] ?? [], $nested[$name] ?? [], $registry);

            $fields[$name] = $property->schema;
            if ($property->required) {
                $required[] = $name;
            }
        }

        $schema = [
            'type' => 'object',
            'title' => $this->title($class),
            'description' => 'Request payload bound from '.$class.'.',
            'properties' => $fields,
        ];

        // An empty `required` array is invalid under the OpenAPI 3.1 meta-schema (minItems: 1), so the key is
        // omitted rather than emitted empty — a distinction a strict validator does enforce.
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @param  list<string|ValidationRule>  $rules  this member's own compiled rule list
     * @param  array<string, list<string|ValidationRule>>  $nestedRules  rules cascaded from a parent #[Valid]
     */
    private function property(MemberType $member, array $rules, array $nestedRules, SchemaRegistry $registry): PropertySchema
    {
        $type = $member->type;

        if ($type !== null && TypeSchema::isDto($type)) {
            $ref = $this->ref($type, $registry, [], $nestedRules);

            // A `$ref` cannot usefully be widened with a sibling `type` in 2020-12 (the reference's own
            // keywords win), so a nullable nested DTO is spelled as the union it actually is.
            $schema = $member->nullable
                ? ['anyOf' => [['$ref' => $ref], ['type' => 'null']]]
                : ['$ref' => $ref];

            return new PropertySchema($schema, $this->mapper->apply([], $rules, false, $member->required())->required);
        }

        $base = TypeSchema::for($type) ?? [];
        $property = $this->mapper->apply($base, $rules, $member->nullable, $member->required());

        if ($member->hasDefault && $member->default !== null && ! array_key_exists('default', $property->schema)) {
            return new PropertySchema([...$property->schema, 'default' => $member->default], $property->required);
        }

        return $property;
    }

    /**
     * The members to document, in the order a reader expects: constructor parameters first (declaration
     * order, the order the class itself states), then any rule-only member the constructor does not take.
     *
     * @param  list<string>  $properties
     * @param  array<string, list<string|ValidationRule>>  $own
     * @return array<string, MemberType>
     */
    private function members(string $class, array $properties, array $own): array
    {
        $members = [];

        foreach ($this->parameters($class) as $parameter) {
            $members[$parameter->getName()] = MemberType::fromParameter($parameter);
        }

        if ($members === []) {
            // No constructor to reflect (the class is not autoloadable here, or takes no arguments): fall
            // back to the compiled binding's key list, which RouteScanner captured from the same source.
            foreach ($properties as $name) {
                $members[$name] = MemberType::unknown();
            }
        }

        foreach (array_keys($own) as $name) {
            $members[$name] ??= MemberType::unknown();
        }

        return $members;
    }

    /**
     * @return list<ReflectionParameter>
     */
    private function parameters(string $class): array
    {
        if (! class_exists($class)) {
            return [];
        }

        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || $reflection->isInterface()) {
            return [];
        }

        return $reflection->getConstructor()?->getParameters() ?? [];
    }

    /**
     * Splits a class's compiled rules into its OWN members and the dotted keys #[Valid] cascaded down from
     * it, re-nesting the latter under their first segment so a nested schema can be built from them when the
     * nested class has no manifest entry of its own.
     *
     * @param  array<string, list<string|ValidationRule>>  $rules
     * @return array{array<string, list<string|ValidationRule>>, array<string, array<string, list<string|ValidationRule>>>}
     */
    private function partition(array $rules): array
    {
        $own = [];
        $nested = [];

        foreach ($rules as $key => $list) {
            if (! str_contains($key, '.')) {
                $own[$key] = $list;

                continue;
            }

            [$parent, $child] = explode('.', $key, 2);
            $nested[$parent][$child] = $list;
        }

        return [$own, $nested];
    }

    private function title(string $class): string
    {
        return str_contains($class, '\\') ? substr($class, strrpos($class, '\\') + 1) : $class;
    }
}
