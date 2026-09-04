<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Constraint;

use Firefly\Validation\Constraint\Min;
use Firefly\Validation\Constraint\Size;

/**
 * The regression fixture for the #[Size]/`numeric` collision. `$code` deliberately pairs a LENGTH constraint
 * with a VALUE constraint on the same property — the exact combination that used to make Laravel's polymorphic
 * `between:`/`min:`/`max:` rules switch from measuring the string to comparing the number. `$label` carries a
 * lone #[Size] so the suite also proves the standalone case never regressed.
 */
final class SizedCodePayload
{
    public function __construct(
        #[Size(min: 3, max: 8)]
        #[Min(5)]
        public readonly int|string $code,
        #[Size(max: 4)]
        public readonly string $label,
    ) {}
}
