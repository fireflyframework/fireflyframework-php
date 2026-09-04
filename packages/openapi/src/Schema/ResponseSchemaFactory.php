<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

use Firefly\OpenApi\Generator\DocBlock;
use JsonSerializable;
use ReflectionClass;
use ReflectionNamedType;
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
     * The fragment that stands for $class in a response position: an inline one for the types that have a
     * scalar spelling (a backed enum, a DateTimeInterface), a `$ref` for anything reflectable, and the
     * any-value schema for a class this process cannot look at.
     *
     * @return array<string, mixed>
     */
    public function schema(string $class, SchemaRegistry $registry): array
    {
        $inline = TypeSchema::for($class);

        if ($inline !== null) {
            return $inline;
        }

        return ['$ref' => $this->ref($class, $registry)];
    }

    /** @return string the `$ref` pointer to this class's component schema */
    public function ref(string $class, SchemaRegistry $registry): string
    {
        return $registry->ref($class, fn (): array => $this->build($class, $registry));
    }

    /**
     * @return array<string, mixed>
     */
    private function build(string $class, SchemaRegistry $registry): array
    {
        /** @var class-string $class */
        $reflection = new ReflectionClass($class);

        $schema = $this->declaredShape($reflection, $registry) ?? $this->reflectedShape($reflection, $registry);

        $description = DocBlock::parse($reflection->getDocComment())->prose();

        return [
            'title' => $reflection->getShortName(),
            'description' => $description === '' ? 'Response payload serialised from '.$class.'.' : $description,
            ...$schema,
        ];
    }

    /**
     * The shape a JsonSerializable class states for itself, when it states one worth having.
     *
     * @param  ReflectionClass<object>  $class
     * @return array<string, mixed>|null
     */
    private function declaredShape(ReflectionClass $class, SchemaRegistry $registry): ?array
    {
        if (! $class->implementsInterface(JsonSerializable::class) || ! $class->hasMethod('jsonSerialize')) {
            return null;
        }

        $line = DocBlock::parse($class->getMethod('jsonSerialize')->getDocComment())->returnLine();

        if ($line === null) {
            return null;
        }

        [$schema] = DocType::split($line, fn (string $c): array => $this->schema($c, $registry), $class);

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
     * @return array<string, mixed>
     */
    private function reflectedShape(ReflectionClass $class, SchemaRegistry $registry): array
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

            $properties[$name] = $doc->apply($this->property($property, $promotedTypes[$name] ?? null, $class, $registry));
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
     * @return array<string, mixed>
     */
    private function property(ReflectionProperty $property, ?string $promotedType, ReflectionClass $declaring, SchemaRegistry $registry): array
    {
        $type = $property->getType();
        $declared = $type instanceof ReflectionNamedType ? $type->getName() : null;
        $nullable = $type?->allowsNull() ?? true;

        $expression = DocBlock::parse($property->getDocComment())->varType() ?? $promotedType;

        $schema = null;
        if ($expression !== null) {
            $schema = DocType::schema($expression, fn (string $c): array => $this->schema($c, $registry), $declaring);
        }

        if (! $this->informative($schema)) {
            $schema = $declared === null
                ? []
                : (TypeSchema::for($declared) ?? ['$ref' => $this->ref($declared, $registry)]);

            if ($nullable) {
                $schema = $this->nullable($schema);
            }
        }

        return $schema ?? [];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function nullable(array $schema): array
    {
        if ($schema === []) {
            return [];
        }

        if (isset($schema['$ref'])) {
            return ['anyOf' => [$schema, ['type' => 'null']]];
        }

        if (isset($schema['type']) && is_string($schema['type'])) {
            $schema['type'] = [$schema['type'], 'null'];
        }

        return $schema;
    }
}
