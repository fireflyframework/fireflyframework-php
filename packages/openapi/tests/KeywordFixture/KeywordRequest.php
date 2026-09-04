<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\KeywordFixture;

use Firefly\Validation\Constraint\NotBlank;

/**
 * Members named after JSON Schema keywords, which are ordinary PHP property names and so ordinary keys of a
 * `properties` map. Nothing about this payload is exotic — it exists because the generator now treats
 * `default`/`const`/`example`/`enum`/`examples` as INSTANCE keywords inside a Schema Object, and that rule
 * must never fire on a `properties` map, which is keyed by property name rather than by keyword.
 *
 * `mixed $type` is the member that made the distinction fail: an unconstrained member has the schema `[]`,
 * and an empty array satisfied every part of the Schema-Object shape test except emptiness. The map was then
 * read as a Schema Object declaring no type, and the sibling `enum` member serialised as `"enum": []` — a
 * JSON array where the meta-schema requires a Schema Object.
 */
final class KeywordRequest
{
    public function __construct(
        #[NotBlank] public readonly string $reference,
        public readonly mixed $type = null,
        public readonly mixed $enum = null,
        public readonly mixed $default = null,
        public readonly mixed $example = null,
    ) {}
}
