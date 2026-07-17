<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Firefly\Validation\Valid;
use Illuminate\Contracts\Validation\ValidationRule;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The ONE reflection site in packages/validation/src (grep invariant). Reflects a DTO's
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
                $class,
                $ancestors,
                $rules,
            );
        }

        return $rules;
    }

    /**
     * @param  list<ReflectionAttribute<Constraint>>  $constraintAttributes
     * @param  class-string  $class  the class currently being scanned (pushed onto $ancestors on descent)
     * @param  list<class-string>  $ancestors
     * @param  array<string, list<string|ValidationRule>>  $rules
     */
    private function collect(
        string $name,
        array $constraintAttributes,
        bool $valid,
        ?string $nestedClass,
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
            $rules[$name] = $propertyRules;
        }

        if ($valid && $nestedClass !== null && ! in_array($nestedClass, $ancestors, true)) {
            foreach ($this->scanClass($nestedClass, [...$ancestors, $class]) as $nestedKey => $nestedRules) {
                $rules["{$name}.{$nestedKey}"] = $nestedRules;
            }
        }
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
