<?php

declare(strict_types=1);

namespace Firefly\Security\Access\Attributes;

use Attribute;

/**
 * Narrows an ITERABLE ARGUMENT before the call: every element for which the expression — with `#filterObject`
 * bound to the element — is false is dropped, and the method receives the rest. `filterTarget` names the
 * parameter; it may be omitted when exactly one parameter is declared `array` or `iterable`, and the scan
 * refuses the attribute when the choice is ambiguous. Enforced on a proxied bean only: a controller receives
 * a request DTO, and the dispatcher's guard evaluates but cannot rewrite what the method receives.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class PreFilter
{
    public function __construct(
        public string $expression,
        public ?string $filterTarget = null,
    ) {}
}
