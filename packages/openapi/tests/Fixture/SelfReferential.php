<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\Fixture;

use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Valid;

/**
 * The cycle. ConstraintScanner flattens exactly one #[Valid] level here (label + parent.label, never
 * parent.parent.label), but a SCHEMA graph must express the full cycle, which it can only do through a
 * `$ref` that points back at the component being built. If SchemaRegistry did not reserve the name before
 * invoking the builder, generating this class would recurse until the stack ran out.
 */
final class SelfReferential
{
    public function __construct(
        #[NotBlank] public readonly string $label,
        #[Valid] public readonly ?SelfReferential $parent = null,
    ) {}
}
