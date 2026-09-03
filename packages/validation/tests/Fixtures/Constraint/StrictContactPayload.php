<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Constraint;

use Firefly\Validation\Constraint\Email;

/**
 * The counterpart to OptionalContactPayload: the SAME constraint on two properties that differ only in
 * whether their declared type admits null.
 *
 * It pins the boundary of Jakarta's null contract in PHP. `?string $optional` can hold null, so a
 * present-but-null value skips #[Email] exactly as Jakarta requires. `string $required` cannot: the type
 * declaration has already said null is not a value this field takes, and letting null through validation
 * would only defer the failure to the web layer's `new $dto(...$named)` hydration — a TypeError and an HTTP
 * 500 in place of the 422 the payload deserves.
 */
final class StrictContactPayload
{
    public function __construct(
        #[Email]
        public readonly string $required,
        #[Email]
        public readonly ?string $optional = null,
    ) {}
}
