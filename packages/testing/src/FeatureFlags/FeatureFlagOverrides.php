<?php

declare(strict_types=1);

namespace Firefly\Testing\FeatureFlags;

use Firefly\FeatureFlags\Definition\FlagDefinitions;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Provider\ValueCopy;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use LogicException;

/** Test-only, in-process flag layer for one running application. */
final class FeatureFlagOverrides
{
    /** @var array<array-key, mixed> */
    private array $flags = [];

    public function __construct(private readonly FlagRegistry $registry) {}

    /** @param array<array-key, mixed> $flags */
    public static function install(array $flags, ?ContainerContract $app = null): self
    {
        $app ??= Container::getInstance();
        if (! $app->bound(FlagRegistry::class)) {
            throw new LogicException('withFeatureFlags() requires a running flag registry: set firefly.feature-flags.enabled to true in the test configuration. An application with its own OpenFeature provider must stub that provider instead.');
        }

        /** @var FlagRegistry $registry */
        $registry = $app->make(FlagRegistry::class);
        $current = $app->bound(self::class) ? $app->make(self::class) : null;
        $overrides = $current instanceof self && $current->registry === $registry ? $current : new self($registry);
        $overrides->merge($flags);
        $app->instance(self::class, $overrides);

        return $overrides;
    }

    /** @param array<array-key, mixed> $flags */
    public function merge(array $flags): self
    {
        $candidate = $this->flags;
        foreach ($flags as $key => $definition) {
            $candidate[$key] = $definition;
        }

        return $this->apply($candidate);
    }

    public function set(string $key, mixed $definition): self
    {
        return $this->merge([$key => $definition]);
    }

    public function forget(string $key): self
    {
        $flags = $this->flags;
        unset($flags[$key]);

        return $this->apply($flags);
    }

    public function clear(): void
    {
        $this->apply([]);
    }

    /** @return array<array-key, mixed> */
    public function flags(): array
    {
        /** @var array<array-key, mixed> $snapshot */
        $snapshot = ValueCopy::of($this->flags);

        return $snapshot;
    }

    /** @param array<array-key, mixed> $flags */
    private function apply(array $flags): self
    {
        /** @var array<array-key, mixed> $owned */
        $owned = ValueCopy::of($flags);
        $document = $owned === [] ? null : FlagDefinitions::parseDocument([
            'flags' => Json::object(FlagDefinitions::normalize($owned)),
        ]);
        $this->registry->overrideForTests($document);
        $this->flags = $owned;

        return $this;
    }
}
