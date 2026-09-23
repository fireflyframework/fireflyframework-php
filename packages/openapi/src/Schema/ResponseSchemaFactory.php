<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

use Firefly\OpenApi\Generator\DocBlock;
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
        $inline = TypeSchema::for($class);

        if ($inline !== null) {
            return $inline;
        }

        if (is_a($class, self::ENUMERABLE, true)) {
            return $this->collection($arguments);
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
        $schema = $this->declaredShape($reflection, $registry, $bindings) ?? $this->reflectedShape($reflection, $registry, $bindings);

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
            fn (string $type): array => TypeSchema::for($type) ?? ['$ref' => $this->ref($type, $registry)],
        );
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
