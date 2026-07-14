<?php

declare(strict_types=1);

namespace Firefly\Container\Registrar;

use Firefly\Container\Attributes\Value;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Container\Scope;
use Firefly\Container\Value\DefaultValueResolver;
use Firefly\Container\Value\ValueResolver;
use Illuminate\Container\Container;

final class ContainerRegistrar
{
    private const REGISTERED = 'firefly.container.registered';

    public function __construct(private readonly Container $container) {}

    public function register(ComponentManifest $manifest): void
    {
        // Illuminate's tag() APPENDS rather than replaces, so a second call to
        // register() on the same container would duplicate every tagged binding
        // (e.g. getAll() returning 6 instances instead of 3). Providers can be
        // register()/boot()-invoked more than once, so guard with a per-container
        // sentinel: a fresh container is never bound here, making register() a
        // no-op on repeat calls against an already-registered container.
        if ($this->container->bound(self::REGISTERED)) {
            return;
        }
        $this->container->instance(self::REGISTERED, true);

        $this->registerValueSupport();

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

    private function registerValueSupport(): void
    {
        if (! $this->container->bound(ValueResolver::class)) {
            $this->container->singleton(ValueResolver::class, DefaultValueResolver::class);
        }

        // Resolve #[Value] parameters through the bound ValueResolver.
        $this->container->whenHasAttribute(
            Value::class,
            fn (Value $attribute): mixed => $this->container->make(ValueResolver::class)->resolve($attribute->expression),
        );
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
