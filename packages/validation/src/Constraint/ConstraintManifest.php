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
 * @phpstan-type RuleEnvelope array{'@rule': class-string<ValidationRule>, args?: list<mixed>}
 */
final class ConstraintManifest
{
    /**
     * @param  array<class-string, array<string, list<string|ValidationRule>>>  $rules
     */
    public function __construct(private readonly array $rules) {}

    /**
     * @param  array<class-string, array<string, list<string|RuleEnvelope>>>  $data
     */
    public static function fromArray(array $data): self
    {
        $rules = [];
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

        return new self($rules);
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

        /** @var array<class-string, array<string, list<string|RuleEnvelope>>> $data */
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
     * @param  RuleEnvelope  $entry
     */
    private static function rehydrate(array $entry): ValidationRule
    {
        $class = $entry['@rule'];
        $args = $entry['args'] ?? [];

        return new $class(...$args);
    }
}
