<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

/**
 * How two or more schemas become one: the union and nullability spellings, shared by every path that states a
 * type — a PHPDoc expression (DocType) and a DECLARED PHP type (TypeSchema::reflected()).
 *
 * They used to live inside DocType alone, which is how the declared-type paths came to have none: a
 * `Parcel|Label` return, a `?Parcel` return and an `int|string` member were read only when they happened to
 * be a ReflectionNamedType, and otherwise became `{}` — "any value" — or lost their null. One home means the
 * two spellings of a type (`@return ?Parcel` and `: ?Parcel`) cannot drift into two different documents.
 */
final class SchemaUnion
{
    /**
     * Collapses a union into the narrowest legal spelling.
     *
     * Three cases, in order of how much they help a reader. All arms scalar (`int|string`) becomes a single
     * schema with a type ARRAY, which is 2020-12's own spelling and what a generator turns into a union
     * type. All arms literals (`'draft'|'sent'`) becomes an `enum`, which is the whole reason to write such
     * a union. Anything else is an `anyOf`, which is always correct and never as readable.
     *
     * @param  list<array<string, mixed>>  $parts
     * @return array<string, mixed>
     */
    public static function of(array $parts): array
    {
        $unique = [];
        foreach ($parts as $part) {
            $key = json_encode($part);
            $unique[is_string($key) ? $key : count($unique)] = $part;
        }
        $parts = array_values($unique);

        if (count($parts) === 1) {
            return $parts[0];
        }

        $constants = [];
        foreach ($parts as $part) {
            if (array_keys($part) === ['const']) {
                $constants[] = $part['const'];
            }
        }
        if (count($constants) === count($parts)) {
            $types = array_values(array_unique(array_map(
                static fn (mixed $v): string => match (true) {
                    is_int($v) => 'integer',
                    is_bool($v) => 'boolean',
                    is_float($v) => 'number',
                    default => 'string',
                },
                $constants,
            )));

            return ['type' => count($types) === 1 ? $types[0] : $types, 'enum' => $constants];
        }

        $types = [];
        foreach ($parts as $part) {
            if (array_keys($part) !== ['type'] || ! is_string($part['type'])) {
                $types = null;
                break;
            }
            $types[] = $part['type'];
        }
        if ($types !== null) {
            return ['type' => array_values(array_unique($types))];
        }

        return ['anyOf' => $parts];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function nullable(array $schema): array
    {
        if ($schema === []) {
            return [];
        }

        if (array_keys($schema) === ['type'] && is_string($schema['type'])) {
            return ['type' => [$schema['type'], 'null']];
        }

        if (isset($schema['type']) && is_string($schema['type']) && ! isset($schema['$ref'])) {
            $schema['type'] = [$schema['type'], 'null'];

            return $schema;
        }

        // A `$ref` cannot be widened in place: sibling validation keywords are applied WITH the reference in
        // 2020-12, so a `type: null` beside it would have to hold as well as the reference and never could.
        return ['anyOf' => [$schema, ['type' => 'null']]];
    }
}
