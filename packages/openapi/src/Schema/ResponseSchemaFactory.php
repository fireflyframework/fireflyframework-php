<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

use Firefly\OpenApi\Generator\DocBlock;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;
use JsonSerializable;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Builds the `components/schemas` entry for a class an action RETURNS.
 *
 * WHY IT IS NOT DtoSchemaFactory. That factory documents a request body, and it derives the member list from
 * the CONSTRUCTOR and the member rules from the compiled ConstraintManifest — the right two sources for a
 * payload the server binds and validates. Neither applies on the way out. A response is never validated, so
 * there are no rules; and a response object's members are what `json_encode` emits, which is its PUBLIC
 * PROPERTIES and not its constructor parameters. The two coincide for a promoted-property DTO and diverge
 * the moment a class takes a collaborator it does not expose, or exposes a value it did not take. Reusing
 * the request factory here would have documented every response as its own constructor — including the
 * private dependencies.
 *
 * THE WIRE SHAPE IS WHAT `json_encode` PRODUCES, and PHP gives that two spellings:
 *
 *   - A class implementing JsonSerializable serialises as whatever `jsonSerialize()` RETURNS, which may look
 *     nothing like its properties. App\Orders\Order is the case that matters: it publishes a `total` that is
 *     a derived method, not a property, so reflecting properties alone documents five of the six members
 *     that actually appear on the wire. The method's own `@return array{...}` states the shape exactly, and
 *     that is read first.
 *   - Everything else serialises as its public properties, which is what reflection reads.
 *
 * A DECLARED SHAPE ONLY WINS WHEN IT SAYS SOMETHING. `@return array<string, mixed>` parses fine and means
 * "an object, members unknown" — strictly less than the property list it would have suppressed. So a parsed
 * shape is accepted only when it carries members (`properties`), an element type (`items`), a value type
 * (`additionalProperties`) or a reference; otherwise the reflection path runs and the comment is ignored.
 * That is the difference between reading a docblock and obeying one.
 *
 * PROPERTY TYPES come from the declared PHP type, refined by PHPDoc where PHP cannot speak: `array` with a
 * `@var list<OrderLine>` on the property, or — for a promoted property, whose docblock PHP attaches to the
 * constructor parameter rather than the property — the constructor's `@param` line for it. Without that
 * refinement every collection member in every response is `Array<any>`, which is the same hole this package
 * already closed on the request side.
 *
 * NULLABILITY IS NOT REQUIREDNESS. A response member is present or it is not, and a `?int $id` is always
 * PRESENT — it is simply sometimes null. So every public property is `required` and nullable ones widen
 * their type, which is the opposite of the request side, where a nullable member is usually omissible. A
 * document that marked `id` optional would tell a generated client to expect its absence, and it never is.
 */
final class ResponseSchemaFactory
{
    /**
     * Laravel's collection contract — Collection, LazyCollection, Eloquent's Collection. Named rather than
     * imported: illuminate/collections arrives with illuminate/support, but is_a() on a string is all this
     * needs and costs nothing when the class is absent.
     */
    private const string ENUMERABLE = 'Illuminate\Support\Enumerable';

    /**
     * Generic instantiations currently being built INLINE (see schema()), so one that refers to itself
     * degrades to the plain component instead of expanding forever. Empty between calls.
     *
     * @var array<string, true>
     */
    private array $building = [];

