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
}
