<?php

declare(strict_types=1);

namespace Firefly\Context\Condition\Attributes;

use Attribute;
use Firefly\Context\Condition\ConditionAttribute;

/**
 * Gates a #[Configuration] class or a single #[Bean] method on one of the active Profiles
 * matching. INERT METADATA ONLY — the ConditionEvaluator supplies all evaluation logic.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class ConditionalOnProfile implements ConditionAttribute
{
    /** @var list<string> */
    public array $profiles;

    public function __construct(string ...$profiles)
    {
        $this->profiles = array_values($profiles);
    }
}
