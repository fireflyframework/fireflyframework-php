<?php

declare(strict_types=1);

namespace Firefly\Testing\Fixture;

use Closure;
use LogicException;

/** A thin named-fixture registry over plain factory closures — the Firefly convenience atop Eloquent factories. */
final class FixtureRegistry
{
    /** @var array<string, Closure(array<string,mixed>): object> */
    private array $factories = [];

    /** @param Closure(array<string,mixed>): object $factory */
    public function register(string $name, Closure $factory): self
    {
        $this->factories[$name] = $factory;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    /** @param array<string,mixed> $overrides */
    public function make(string $name, array $overrides = []): object
    {
        $factory = $this->factories[$name]
            ?? throw new LogicException("No fixture registered for [{$name}].");

        return $factory($overrides);
    }

    /** @return list<object> */
    public function load(string ...$names): array
    {
        return array_map(fn (string $name): object => $this->make($name), array_values($names));
    }
}
