<?php

declare(strict_types=1);

namespace Firefly\Container\Registrar;

use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Container\Scope;
use Illuminate\Contracts\Container\Container;

final class ContainerRegistrar
{
    public function __construct(private readonly Container $container) {}

    public function register(ComponentManifest $manifest): void
    {
        foreach ($manifest->components as $component) {
            $this->bindClass($component);
            $this->registerName($component);
        }

        $this->wireInterfaces($manifest);
    }

    public function tagFor(string $interface): string
    {
        return 'firefly.contract.'.$interface;
    }

    private function bindClass(ComponentDescriptor $component): void
    {
        $class = $component->class;

        match ($component->scope) {
            Scope::Singleton => $this->container->singleton($class, $class),
            Scope::Transient => $this->container->bind($class, $class),
            Scope::Scoped => $this->container->scoped($class, $class),
        };
    }

    private function registerName(ComponentDescriptor $component): void
    {
        $name = $component->name ?? $component->qualifier;
        if ($name !== null && $name !== $component->class) {
            $this->container->alias($component->class, $name);
        }
    }

    private function wireInterfaces(ComponentManifest $manifest): void
    {
        /** @var array<string, list<ComponentDescriptor>> $byInterface */
        $byInterface = [];
        foreach ($manifest->components as $component) {
            foreach ($component->interfaces as $interface) {
                $byInterface[$interface][] = $component;
            }
        }

        foreach ($byInterface as $interface => $impls) {
            // Tag all implementations for ordered list resolution.
            $this->container->tag(
                array_map(static fn (ComponentDescriptor $c): string => $c->class, $impls),
                $this->tagFor($interface),
            );

            // Bind the interface to a single default: the #[Primary], else the sole implementation.
            $primary = array_values(array_filter($impls, static fn (ComponentDescriptor $c): bool => $c->primary));
            if (count($primary) === 1) {
                $this->container->bind($interface, $primary[0]->class);
            } elseif (count($impls) === 1) {
                $this->container->bind($interface, $impls[0]->class);
            }
            // Otherwise leave the interface unbound: ambiguous, must be resolved by name/qualifier.
        }
    }
}
