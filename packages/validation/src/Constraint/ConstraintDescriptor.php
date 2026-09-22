<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\ValidationRuleParser;

/**
 * One #[Constraint] attribute as it was declared on one property path, compiled: the attribute's short name
 * (`NotBlank`, `Size`, `Email` — what Spring's FieldError publishes as `code` and this framework publishes as
 * `constraint`), the sentence it publishes when it fails (already interpolated, so the request path never
 * sees `{min}`), and the Laravel rules it contributed, spelled the way Illuminate\Validation\Validator::failed()
 * spells them so a failed rule can be handed back to the constraint that owns it without a second parse.
 *
 * WHY THE RULE KEYS CARRY THEIR PARAMETERS. Two constraints on one property may emit the SAME Laravel rule
 * name — #[NotBlank] and #[Pattern] both emit `regex`, #[Positive] and #[Max] both emit `numeric` — and
 * Laravel's message system is keyed by rule name alone, which is why the humanised sentences could never
 * have said which constraint failed. failed() records the failing rule's parameters beside its name, and
 * `regex:/\S/` and `regex:/^[A-Z0-9]…$/D` have different parameters, so the owner is found by exact
 * (name, parameters) match first and by name alone only when no constraint carries those parameters. A
 * rule OBJECT is keyed by its class and carries no parameters, exactly as failed() records it.
 *
 * `own` records whether the sentence is the developer's `message:` element rather than the constraint's
 * default: the developer's words win in every message style, Laravel's defaults only replace the framework's.
 *
 * @phpstan-type RuleKey array{0: string, 1: list<string>}
 * @phpstan-type DescriptorRow array{name: string, message: string|null, rules: list<RuleKey>, own?: bool}
 */
final readonly class ConstraintDescriptor
{
    /**
     * @param  string  $name  the attribute's short class name
     * @param  string|null  $message  the sentence to publish, or null when the constraint has none of its own
     * @param  list<RuleKey>  $rules  the rule keys this constraint contributed, in Validator::failed() spelling
     * @param  bool  $own  whether $message is the developer's `message:` element
     */
    public function __construct(
        public string $name,
        public ?string $message,
        public array $rules,
        public bool $own = false,
    ) {}

    /**
     * @param  list<string|ValidationRule>  $contributed  what the constraint's toRules() returned
     */
    public static function of(Constraint $constraint, array $contributed): self
    {
        $keys = [];
        foreach ($contributed as $rule) {
            if ($rule instanceof ValidationRule) {
                $keys[] = [$rule::class, []];

                continue;
            }

            /** @var array{0: string, 1: array<int, string|null>} $parsed the studly name and str_getcsv()'s parameters */
            $parsed = ValidationRuleParser::parse($rule);
            $keys[] = [$parsed[0], self::normalise($parsed[1])];
        }

        $class = $constraint::class;
        $separator = strrpos($class, '\\');

        return new self(
            $separator === false ? $class : substr($class, $separator + 1),
            $constraint instanceof HasMessage ? $constraint->message() : null,
            $keys,
            $constraint instanceof HasMessage && $constraint->hasCustomMessage(),
        );
    }

    /**
     * Exact ownership: this constraint contributed $rule WITH these parameters.
     *
     * @param  array<int, mixed>  $parameters  as Validator::failed() recorded them
     */
    public function owns(string $rule, array $parameters): bool
    {
        $wanted = self::normalise($parameters);
        foreach ($this->rules as [$name, $ruleParameters]) {
            if ($name === $rule && $ruleParameters === $wanted) {
                return true;
            }
        }

        return false;
    }

    /** Ownership by name alone — the fallback when no constraint carries the failing parameters. */
    public function names(string $rule): bool
    {
        foreach ($this->rules as [$name]) {
            if ($name === $rule) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return DescriptorRow
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'message' => $this->message, 'rules' => $this->rules, 'own' => $this->own];
    }

    /**
     * @param  DescriptorRow  $row
     */
    public static function fromArray(array $row): self
    {
        return new self($row['name'], $row['message'], $row['rules'], $row['own'] ?? false);
    }

    /**
     * Both sides of owns() pass through here. failed() hands parameters back as whatever the parser produced
     * (strings for a rule string, an empty list for an object), and of() compiles the same parser's output;
     * normalising both to a list of strings is what makes the comparison exact rather than approximate.
     *
     * @param  array<int, mixed>  $parameters
     * @return list<string>
     */
    private static function normalise(array $parameters): array
    {
        $normalised = [];
        /** @var mixed $parameter */
        foreach ($parameters as $parameter) {
            $normalised[] = is_scalar($parameter) ? (string) $parameter : '';
        }

        return $normalised;
    }
}
