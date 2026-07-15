<?php

declare(strict_types=1);

namespace Firefly\Context\Condition\Attributes;

use Attribute;
use Firefly\Context\Condition\ConditionAttribute;

/**
 * Gates a #[Configuration] class or a single #[Bean] method on a class being ABSENT
 * (the inverse of #[ConditionalOnClass]). $class is a plain string for the same reason:
 * the class may legitimately not exist. INERT METADATA ONLY.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class ConditionalOnMissingClass implements ConditionAttribute
{
    public function __construct(
        public string $class,
    ) {}
}
