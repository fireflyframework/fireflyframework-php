<?php

declare(strict_types=1);

namespace Firefly\Context\Condition;

/**
 * The result of evaluating a single ConditionAttribute.
 *
 * Every outcome carries a human-readable reason naming the ACTUAL VALUE observed (e.g.
 * "@ConditionalOnProperty (firefly.cache.enabled=false) did not match required value 'true'"),
 * never a bare "did not match" — this is what M12's /actuator/conditions endpoint renders.
 */
final readonly class ConditionOutcome
{
    private function __construct(
        public bool $matched,
        public string $reason,
    ) {}

    public static function match(string $reason): self
    {
        return new self(true, $reason);
    }

    public static function noMatch(string $reason): self
    {
        return new self(false, $reason);
    }
}
