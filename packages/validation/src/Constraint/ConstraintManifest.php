<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The compiled, reflection-free rule source the BeanValidator reads at runtime. Stored form is a pure PHP
 * array literal: each rule entry is a plain Laravel rule string OR the envelope
 * ['@rule' => Iban::class] / ['@rule' => DecimalScale::class, 'args' => [2]], because ValidationRule
 * objects do not var_export cleanly. fromArray()/load() rehydrate envelopes back to rule objects.
 *
 * 'args' is POSITIONAL and complete: the compiler emits every constructor parameter in declaration order, so
 * rehydrate() can splat it without knowing anything about the rule. The key is absent, rather than an empty
 * list, for a rule with no constructor state. Producing that list is ConstraintManifestCompiler's job and the
 * subtle part of this round-trip — read its class docblock for what is recoverable, what is refused at
 * compile time, and the production-only failure that came from getting it wrong.
 *
 * THE SECOND TABLE. Beside the class rows the file carries ONE reserved top-level key, `@constraints` (the
 * `@` prefix marks a reserved key exactly as `@rule` marks an envelope; a class name cannot start with it):
 * per class, per property path, the ConstraintDescriptor rows that contributed the rules — name, sentence,
 * owned rule keys — which FieldErrorMapper reads to report a failed Laravel rule as the constraint that
 * declared it. Kept beside the rules rather than in a second file so every caller that already builds a
 * manifest with fromArray(compiler->toArray(...)) carries it without change, and a manifest written before
 * the key existed still loads — with no descriptors, and therefore Laravel's sentences.
 *
 * @phpstan-import-type DescriptorRow from ConstraintDescriptor
 *
 * @phpstan-type RuleEnvelope array{'@rule': class-string<ValidationRule>, args?: list<mixed>}
 * @phpstan-type ClassRules array<string, list<string|RuleEnvelope>>
 * @phpstan-type DescriptorTable array<string, array<string, list<DescriptorRow>>>
 * @phpstan-type ManifestData array<string, ClassRules|DescriptorTable>
 */
final class ConstraintManifest
{
    public const string CONSTRAINTS = '@constraints';

    /**
     * Keys are the DTO class names (strings rather than class-strings on purpose: the same array carries the
     * reserved `@constraints` key on the way in, and nothing here needs the narrower type).
     *
     * @param  array<string, array<string, list<string|ValidationRule>>>  $rules
     * @param  array<string, array<string, list<ConstraintDescriptor>>>  $constraints
     */
    public function __construct(
        private readonly array $rules,
        private readonly array $constraints = [],
    ) {}

    /**
     * The array is heterogeneous BY KEY — every class name maps to that class's rule rows, and the one
     * reserved key maps to the descriptor table — which no array type can spell, so the two shapes are
     * told apart here, once, and narrowed as they are read.
     *
     * @param  ManifestData  $data
     */
    public static function fromArray(array $data): self
    {
        $descriptorRows = [];
        if (array_key_exists(self::CONSTRAINTS, $data)) {
            /** @var DescriptorTable $descriptorRows */
            $descriptorRows = $data[self::CONSTRAINTS];
            unset($data[self::CONSTRAINTS]);
        }

        $rules = [];
        /** @var ClassRules $properties */
        foreach ($data as $class => $properties) {
            $rehydrated = [];
            foreach ($properties as $property => $entries) {
                $list = [];
                foreach ($entries as $entry) {
                    $list[] = is_string($entry) ? $entry : self::rehydrate($entry);
                }
                $rehydrated[$property] = $list;
            }
            $rules[$class] = $rehydrated;
        }

        $constraints = [];
        foreach ($descriptorRows as $class => $properties) {
            foreach ($properties as $property => $rows) {
                $constraints[$class][$property] = array_map(ConstraintDescriptor::fromArray(...), $rows);
            }
        }

        return new self($rules, $constraints);
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new ConfigurationException("Constraint manifest not found at {$path}. Run the constraint scan first.");
        }

        /** @var mixed $data */
        $data = require $path;
        if (! is_array($data)) {
            throw new ConfigurationException("Constraint manifest at {$path} did not return an array.");
        }

        /** @var ManifestData $data */
        return self::fromArray($data);
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rulesFor(string $class): array
    {
        return $this->rules[$class] ?? [];
    }

    /**
     * The constraints declared on each property path of $class, in declaration order — keyed exactly like
     * rulesFor(); empty for a class the compiler never saw or a manifest written before descriptors existed.
     *
     * @return array<string, list<ConstraintDescriptor>>
     */
    public function constraintsFor(string $class): array
    {
        return $this->constraints[$class] ?? [];
    }

    /**
     * The only place a compiled envelope becomes an object again. Positional splat, no reflection, no
     * per-rule knowledge — everything needed to rebuild the rule was decided by the compiler.
     *
     * @param  RuleEnvelope  $entry
     */
    private static function rehydrate(array $entry): ValidationRule
    {
        $class = $entry['@rule'];
        $args = $entry['args'] ?? [];

        return new $class(...$args);
    }
}
