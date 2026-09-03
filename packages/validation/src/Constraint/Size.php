<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\Size as SizeRule;

/**
 * Jakarta's @Size: a LENGTH/SIZE constraint, always, whatever else is declared on the property.
 *
 * This used to emit Laravel's `min:`/`max:`/`between:` strings, whose meaning Validator::getSize() decides at
 * runtime from the property's OTHER rules — value semantics when a sibling contributes `numeric`, size
 * semantics otherwise. Pairing #[Size] with #[Min]/#[Max]/#[Digits]/#[Positive] (all of which emit `numeric`)
 * therefore turned a length check into a magnitude check without a word of warning. It now wraps the
 * first-party Firefly\Validation\Rule\Size, which measures the value and never reads the sibling list; see
 * that rule for the full account of the defect and of what "measurable" means per type.
 *
 * An unbounded #[Size] (neither min nor max) still contributes nothing: it constrains nothing, and emitting a
 * rule object that can never fail would only add noise to the compiled manifest.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Size implements Constraint
{
    public function __construct(
        public readonly ?int $min = null,
        public readonly ?int $max = null,
    ) {}

    /** @return list<SizeRule> */
    public function toRules(): array
    {
        if ($this->min === null && $this->max === null) {
            return [];
        }

        return [new SizeRule($this->min, $this->max)];
    }
}
