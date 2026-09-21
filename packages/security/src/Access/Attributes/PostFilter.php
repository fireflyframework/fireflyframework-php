<?php

declare(strict_types=1);

namespace Firefly\Security\Access\Attributes;

use Attribute;

/**
 * Narrows an ITERABLE RESULT after the call: elements for which the expression — `#filterObject` bound to the
 * element — is false are removed. An array keeps its keys (a list is re-indexed), an Illuminate Enumerable is
 * filtered in kind, any other Traversable comes back as an array; a non-iterable result is a refusal, because
 * a filter that cannot filter must fail closed. Enforced on a proxied bean and at the controller dispatcher.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class PostFilter
{
    public function __construct(public string $expression) {}
}
