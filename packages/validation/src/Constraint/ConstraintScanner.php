<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Firefly\Validation\Rule\NullAware;
use Firefly\Validation\Valid;
use Illuminate\Contracts\Validation\ValidationRule;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The primary reflection site in packages/validation/src, and — together with ConstraintManifestCompiler's
 * rule-argument recovery — one of only two, both COMPILE-time only: nothing on the cached runtime path
 * reflects, which is the invariant that actually matters. Reflects a DTO's
 * constructor-promoted properties (plus any plain typed properties) once, at COMPILE time, reads each
 * property's #[Constraint] attributes via IS_INSTANCEOF, merges toRules() in declaration order, and
 * cascades one #[Valid] level into dot-prefixed nested keys. Recursion is guarded by an ANCESTOR set
 * threaded DOWN the descent (the class-strings currently on the #[Valid] path); the current class is
 * added to that set only when descending INTO a nested #[Valid], so a self-referential #[Valid] still
 * expands exactly one level. Trace — for SelfReferential{ #[NotBlank] string $label; #[Valid]
 * ?SelfReferential $parent; }, scan() descends into `parent` with $ancestors=[SelfReferential], so the
 * inner `parent` is skipped (in_array(SelfReferential, [SelfReferential]) === true) and the keys are
 * `label` + `parent.label` but NOT `parent.parent.label`. For MoneyTransferRequest{ #[Valid]
 * AddressPayload $beneficiary } it yields `beneficiary.postcode` (etc.). Production loads the compiled
 * ConstraintManifest instead (require+map); this class runs only at cache time or, in tests, inline via
 * ConstraintManifestCompiler.
 *
 * Assembling a property's list is also where Jakarta's NULL contract is applied — see applyNullContract().
 */
final class ConstraintScanner
{
    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function scan(string $class): array
    {
        return $this->scanClass($class, []);
    }

    /**
     * @param  list<class-string>  $ancestors  class-strings currently on the #[Valid] descent path
     * @return array<string, list<string|ValidationRule>>
     */
    private function scanClass(string $class, array $ancestors): array
    {
        if (! class_exists($class)) {
            return [];
        }

        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || $reflection->isInterface()) {
            return [];
        }

        $rules = [];

        $constructor = $reflection->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if (! $parameter->isPromoted()) {
                continue;
            }
            $this->collect(
                $parameter->getName(),
                $parameter->getAttributes(Constraint::class, ReflectionAttribute::IS_INSTANCEOF),
                $this->hasValid($parameter->getAttributes(Valid::class)),
                $this->classTypeOf($parameter->getType()),
                $parameter->getType()?->allowsNull() ?? true,
                $class,
                $ancestors,
                $rules,
            );
        }

        foreach ($reflection->getProperties() as $property) {
            if ($property->isPromoted()) {
                continue;
            }
            $this->collect(
                $property->getName(),
                $property->getAttributes(Constraint::class, ReflectionAttribute::IS_INSTANCEOF),
                $this->hasValid($property->getAttributes(Valid::class)),
                $this->classTypeOf($property->getType()),
                $property->getType()?->allowsNull() ?? true,
                $class,
                $ancestors,
                $rules,
            );
        }

        return $rules;
    }

    /**
     * @param  list<ReflectionAttribute<Constraint>>  $constraintAttributes
     * @param  bool  $acceptsNull  whether the property's DECLARED type admits null (see applyNullContract())
     * @param  class-string  $class  the class currently being scanned (pushed onto $ancestors on descent)
     * @param  list<class-string>  $ancestors
     * @param  array<string, list<string|ValidationRule>>  $rules
     */
    private function collect(
        string $name,
        array $constraintAttributes,
        bool $valid,
        ?string $nestedClass,
        bool $acceptsNull,
        string $class,
        array $ancestors,
        array &$rules,
    ): void {
        $propertyRules = [];
        foreach ($constraintAttributes as $attribute) {
            foreach ($attribute->newInstance()->toRules() as $rule) {
                $propertyRules[] = $rule;
            }
        }
        if ($propertyRules !== []) {
            $rules[$name] = $this->applyNullContract($propertyRules, $acceptsNull);
        }

        if ($valid && $nestedClass !== null && ! in_array($nestedClass, $ancestors, true)) {
            foreach ($this->scanClass($nestedClass, [...$ancestors, $class]) as $nestedKey => $nestedRules) {
                $rules["{$name}.{$nestedKey}"] = $nestedRules;
            }
        }
    }

    /**
     * Applies Jakarta Bean Validation's null contract to a property's assembled rule list.
     *
     * Jakarta is explicit: `null` is a VALID value for every constraint except @NotNull (and the constraints
     * that subsume it, @NotEmpty/@NotBlank). `@Email String backupEmail` accepts null; it is @NotNull's job,
     * and only @NotNull's job, to say that a value is required. LaraFly did the opposite. Illuminate
     * validates a rule when the attribute is PRESENT (Validator::presentOrRuleIsImplicit -> validatePresent,
     * which is Arr::has and therefore true for a key holding null), so a payload of ['backupEmail' => null]
     * ran the `email` rule against null and failed it. That was wrong on its own terms and INCONSISTENT with
     * the neighbouring case: omit the key entirely and the same rule is skipped, so {"backupEmail": null} was
     * rejected while {} was accepted — two spellings of "no value" with opposite outcomes, which is how the
     * defect surfaced: clients were punished for serialising their optional fields explicitly.
     *
     * The fix is one flag, decided at compile time and baked into the manifest: prepend Laravel's `nullable`,
     * which makes Validator::isNotNullIfMarkedAsNullable skip every NON-implicit rule when the value is null.
     * The relationship it establishes is the simple one Jakarta describes: ABSENT and PRESENT-BUT-NULL now
     * behave identically for every constraint, and a constraint that means to reject either must say so.
     *
     * The flag is withheld from a property whose DECLARED TYPE does not admit null, and that qualification is
     * load-bearing rather than tidiness. Jakarta's contract is stated for Java references, every one of which
     * can hold null; PHP's equivalent statement is the type declaration, and `public readonly string $email`
     * has already said that null is not a value this field can take. Marking it `nullable` would let a
     * {"email": null} body pass validation and then blow up one line later in the web layer's
     * `new $dto(...$named)` with a TypeError — a 500 where the payload used to get a 422. So the null
     * contract applies exactly where the DTO admits null (`?string $backupEmail`, a union with null, `mixed`,
     * or an untyped property), and a non-nullable property keeps rejecting an explicit null through whatever
     * rule it already carries. Absent-vs-present-null therefore agree wherever null is a legal value for the
     * property, which is the whole of the case the contract is about.
     *
     * Two kinds of rule deliberately keep firing through the flag. Implicit rule STRINGS — `required` and its
     * family, emitted by #[NotEmpty]/#[NotBlank] — are exempt from `nullable` by Illuminate's own design, so
     * they still reject null; there the flag merely suppresses the type/format rules queued behind them,
     * collapsing three meaningless complaints about a null ("must be a string", "format is invalid", ...) into
     * the one that matters. Rule OBJECTS are never implicit, so a rule whose entire purpose is null would be
     * silently disabled instead: those implement Firefly\Validation\Rule\NullAware (Firefly's NotNull does),
     * and their presence withholds the flag from the property altogether.
     *
     * @param  list<string|ValidationRule>  $propertyRules
     * @param  bool  $acceptsNull  whether the property's declared type admits null
     * @return list<string|ValidationRule>
     */
    private function applyNullContract(array $propertyRules, bool $acceptsNull): array
    {
        if (! $acceptsNull) {
            return $propertyRules;
        }

        foreach ($propertyRules as $rule) {
            if ($rule instanceof NullAware) {
                return $propertyRules;
            }
        }

        return ['nullable', ...$propertyRules];
    }

    /**
     * @param  list<ReflectionAttribute<Valid>>  $attributes
     */
    private function hasValid(array $attributes): bool
    {
        return $attributes !== [];
    }

    private function classTypeOf(?\ReflectionType $type): ?string
    {
        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            $name = $type->getName();

            return class_exists($name) ? $name : null;
        }

        return null;
    }
}
