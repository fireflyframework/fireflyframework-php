<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Constraint;

use Firefly\Validation\Constraint\Rules;
use Firefly\Validation\Tests\Fixtures\Rule\NormalisingRule;
use Firefly\Validation\Tests\Fixtures\Rule\StartsWith;

/**
 * The #[Rules] escape hatch carrying stateful rule objects — the shape that used to compile to a bare
 * ['@rule' => Class] envelope and rehydrate with its constructor state thrown away.
 */
final class CustomRulePayload
{
    public function __construct(
        #[Rules(new StartsWith('ACME-'))]
        public readonly string $sku,
        #[Rules(new NormalisingRule('urgent'))]
        public readonly string $tag,
    ) {}
}