    /**
     * The fragment that stands for $class in a response position: an inline one for the types that have a
     * scalar spelling (a backed enum, a DateTimeInterface), a `$ref` for anything reflectable, and the
     * any-value schema for a class this process cannot look at.
     *
     * GENERIC INSTANTIATIONS. $arguments are the schemas of a generic spelling's type arguments — the `Order`
     * in `Page<Order>` — and they are bound to the class's own `@template` parameters, so the `list<T>` its
     * constructor documents becomes a list of Order. An instantiation gets a component of its own, named the
     * way springdoc names one (`PageOrder`), whenever every argument has a name to give it; one whose
     * arguments have none (`Page<list<Order>>`) is written in place rather than under a name no reader could
     * predict; and one whose arguments say nothing (`Page<mixed>`) is simply the plain component. A Laravel
     * Collection is not a component at all: it serialises as its elements.
     *
     * @param  list<array<string, mixed>>  $arguments
     * @return array<string, mixed>
     */
    public function schema(string $class, SchemaRegistry $registry, array $arguments = []): array
    {
        // The wire shapes that are facts about a CONTRACT come first: TypeSchema answers every interface with
        // the any-value schema, so a `Contracts\Pagination\LengthAwarePaginator` or an `Enumerable` return
        // would otherwise never reach them.
        if (is_a($class, self::ENUMERABLE, true)) {
            return $this->collection($arguments);
        }

        $paginator = PaginatorSchema::contract($class);
        if ($paginator !== null) {
            return $this->paginator($paginator, $arguments, $registry);
        }

        // A resource collection is the list of what it collects; its own members are machinery.
        if (ResourceSchema::isCollection($class)) {
            /** @var class-string $class */
            $collects = ResourceSchema::collects(new ReflectionClass($class));

            return $collects === null ? ['type' => 'array'] : ['type' => 'array', 'items' => $this->schema($collects, $registry)];
        }

        $inline = TypeSchema::for($class);

        if ($inline !== null) {
            return $inline;
        }

        /** @var class-string $class */
        $reflection = new ReflectionClass($class);
        $bindings = $this->bind($reflection, $arguments);

        if (array_filter($bindings, static fn (array $schema): bool => $schema !== []) === []) {
            return ['$ref' => $this->ref($class, $registry)];
        }

        $names = $this->argumentNames($bindings);
        if ($names !== null) {
            return ['$ref' => $registry->refSpecialised(
                $class,
                implode('', array_map(ucfirst(...), $names)),
                fn (): array => $this->build($reflection, $registry, $bindings, $reflection->getShortName().'<'.implode(', ', $names).'>'),
            )];
        }

        $key = $class.'<'.json_encode($bindings).'>';
        if (isset($this->building[$key])) {
            return ['$ref' => $this->ref($class, $registry)];
        }

        $this->building[$key] = true;
        try {
            return $this->build($reflection, $registry, $bindings, $reflection->getShortName());
        } finally {
            unset($this->building[$key]);
        }
    }

