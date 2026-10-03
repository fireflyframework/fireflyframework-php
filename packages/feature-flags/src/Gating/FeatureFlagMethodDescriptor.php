<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Gating;

/** @phpstan-type FeatureFlagMethodRow array{class: string, method: string, key: string, variant?: string|null, default?: bool, fallback?: string|null, route?: bool} */
final readonly class FeatureFlagMethodDescriptor
{
    public function __construct(
        public string $class,
        public string $method,
        public string $key,
        public ?string $variant = null,
        public bool $default = false,
        public ?string $fallback = null,
        public bool $route = false,
    ) {}

    public function site(): string
    {
        return $this->class.'::'.$this->method;
    }

    public function middleware(): string
    {
        return FeatureFlagGate::MIDDLEWARE_ALIAS.':'.$this->key.','.($this->variant ?? '').','.($this->default ? 'true' : 'false');
    }

    /** @return FeatureFlagMethodRow */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'method' => $this->method,
            'key' => $this->key,
            'variant' => $this->variant,
            'default' => $this->default,
            'fallback' => $this->fallback,
            'route' => $this->route,
        ];
    }

    /** @param FeatureFlagMethodRow $data */
    public static function fromArray(array $data): self
    {
        return new self($data['class'], $data['method'], $data['key'], $data['variant'] ?? null, $data['default'] ?? false, $data['fallback'] ?? null, $data['route'] ?? false);
    }
}
