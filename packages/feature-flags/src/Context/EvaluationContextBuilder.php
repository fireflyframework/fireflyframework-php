<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Context;

/**
 * The mutable context contributors write into; the resolver turns it into an OpenFeature EvaluationContext.
 *
 * The attributes are keyed by array-key: PHP stores a numeric-looking name (`"2024"`) as an int key, which
 * OpenFeature cannot carry, so the resolver drops it. Read a name from attributes() with `(string) $name`.
 */
final class EvaluationContextBuilder
{
    private ?string $targetingKey = null;

    /** @var array<array-key, mixed> */
    private array $attributes = [];

    public function targetingKey(): ?string
    {
        return $this->targetingKey;
    }

    /** An empty key is no key (anonymous traffic). */
    public function setTargetingKey(?string $targetingKey): self
    {
        $this->targetingKey = $targetingKey === '' ? null : $targetingKey;

        return $this;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    public function get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function set(string $name, mixed $value): self
    {
        $this->attributes[$name] = $value;

        return $this;
    }

    public function remove(string $name): self
    {
        unset($this->attributes[$name]);

        return $this;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }
}
