<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

use BackedEnum;
use DateTimeInterface;

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
            null, 'mixed', 'object', 'null' => [],
            default => self::forClass($type),
        };
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
