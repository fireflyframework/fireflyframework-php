<?php

declare(strict_types=1);

namespace Firefly\Context\Condition\Attributes;

use Attribute;
use Firefly\Context\Condition\ConditionAttribute;

/**
 * Gates a #[Configuration] class or a single #[Bean] method on a config property being present
 * (and, optionally, matching a specific value). INERT METADATA ONLY — the ConditionEvaluator
 * supplies all evaluation logic; this attribute carries no matches()/evaluate() method.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class ConditionalOnProperty implements ConditionAttribute
{
    public function __construct(
        public string $name,
        public ?string $havingValue = null,
        public bool $matchIfMissing = false,
    ) {}
}
