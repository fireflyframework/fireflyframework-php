<?php

declare(strict_types=1);

namespace Firefly\Observability\Method;

use Attribute;

/**
 * Micrometer's `@Counted`: one counter increment per invocation, tagged `result` = `success`|`failure` and
 * `exception` = the thrown class's short name (`none` on success), plus `class`/`method` and `extraTags`.
 * `value` names the meter (empty → `firefly.observability.method.counted.name`, default `method.counted`).
 *
 * `recordFailuresOnly` is Micrometer's own flag: count only the invocations that threw. It exists because a
 * success counter is usually already implied by the timer's `_count`, and a team that only wants the error
 * rate should not have to pay for a second full-cardinality meter to get it.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class Counted
{
    /** @param array<string, string> $extraTags */
    public function __construct(
        public string $value = '',
        public array $extraTags = [],
        public bool $recordFailuresOnly = false,
    ) {}
}
