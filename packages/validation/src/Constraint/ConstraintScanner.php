<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Firefly\Validation\Rule\NullAware;
use Firefly\Validation\Valid;
use Illuminate\Contracts\Validation\ValidationRule;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;

/**
 * The primary reflection site in packages/validation/src, and — together with ConstraintManifestCompiler's
 * rule-argument recovery and ContainerElementType's docblock reading — one of only three, all COMPILE-time
 * only: nothing on the cached runtime path reflects, which is the invariant that actually matters. Reflects
 * a DTO's constructor-promoted properties (plus any plain typed properties) once, at COMPILE time, reads
 * each property's #[Constraint] attributes via IS_INSTANCEOF, merges toRules() in declaration order, and
 * cascades #[Valid] into dot-prefixed nested keys. Recursion is guarded by an ANCESTOR set threaded DOWN the
 * descent (the class-strings currently on the #[Valid] path); the current class is added to that set only
 * when descending INTO a nested #[Valid], so a self-referential #[Valid] still expands exactly one level.
 * Trace — for SelfReferential{ #[NotBlank] string $label; #[Valid] ?SelfReferential $parent; }, the walk
 * descends into `parent` with $ancestors=[SelfReferential], so the inner `parent` is skipped
 * (in_array(SelfReferential, [SelfReferential]) === true) and the keys are `label` + `parent.label` but NOT
 * `parent.parent.label`. For MoneyTransferRequest{ #[Valid] AddressPayload $beneficiary } it yields
 * `beneficiary.postcode` (etc.). Production loads the compiled ConstraintManifest instead (require+map);
 * this class runs only at cache time or, in tests, inline via ConstraintManifestCompiler.
 *
 * TWO TABLES FROM ONE WALK. scan() is the rule list Laravel runs, exactly as it always was. constraints()
 * is the same walk's OTHER output: per property path, the ConstraintDescriptors that contributed those
 * rules — name, sentence, and the rule keys each one owns — which is what lets a failed Laravel rule be
 * reported as the constraint that declared it (see FieldErrorMapper). They are keyed identically, cascade
 * identically, and are memoised together so a class is reflected once however many times either is asked.
 * The scanner's own `nullable` flag (applyNullContract()) belongs to no constraint and is described by none;
 * it never fails, so nothing is lost.
 *
 * Assembling a property's list is also where Jakarta's NULL contract is applied — see applyNullContract().
 *
 * @phpstan-type Scanned array{rules: array<string, list<string|ValidationRule>>, constraints: array<string, list<ConstraintDescriptor>>}
 */
final class ConstraintScanner
{
    /** @var array<string, Scanned> top-level walks, memoised so scan() and constraints() reflect a class once */
    private array $scanned = [];

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function scan(string $class): array
    {
        return $this->scanned($class)['rules'];
    }

    /**
     * @return array<string, list<ConstraintDescriptor>>
     */
    public function constraints(string $class): array
    {
        return $this->scanned($class)['constraints'];
    }

    /**
     * @return Scanned
     */
    private function scanned(string $class): array
    {
        return $this->scanned[$class] ??= $this->walk($class, []);
    }

    /**
     * @param  list<class-string>  $ancestors  class-strings currently on the #[Valid] descent path
     * @return Scanned
     */
    private function walk(string $class, array $ancestors): array
    {
        $scanned = ['rules' => [], 'constraints' => []];

        if (! class_exists($class)) {
            return $scanned;
        }

        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || $reflection->isInterface()) {
            return $scanned;
        }

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isPromoted()) {
                $this->collect($parameter, $class, $ancestors, $scanned);
            }
        }

        foreach ($reflection->getProperties() as $property) {
            if (! $property->isPromoted()) {
                $this->collect($property, $class, $ancestors, $scanned);
            }
        }

        return $scanned;
    }

    /**
     * @param  class-string  $class  the class currently being scanned (pushed onto $ancestors on descent)
     * @param  list<class-string>  $ancestors
     * @param  Scanned  $scanned
     */
    private function collect(ReflectionParameter|ReflectionProperty $member, string $class, array $ancestors, array &$scanned): void
    {
        $name = $member->getName();
        $type = $member->getType();

        $propertyRules = [];
        $descriptors = [];
        foreach ($member->getAttributes(Constraint::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $constraint = $attribute->newInstance();
            $contributed = $constraint->toRules();

            // An unbounded #[Size] constrains nothing and describes nothing.
            if ($contributed === []) {
                continue;
            }

            $propertyRules = [...$propertyRules, ...$contributed];
            $descriptors[] = ConstraintDescriptor::of($constraint, $contributed);
        }

        if ($propertyRules !== []) {
            $scanned['rules'][$name] = $this->applyNullContract($propertyRules, $type?->allowsNull() ?? true);
            $scanned['constraints'][$name] = $descriptors;
        }

        if ($member->getAttributes(Valid::class) === []) {
            return;
        }

        $nestedClass = $this->classTypeOf($type);
        if ($nestedClass !== null && ! in_array($nestedClass, $ancestors, true)) {
            $this->cascade($this->walk($nestedClass, [...$ancestors, $class]), $name.'.', $scanned);
        }
    }

    /**
     * Copies a nested walk's two tables under a key prefix — `beneficiary.` for a nested object.
     *
     * @param  Scanned  $nested
     * @param  Scanned  $scanned
     */
    private function cascade(array $nested, string $prefix, array &$scanned): void
    {
        foreach ($nested['rules'] as $key => $rules) {
            $scanned['rules'][$prefix.$key] = $rules;
        }

        foreach ($nested['constraints'] as $key => $descriptors) {
            $scanned['constraints'][$prefix.$key] = $descriptors;
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

    private function classTypeOf(?ReflectionType $type): ?string
    {
        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            $name = $type->getName();

            return class_exists($name) ? $name : null;
        }

        return null;
    }
}
