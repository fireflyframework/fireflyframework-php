<?php

declare(strict_types=1);

namespace Firefly\Context\Condition\Attributes;

use Attribute;
use Firefly\Context\Condition\BeanConditionAttribute;

/**
 * Gates a #[Configuration] class or a single #[Bean] method on a bean of $type already being
 * DEFINED in the BeanDefinitionRegistry (never a resolved instance — matches Spring).
 * INERT METADATA ONLY — the ConditionEvaluator supplies all evaluation logic.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class ConditionalOnBean implements BeanConditionAttribute
{
    public function __construct(
        public string $type,
    ) {}
}
