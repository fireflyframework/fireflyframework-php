<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

use BackedEnum;
use Closure;
use DateTimeInterface;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use stdClass;

/**
 * The PHP-type half of a property schema: everything derivable from a type NAME alone, with no constraints in
 * play. ConstraintSchemaMapper then layers the validation-derived keywords on top of whatever this returns.
 *
 * The split matters because the two sources disagree about how much they know. A type says `?int`, which
 * fixes the JSON type and the nullability and nothing else; a constraint says `gte:1`, which fixes a bound
 * and only IMPLIES a numeric type. Deriving the type from constraints alone (the tempting shortcut, since
 * RouteDescriptor's Binding carries no per-property types) produces `type: string` for every property that
 * happens to carry no type-shaped rule — silently mistyping every un-annotated `int $quantity` in the
 * generated client. So the type comes from the declared type when there is one, and the constraint mapper
 * only fills a type in when this class returned none.
 *
 * Three type shapes get first-class treatment because they are the ones a JSON client actually has to decode
 * differently, and all three are invisible to the constraint list:
 *
 *  - a BACKED ENUM becomes `enum: [...]` over its backing values plus the backing type. This is the single
 *    highest-value thing reflection buys here: `Currency $currency` documents the exact accepted set, where
 *    the constraint list (usually empty on an enum-typed property, because the type already constrains it)
 *    would have documented an unbounded string.
 *  - a DateTimeInterface becomes `string`/`format: date-time`, matching what the JSON body actually carries.
 *  - anything else that is a class is NOT resolved here: null comes back, and the caller mints a
 *    `$ref` through SchemaRegistry. Inlining a nested DTO here instead would defeat component reuse and
 *    would not terminate on a self-referential DTO.
 */
final class TypeSchema
{
    /**
     * The JSON Schema fragment for a declared PHP type, or null when the type is a class the caller must
     * turn into a `$ref` (see class docblock). An unknown/absent type yields an EMPTY fragment — the
     * "any JSON value" schema — never a guessed `type: string`.
     *
     * @return array<string, mixed>|null
     */
    public static function for(?string $type): ?array
    {
        return match ($type) {
            'string' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'array', 'iterable' => ['type' => 'array'],
            // `object` promises an object and nothing about its members — which is exactly `type: object`,
            // and strictly more than the any-value schema it used to share with `mixed`.
            'object' => ['type' => 'object'],
            null, 'mixed', 'null' => [],
            default => self::forClass($type),
        };
    }

    /**
     * The schema for a DECLARED PHP type, whatever its reflected shape — a named type, a union, an
     * intersection — with its nullability.
     *
     * Every declared-type reader in this package used to ask only `instanceof ReflectionNamedType`, so a
     * union came back as no type at all: `Parcel|Label` was documented as any value, `int|string` likewise,
     * and a union-typed request member fell out of `required`. $named decides what ONE type name means in the
     * caller's position (a response `$ref`, a request `$ref`, the bare-array fallback of a success body); this
     * decides how the names combine, through the same SchemaUnion a `@return` expression uses, so
     * `: ?Parcel` and `@return ?Parcel` cannot become two different documents. `null` is an arm like any
     * other — `Parcel|Label|null` is one flat anyOf — and one arm meaning "any value" makes the whole union
     * any value, for the reason DocType gives: a union that silently dropped an arm would be a lie.
     *
     * @param  Closure(string): array<string, mixed>  $named
     * @return array<string, mixed>
     */
    public static function reflected(?ReflectionType $type, Closure $named): array
    {
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();

            if ($name === 'null') {
                return ['type' => 'null'];
            }

            $schema = $named($name);

            return $type->allowsNull() && $name !== 'mixed' ? SchemaUnion::nullable($schema) : $schema;
        }

        if ($type instanceof ReflectionUnionType) {
            $arms = [];
            foreach ($type->getTypes() as $arm) {
                $schema = self::reflected($arm, $named);
                if ($schema === []) {
                    return [];
                }
                $arms[] = $schema;
            }

            return SchemaUnion::of($arms);
        }

        if ($type instanceof ReflectionIntersectionType) {
            return ['allOf' => array_map(static fn (ReflectionType $part): array => self::reflected($part, $named), $type->getTypes())];
        }

        return [];
    }

    /**
     * Whether this type name denotes a DTO that should become its own reusable component schema — i.e. a
     * real class that self() does not already resolve to an inline fragment.
     */
    public static function isDto(?string $type): bool
    {
        return $type !== null && class_exists($type) && self::forClass($type) === null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function forClass(string $type): ?array
    {
        if (! class_exists($type) && ! interface_exists($type)) {
            // A type this process cannot autoload (a `never`/`static` return, a class from a package the
            // app does not install). Documenting it as an opaque JSON value is honest; guessing is not.
            return [];
        }

        // An open bag of members: reflecting it finds none, and a component built from that would document
        // an object with NO members — the opposite of what a stdClass is. Only the exact class; a subclass
        // that declares properties is a DTO like any other.
        if (strcasecmp(ltrim($type, '\\'), stdClass::class) === 0) {
            return ['type' => 'object'];
        }

        if (is_a($type, DateTimeInterface::class, true)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        if (is_subclass_of($type, BackedEnum::class)) {
            return self::backedEnum($type);
        }

        if (! class_exists($type)) {
            // An interface or a non-backed enum: nothing to reflect a property list out of, so it cannot
            // become a component schema either.
            return [];
        }

        return null;
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @return array<string, mixed>
     */
    private static function backedEnum(string $enum): array
    {
        $values = array_map(static fn (BackedEnum $case): int|string => $case->value, $enum::cases());
        $integers = $values !== [] && array_filter($values, 'is_int') === $values;

        return ['type' => $integers ? 'integer' : 'string', 'enum' => $values];
    }
}
