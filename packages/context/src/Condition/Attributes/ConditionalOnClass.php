<?php

declare(strict_types=1);

namespace Firefly\Context\Condition\Attributes;

use Attribute;
use Firefly\Context\Condition\ConditionAttribute;

/**
 * Gates a #[Configuration] class or a single #[Bean] method on a class being loadable
 * (`class_exists()`). $class is a plain string, NOT `class-string` — the whole point of this
 * condition is that the class may legitimately not exist (an optional vendor integration).
 * INERT METADATA ONLY — the ConditionEvaluator supplies all evaluation logic.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class ConditionalOnClass implements ConditionAttribute
{
    public function __construct(
        public string $class,
    ) {}
}
