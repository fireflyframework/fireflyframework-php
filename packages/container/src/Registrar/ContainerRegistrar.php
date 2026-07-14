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
            $this->registerBeans($component);
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
            // Alias registration is last-registration-wins by design, mirroring
            // Illuminate's Container::alias(): if two components register the
            // same name/qualifier, the later one silently rebinds the alias.
            $this->container->alias($component->class, $name);
        }
    }

    private function registerBeans(ComponentDescriptor $component): void
    {
        foreach ($component->beans as $bean) {
            if ($bean->returns === '') {
                continue;
            }

            $configClass = $component->class;
            $method = $bean->method;
            $factory = function (Container $c) use ($configClass, $method): mixed {
                /** @var object $config */
                $config = $c->make($configClass);

                // The scanner only records #[Bean] on public methods that
                // exist on $configClass (see ComponentScanner::beansOf()),
                // so this array is guaranteed to be a valid callable; PHPStan
                // cannot verify that from a dynamic method-name string alone.
                /** @var callable $callable */
                $callable = [$config, $method];

                return $c->call($callable);
            };

            match ($bean->scope) {
                Scope::Singleton => $this->container->singleton($bean->returns, $factory),
                Scope::Transient => $this->container->bind($bean->returns, $factory),
                Scope::Scoped => $this->container->scoped($bean->returns, $factory),
            };

            if ($bean->name !== null && $bean->name !== $bean->returns) {
                // Same last-registration-wins semantics as registerName() above.
                $this->container->alias($bean->returns, $bean->name);
            }
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
            //
            // The interface is bound to a single default ONLY when unambiguous:
            // exactly one #[Primary] implementation, or exactly one implementation
            // total. With zero-or-multiple #[Primary] implementations among
            // multiple candidates, the choice is inherently ambiguous, so the
            // interface is intentionally left UNBOUND — callers must resolve it
            // by name/qualifier or via the ordered tagged list (see tagFor()).
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
