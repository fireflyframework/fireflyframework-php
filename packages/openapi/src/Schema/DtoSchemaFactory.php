<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

use Firefly\OpenApi\Generator\DocBlock;
use Firefly\Validation\Constraint\ConstraintManifest;
use Illuminate\Contracts\Validation\ValidationRule;
use ReflectionClass;

/**
 * Builds the `components/schemas` entry for one request-body DTO, from three sources that each know part of
 * it: the compiled constraints, the constructor signature, and the class's own PHPDoc (plus #[ApiProperty]
 * where an author has something to add that none of the three can state).
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
 * THE PROSE comes from the class docblock (which becomes the schema's `description`), each member's own
 * docblock or the constructor's `@param` line for it (which becomes the member's), and #[ApiProperty] over
 * both — see MemberDoc, which owns that precedence. It is read here for the same reason the types are: the
 * text is already written, sitting in the file, and a schema that repeats a member's own name back at the
 * reader is worse than one that says nothing. The reflection this costs is the same reflection the
 * constructor already required.
 *
 * NESTED DTOs become their own component and a `$ref`, never an inlined object — see SchemaRegistry. When the
 * nested class has its own manifest entry (the normal case: ConstraintManifestCompiler compiles every class
 * under the app's scan roots, not just body DTOs) its own rules are used. When it does not, the parent's
 * dotted `#[Valid]`-cascaded keys (`beneficiary.postcode`) are unflattened back into it, so a nested schema
 * is still constrained rather than a bare `type: object`.
 *
 * A LIST of nested DTOs is the same story told through `items`, and it is the case this factory used to get
 * silently wrong: `#[Valid] array $lines` documented itself as a bare `type: array`, the element class never
 * became a component at all, and a generated client got `Array<any>` for the one member that most needed a
 * type. PHP's `array` says nothing about its elements, so the element class comes from ElementTypes — which
 * reads the table RouteScanner already compiled for the HYDRATOR, so the document and the server agree by
 * construction. That table is threaded down the whole descent rather than looked up once, because it is keyed
 * by class and therefore answers for every level of the graph, not just the body DTO at the top.
 *
 * RULES FOR A LIST ELEMENT COME FROM THE ELEMENT'S OWN MANIFEST ENTRY, never from the parent's dotted keys.
 * ConstraintScanner cascades a #[Valid] only through a CLASS-typed member (classTypeOf() returns null for an
 * `array`), so a parent's dotted keys can never describe a list element in the first place — and unflattening
 * a Laravel-style `lines.*.sku` into an element schema would invent a member literally named `*.sku`.
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
     * @param  ElementTypes  $elements  the compiled `dtos` table of the binding this DTO was reached through;
     *                                  defaults to an empty one, which resolves element types by reflection
     */
    public function ref(string $class, SchemaRegistry $registry, array $properties = [], array $fallbackRules = [], ElementTypes $elements = new ElementTypes): string
    {
        return $registry->ref($class, fn (): array => $this->build($class, $registry, $properties, $fallbackRules, $elements));
    }

    /**
     * @param  list<string>  $properties
     * @param  array<string, list<string|ValidationRule>>  $fallbackRules
     * @return array<string, mixed>
     */
    private function build(string $class, SchemaRegistry $registry, array $properties, array $fallbackRules, ElementTypes $elements): array
    {
        $rules = $this->constraints->rulesFor($class);
        if ($rules === []) {
            $rules = $fallbackRules;
        }

        [$own, $nested] = $this->partition($rules);

        // Resolved once for the whole class rather than per member: both paths behind it — a table row and a
        // constructor docblock — answer for every member at once, and asking per member would re-read the
        // same doc comment once per `array` property.
        $lists = $elements->forClass($class);

        // The constructor's own `@param` expressions, for the members PHP's `array` cannot describe. Read
        // once per class for the same reason the element table is.
        $reflection = $this->reflect($class);
        $documented = DocBlock::parse($reflection?->getConstructor()?->getDocComment())->paramTypes();

        $fields = [];
        $required = [];

        foreach ($this->members($class, $properties, $own) as $name => $member) {
            $property = $this->property($member, $own[$name] ?? [], $nested[$name] ?? [], $registry, $elements, $lists[$name] ?? null, $documented[$name] ?? null, $reflection);

            $fields[$name] = $property->schema;
            if ($property->required) {
                $required[] = $name;
            }
        }

        $schema = [
            'type' => 'object',
            'title' => $this->title($class),
            'description' => $this->description($class),
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
     * @param  string|null  $element  the class this member's list holds, when it holds a list of one
     * @param  string|null  $documented  the constructor's `@param` type expression for this member
     * @param  ReflectionClass<object>|null  $declaring  the class the expression was written inside
     */
    private function property(MemberType $member, array $rules, array $nestedRules, SchemaRegistry $registry, ElementTypes $elements, ?string $element, ?string $documented = null, ?ReflectionClass $declaring = null): PropertySchema
    {
        $type = $member->type;

        if ($type !== null && TypeSchema::isDto($type)) {
            $ref = $this->ref($type, $registry, [], $nestedRules, $elements);

            // A `$ref` cannot usefully be widened with a sibling `type` in 2020-12 — VALIDATION keywords
            // beside a reference are applied WITH it, so `type: 'null'` would have to pass as well as the
            // reference and could never hold — which is why a nullable nested DTO is spelled as the union it
            // actually is. Annotations are the opposite case: a `description` beside a `$ref` is legal in
            // 2020-12 and therefore in 3.1, so MemberDoc::apply() is safe on either shape.
            $schema = $member->nullable
                ? ['anyOf' => [['$ref' => $ref], ['type' => 'null']]]
                : ['$ref' => $ref];

            return new PropertySchema(
                $member->doc->apply($schema),
                $this->mapper->apply([], $rules, false, $member->required())->required,
            );
        }

        $base = TypeSchema::for($type) ?? [];

        // `items` is seeded into the BASE rather than layered on afterwards so the constraint mapper's
        // first-writer-wins ordering sees a complete declared-type fragment — and so `minItems`/`maxItems`
        // still resolve against the `type: array` sitting beside it, which is how MapperState tells a #[Size]
        // on a list from a #[Size] on a string.
        $items = $element === null ? null : $this->items($element, $registry, $elements);
        if ($items !== null && ($base['type'] ?? null) === 'array') {
            $base['items'] = $items;
        } elseif ($element === null && ($base['type'] ?? null) === 'array' && $documented !== null) {
            // The compiled table had nothing for this member, which means the hydrator does not treat it as a
            // list of DTOs — and that is every collection PHP's `array` describes and the table does not:
            // `list<string>`, `array<string, int>`, `list<list<int>>`. Each of those was published as a bare
            // `type: array`, so a list of scalars became Array<any> and a MAP was documented as an array,
            // which is not merely vague but the wrong JSON type. The expression is read only when the table
            // declined, so the hydrator's answer still wins wherever it has one.
            $shape = DocType::schema($documented, fn (string $c): array => $this->classSchema($c, $registry, $elements), $declaring);

            if ($shape !== null && isset($shape['type']) && in_array($shape['type'], ['array', 'object'], true)) {
                $base = [...$shape, ...array_diff_key($base, ['type' => null])];
            }
        }

        $property = $this->mapper->apply($base, $rules, $member->nullable, $member->required());
        $schema = $property->schema;

        if ($member->hasDefault && $member->default !== null && ! array_key_exists('default', $schema)) {
            $schema['default'] = $member->default;
        }

        return new PropertySchema($member->doc->apply($schema), $property->required);
    }

    /**
     * The fragment that stands for a class inside a documented type expression — the same choice items()
     * makes, hoisted so DocType can call it for a class at any depth of a shape.
     *
     * @return array<string, mixed>
     */
    private function classSchema(string $class, SchemaRegistry $registry, ElementTypes $elements): array
    {
        if (TypeSchema::isDto($class)) {
            return ['$ref' => $this->ref($class, $registry, [], [], $elements)];
        }

        return TypeSchema::for($class) ?? [];
    }

    /**
     * The `items` subschema for a list member.
     *
     * A list of DTOs becomes a `$ref` — the recursion is safe for the same reason a plain nested DTO's is:
     * SchemaRegistry reserves the component name BEFORE the builder runs, so `CategoryNode { list<CategoryNode>
     * $children }` closes its own cycle on the component being built instead of expanding forever. A list of
     * anything TypeSchema can state inline (a backed enum, a DateTimeInterface, a scalar) is inlined instead:
     * an enum is not a reusable component, and minting one per enum would hand every generated client a named
     * type where an inline union is what the payload actually is.
     *
     * An element TypeSchema cannot resolve at all yields null rather than an empty `{}` subschema. The two say
     * exactly the same thing — an absent `items` accepts any element — and the empty one says it in the one
     * spelling that has to survive a later `[]`-vs-`{}` decision at encoding time.
     *
     * @return array<string, mixed>|null
     */
    private function items(string $element, SchemaRegistry $registry, ElementTypes $elements): ?array
    {
        if (TypeSchema::isDto($element)) {
            return ['$ref' => $this->ref($element, $registry, [], [], $elements)];
        }

        $schema = TypeSchema::for($element) ?? [];

        return $schema === [] ? null : $schema;
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
        $reflection = $this->reflect($class);
        $constructor = $reflection?->getConstructor();

        // Parsed ONCE per DTO and handed to every member: `@param` lines all live in the same comment, and
        // re-parsing it per parameter would re-do the same work eight times for an eight-member payload.
        $constructorDoc = DocBlock::parse($constructor?->getDocComment());

        $members = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $members[$parameter->getName()] = MemberType::fromParameter(
                $parameter,
                MemberDoc::forParameter($parameter, $constructorDoc),
            );
        }

        if ($members === []) {
            // No constructor to reflect (the class is not autoloadable here, or takes no arguments): fall
            // back to the compiled binding's key list, which RouteScanner captured from the same source.
            foreach ($properties as $name) {
                $members[$name] = MemberType::unknown($this->memberDoc($reflection, $name));
            }
        }

        foreach (array_keys($own) as $name) {
            $members[$name] ??= MemberType::unknown($this->memberDoc($reflection, $name));
        }

        return $members;
    }

    /**
     * The prose for a member the constructor does not take, read off a declared property of the same name
     * when there is one. A rule-only member with no property at all (validated on the raw decoded array, and
     * nowhere else) simply has nothing to read.
     *
     * @param  ReflectionClass<object>|null  $class
     */
    private function memberDoc(?ReflectionClass $class, string $name): MemberDoc
    {
        return $class !== null && $class->hasProperty($name)
            ? MemberDoc::forProperty($class->getProperty($name))
            : new MemberDoc;
    }

    /**
     * The DTO's class docblock as the schema `description`, falling back to a statement of where the payload
     * is bound from.
     *
     * The fallback is kept rather than dropped because a schema with no description at all reads, in a
     * viewer, as a component nobody has looked at — whereas "Request payload bound from App\Dto\X." at
     * least tells a reader which PHP class to open. It is a locator, not documentation, which is exactly why
     * any real docblock beats it.
     */
    private function description(string $class): string
    {
        $prose = DocBlock::parse($this->reflect($class)?->getDocComment())->prose();

        return $prose === '' ? 'Request payload bound from '.$class.'.' : $prose;
    }

    /**
     * @return ReflectionClass<object>|null
     */
    private function reflect(string $class): ?ReflectionClass
    {
        if (! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        return $reflection->isAbstract() || $reflection->isInterface() ? null : $reflection;
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
