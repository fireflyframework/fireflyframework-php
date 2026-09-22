<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The escape hatch: any Laravel rule string or ValidationRule object, attached directly.
 *
 * It has no sentence of its own — there is nothing to say about "whatever rules you passed" — so a failure
 * keeps Laravel's sentence for the rule that failed unless a `message:` is given. The element rides in the
 * variadic: PHP collects a named argument that matches no declared parameter into `...$rules` under its
 * name, so `#[Rules('min:3', message: 'must be at least 3 characters')]` arrives as
 * `['min:3', 'message' => '…']` and the two are told apart by key. A declared `$message` parameter could
 * only sit BEFORE the variadic, where it would swallow the first rule of every existing `#[Rules('a', 'b')]`.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Rules implements Constraint, HasMessage
{
    use MessageElement;

    /** @var list<string|ValidationRule> */
    public readonly array $rules;

    public readonly ?string $message;

    public function __construct(string|ValidationRule ...$rules)
    {
        $message = $rules['message'] ?? null;
        unset($rules['message']);

        $this->message = is_string($message) ? $message : null;
        $this->rules = array_values($rules);
    }

    public function toRules(): array
    {
        return $this->rules;
    }

    public function message(): ?string
    {
        return $this->message;
    }
}
