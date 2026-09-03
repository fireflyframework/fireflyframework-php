<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Attributes;

use Attribute;

/**
 * Enriches one DTO member's schema — springdoc's @Schema on a field.
 *
 * The generator already knows a great deal about a member without being told: the declared PHP type fixes the
 * JSON type, a backed enum fixes the accepted SET, and the compiled ConstraintManifest fixes the bounds,
 * patterns and formats that #[Email]/#[Size]/#[Pattern] actually enforce. What none of that can supply is
 * PROSE, and what a docblock cannot supply is a `format` or an example. This attribute is the second half.
 *
 * `description` beats the member's docblock, which beats the promoted-constructor `@param` line. All three
 * are absent by default and nothing is invented in their place — an undescribed property gets no
 * `description` key rather than a restatement of its own name.
 *
 * `format` OVERWRITES a constraint-derived one. That is deliberate and it is the point: #[Email] emits
 * `format: email` and an author who writes `format: 'idn-email'` has said something more precise than the
 * validator could. `format` in JSON Schema 2020-12 is an open, annotation-only vocabulary, so an unregistered
 * value is legal and merely un-asserted rather than an error.
 *
 * `example` is emitted as `examples: [value]`, NOT as `example: value`. OpenAPI 3.1 aligned the Schema Object
 * with JSON Schema 2020-12, whose keyword is the plural ARRAY form, and explicitly deprecated the singular
 * `example` it inherited from 3.0. Writing the deprecated spelling would still render in most viewers today
 * and would be the first thing a 3.2 validator complains about.
 *
 * A null `example` is read as "no example", the same convention ApiParameter uses: a member whose only
 * published example is null teaches a reader nothing, and the distinction between "absent" and "null" is not
 * worth a sentinel constant in an attribute people write by hand.
 *
 * `deprecated` marks the member without removing it, which is the only way to retire a request field without
 * breaking every client at once.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class ApiProperty
{
    public function __construct(
        public readonly string $description = '',
        public readonly mixed $example = null,
        public readonly ?string $format = null,
        public readonly bool $deprecated = false,
    ) {}
}