    /** @return string the `$ref` pointer to this class's component schema */
    public function ref(string $class, SchemaRegistry $registry): string
    {
        /** @var class-string $class */
        $reflection = new ReflectionClass($class);

        return $registry->ref($class, fn (): array => $this->build($reflection, $registry, $this->bind($reflection, []), $reflection->getShortName()));
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @param  array<string, array<string, mixed>>  $bindings  the class's template parameters, bound
     * @return array<string, mixed>
     */
    private function build(ReflectionClass $reflection, SchemaRegistry $registry, array $bindings, string $title): array
    {
        $schema = match (true) {
            ResourceSchema::isResource($reflection->getName()) => $this->resourceShape($reflection, $registry, $bindings),
            $reflection->implementsInterface(Arrayable::class) => $this->arrayableShape($reflection, $registry, $bindings),
            default => $this->declaredShape($reflection, $registry, $bindings) ?? $this->reflectedShape($reflection, $registry, $bindings),
        };

        $description = DocBlock::parse($reflection->getDocComment())->prose();

        return [
            'title' => $title,
            'description' => $description === '' ? 'Response payload serialised from '.$reflection->getName().'.' : $description,
            ...$schema,
        ];
    }

    /**
     * The shape a JsonSerializable class states for itself, when it states one worth having.
     *
     * @param  ReflectionClass<object>  $class
     * @param  array<string, array<string, mixed>>  $bindings
     * @return array<string, mixed>|null
     */
    private function declaredShape(ReflectionClass $class, SchemaRegistry $registry, array $bindings): ?array
    {
        if (! $class->implementsInterface(JsonSerializable::class) || ! $class->hasMethod('jsonSerialize')) {
            return null;
        }

        $method = $class->getMethod('jsonSerialize');
        $line = DocBlock::parse($method->getDocComment())->returnLine();

        if ($line === null) {
            return null;
        }

        $declaring = $method->getDeclaringClass();
        [$schema] = DocType::split($line, fn (string $c, array $arguments = []): array => $this->schema($c, $registry, $arguments), $declaring, $this->scope($class, $bindings, $declaring, $registry));

        return $this->informative($schema) ? $schema : null;
    }

    /**
     * The envelope a returned $class is sent in: a Laravel API resource's `$wrap` around $schema, or $schema
     * itself. Applied by whoever documents a RESPONSE, never inside a component — see ResourceSchema.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function envelope(string $class, array $schema): array
    {
        $wrap = ResourceSchema::wrap($class);

        return $wrap === null ? $schema : ['type' => 'object', 'properties' => [$wrap => $schema], 'required' => [$wrap]];
    }

    /**
     * A resource's bare shape — what resolve() returns. Its own toArray() `@return` when it overrides the
     * method; JsonResource's inherited toArray() hands back the underlying resource's own array, so then the
     * class it `@mixin`s; and otherwise an object nothing describes.
     *
     * @param  ReflectionClass<object>  $class
     * @param  array<string, array<string, mixed>>  $bindings
     * @return array<string, mixed>
     */
    private function resourceShape(ReflectionClass $class, SchemaRegistry $registry, array $bindings): array
    {
        $method = $class->getMethod('toArray');
        $declaring = $method->getDeclaringClass();

        if (ResourceSchema::isResource($declaring->getName()) && $declaring->getName() !== JsonResource::class) {
            $line = DocBlock::parse($method->getDocComment())->returnLine();
            if ($line !== null) {
                [$schema] = DocType::split($line, fn (string $c, array $arguments = []): array => $this->schema($c, $registry, $arguments), $declaring, $this->scope($class, $bindings, $declaring, $registry));

                if ($this->informative($schema)) {
                    return $schema ?? [];
                }
            }

            return ['type' => 'object'];
        }

        foreach (DocBlock::parse($class->getDocComment())->tag('mixin') as $mixin) {
            $mixed = ClassNames::resolve((string) strtok(trim($mixin), " \t\n"), $class);
            if ($mixed !== null) {
                return $this->schema($mixed, $registry);
            }
        }

        return ['type' => 'object'];
    }

    /**
     * What JsonMessageConverter writes for an Arrayable — toArray(), ahead of JsonSerializable and never the
     * public properties. An Eloquent model is read by EloquentSchema; anything else by its toArray() `@return`,
     * then by the value type an `@implements Arrayable<K, V>` declares, and otherwise as an object whose
     * members nothing states — which is less than a property list, and true where a property list is not.
     *
     * @param  ReflectionClass<object>  $class
     * @param  array<string, array<string, mixed>>  $bindings
     * @return array<string, mixed>
     */
    private function arrayableShape(ReflectionClass $class, SchemaRegistry $registry, array $bindings): array
    {
        if (EloquentSchema::isModel($class->getName())) {
            return EloquentSchema::shape($class, fn (string $c, array $arguments = []): array => $this->schema($c, $registry, $arguments));
        }

        $method = $class->getMethod('toArray');
        $line = DocBlock::parse($method->getDocComment())->returnLine();

        if ($line !== null) {
            $declaring = $method->getDeclaringClass();
            [$schema] = DocType::split($line, fn (string $c, array $arguments = []): array => $this->schema($c, $registry, $arguments), $declaring, $this->scope($class, $bindings, $declaring, $registry));

            if ($this->informative($schema)) {
                return $schema ?? [];
            }
        }

        foreach (DocBlock::parse($class->getDocComment())->implementsLines() as $implements) {
            if (preg_match('/^\\\\?([A-Za-z_][\\w\\\\]*)/', $implements, $name) !== 1 || ClassNames::resolve($name[1], $class) !== Arrayable::class) {
                continue;
            }

            $arguments = DocType::genericArguments($implements, fn (string $c, array $arguments = []): array => $this->schema($c, $registry, $arguments), $class, $bindings);

            return $this->collection($arguments ?? []);
        }

        return ['type' => 'object'];
    }

    /**
     * Whether a parsed schema says more than "some object" — see the class docblock on why a shape that does
     * not is discarded in favour of reflection.
     *
     * @param  array<string, mixed>|null  $schema
     */
    private function informative(?array $schema): bool
    {
        if ($schema === null) {
            return false;
        }

        foreach (['properties', 'items', 'additionalProperties', '$ref', 'enum', 'anyOf', 'allOf', 'prefixItems'] as $key) {
            if (array_key_exists($key, $schema)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The public properties, which is exactly what `json_encode` walks for a plain object.
     *
     * @param  ReflectionClass<object>  $class
     * @param  array<string, array<string, mixed>>  $bindings
     * @return array<string, mixed>
     */
    private function reflectedShape(ReflectionClass $class, SchemaRegistry $registry, array $bindings): array
    {
        $constructor = $class->getConstructor();
        $constructorDoc = DocBlock::parse($constructor?->getDocComment());
        $promotedTypes = $constructorDoc->paramTypes();

        /** @var array<string, ReflectionParameter> $parameters */
        $parameters = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }

        $properties = [];
        $required = [];

        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $name = $property->getName();

            // A promoted property's prose lives on the CONSTRUCTOR's `@param` line, not on the property —
            // PHP attaches no docblock to the property it synthesises — so the parameter is the richer
            // source whenever there is one.
            $doc = isset($parameters[$name])
                ? MemberDoc::forParameter($parameters[$name], $constructorDoc)
                : MemberDoc::forProperty($property);

            // A member is read in the scope of the class that DECLARES it — that file's imports and that
            // class's template parameters — which for an inherited one is an ancestor, not $class.
            $declaring = $property->getDeclaringClass();
            $templates = $this->scope($class, $bindings, $declaring, $registry);

            $properties[$name] = $doc->apply($this->property($property, $promotedTypes[$name] ?? null, $declaring, $templates, $registry));
            $required[] = $name;
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * One property's schema: the declared type, refined by PHPDoc. #[ApiProperty] and the prose are layered
     * on by the caller through MemberDoc, which already owns that precedence for the request side.
     *
     * The PHPDoc refinement is applied only when it is at least as specific as the declared type — a
     * `@var list<Line>` on an `array` property is a strict improvement, whereas a stale `@var string` on an
     * `int` property is a comment that has drifted from the code, and the code is what serialises.
     *
     * @param  ReflectionClass<object>  $declaring
     * @param  array<string, array<string, mixed>>  $templates
     * @return array<string, mixed>
     */
    private function property(ReflectionProperty $property, ?string $promotedType, ReflectionClass $declaring, array $templates, SchemaRegistry $registry): array
    {
        $expression = DocBlock::parse($property->getDocComment())->varType() ?? $promotedType;

        $schema = null;
        if ($expression !== null) {
            $schema = DocType::schema($expression, fn (string $c, array $arguments = []): array => $this->schema($c, $registry, $arguments), $declaring, $templates);
        }

        if ($this->informative($schema)) {
            return $schema ?? [];
        }

        return TypeSchema::reflected(
            $property->getType(),
            fn (string $type): array => class_exists($type) || interface_exists($type) ? $this->schema($type, $registry) : (TypeSchema::for($type) ?? []),
        );
    }

    /**
     * A Laravel paginator's envelope around its elements, named like any generic instantiation — by the element
     * type, the TValue of `LengthAwarePaginator<int, Order>` — and keyed by the paginator CONTRACT, so the class
     * and its contract share one component.
     *
     * @param  class-string  $contract
     * @param  list<array<string, mixed>>  $arguments
     * @return array<string, mixed>
     */
    private function paginator(string $contract, array $arguments, SchemaRegistry $registry): array
    {
        $element = $arguments[count($arguments) >= 2 ? 1 : 0] ?? [];
        $short = substr($contract, strrpos($contract, '\\') + 1);
        $build = static fn (string $title): array => [
            'title' => $title,
            'description' => PaginatorSchema::description($contract),
            ...PaginatorSchema::envelope($contract, $element),
        ];

        if ($element === []) {
            return ['$ref' => $registry->ref($contract, static fn (): array => $build($short))];
        }

        $names = $this->argumentNames(['TValue' => $element]);
        if ($names === null) {
            return $build($short);
        }

        return ['$ref' => $registry->refSpecialised($contract, ucfirst($names[0]), static fn (): array => $build($short.'<'.$names[0].'>'))];
    }

    /**
     * A collection serialises as its elements: a list, or — keyed by anything but integers — a map.
     * `Collection<int, Order>` is the list of orders; `Collection<string, Money>` an object of Money.
     *
     * @param  list<array<string, mixed>>  $arguments
     * @return array<string, mixed>
     */
    private function collection(array $arguments): array
    {
        $keyed = count($arguments) >= 2;
        $value = $arguments[$keyed ? 1 : 0] ?? [];
        $key = $keyed ? ($arguments[0]['type'] ?? null) : 'integer';

        if ($key !== 'integer' && $key !== ['integer']) {
            return $value === [] ? ['type' => 'object'] : ['type' => 'object', 'additionalProperties' => $value];
        }

        return $value === [] ? ['type' => 'array'] : ['type' => 'array', 'items' => $value];
    }

    /**
     * The class's template parameters paired with the arguments of an instantiation, in declaration order.
     * A parameter no argument reaches is bound to the any-value schema, which is what an unbound one means —
     * and binding it at all is what keeps `T` from being looked up as a class named T.
     *
     * @param  ReflectionClass<object>  $class
     * @param  list<array<string, mixed>>  $arguments
     * @return array<string, array<string, mixed>>
     */
    private function bind(ReflectionClass $class, array $arguments): array
    {
        $bindings = [];

        foreach (DocBlock::parse($class->getDocComment())->templates() as $position => $name) {
            $bindings[$name] = $arguments[$position] ?? [];
        }

        return $bindings;
    }

    /**
     * The template scope of $declaring, given $class's bindings: $class's own when they are the same class,
     * otherwise each ancestor's in turn, through the arguments every `@extends` line up the chain passes to
     * its parent. `final class OrderEnvelope extends Envelope` with `@extends Envelope<Order>` is what makes
     * Envelope's `TData $data` an Order.
     *
     * @param  ReflectionClass<object>  $class
     * @param  array<string, array<string, mixed>>  $bindings
     * @param  ReflectionClass<object>  $declaring
     * @return array<string, array<string, mixed>>
     */
    private function scope(ReflectionClass $class, array $bindings, ReflectionClass $declaring, SchemaRegistry $registry): array
    {
        $current = $class;

        while ($current->getName() !== $declaring->getName()) {
            $parent = $current->getParentClass();
            if ($parent === false) {
                return [];
            }

            $line = DocBlock::parse($current->getDocComment())->extendsLine();
            $arguments = $line === null ? [] : (DocType::genericArguments($line, fn (string $c, array $arguments = []): array => $this->schema($c, $registry, $arguments), $current, $bindings) ?? []);

            $bindings = $this->bind($parent, $arguments);
            $current = $parent;
        }

        return $bindings;
    }

    /**
     * What each bound argument is CALLED, for the name of the instantiation's component — its own component
     * name, or its JSON type for a scalar — or null when any of them has no name worth building one from.
     *
     * @param  array<string, array<string, mixed>>  $bindings
     * @return list<string>|null
     */
    private function argumentNames(array $bindings): ?array
    {
        $names = [];

        foreach ($bindings as $schema) {
            $ref = $schema['$ref'] ?? null;

            if (is_string($ref) && count($schema) === 1) {
                $names[] = substr($ref, strrpos($ref, '/') + 1);

                continue;
            }

            $type = $schema['type'] ?? null;
            if (count($schema) === 1 && in_array($type, ['string', 'integer', 'number', 'boolean'], true)) {
                $names[] = $type;

                continue;
            }

            return null;
        }

        return $names;
    }
}
