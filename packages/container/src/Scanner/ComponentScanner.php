<?php

declare(strict_types=1);

namespace Firefly\Container\Scanner;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Container\Attributes\Primary;
use Firefly\Container\Attributes\Qualifier;
use Firefly\Container\Descriptor\BeanDescriptor;
use Firefly\Container\Descriptor\ComponentDescriptor;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

final class ComponentScanner
{
    /**
     * Scan PSR-4 namespaces for #[Component]-annotated classes.
     *
     * Discovery uses class_exists(), which autoloads — so each prefix => dir
     * mapping must also be registered with the active Composer autoloader
     * (in production, pass Composer's own PSR-4 map). A trailing "\\" on the
     * prefix is optional; it is normalized internally.
     *
     * @param  array<string,string>  $psr4  namespace-prefix => absolute directory
     * @return list<ComponentDescriptor>
     */
    public function scan(array $psr4): array
    {
        $descriptors = [];

        foreach ($psr4 as $prefix => $dir) {
            foreach ($this->classesIn($prefix, $dir) as $class) {
                $descriptor = $this->describe($class);
                if ($descriptor !== null) {
                    $descriptors[] = $descriptor;
                }
            }
        }

        return $descriptors;
    }

    /**
     * @return list<class-string>
     */
    private function classesIn(string $prefix, string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $prefix = rtrim($prefix, '\\').'\\';

        $classes = [];
        $realDir = rtrim((string) realpath($dir), DIRECTORY_SEPARATOR);

        /** @var iterable<\SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($realDir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr((string) $file->getRealPath(), strlen($realDir) + 1, -4);
            $class = $prefix.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
            if (class_exists($class)) {
                /** @var class-string $class */
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * @param  class-string  $class
     */
    private function describe(string $class): ?ComponentDescriptor
    {
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || $reflection->isInterface()) {
            return null;
        }

        $componentAttrs = $reflection->getAttributes(Component::class, \ReflectionAttribute::IS_INSTANCEOF);
        if ($componentAttrs === []) {
            return null;
        }

        /** @var Component $component */
        $component = $componentAttrs[0]->newInstance();
        $shortAttr = strtolower((new ReflectionClass($component))->getShortName());

        /** @var list<class-string> $interfaces */
        $interfaces = array_values(class_implements($class) ?: []);

        return new ComponentDescriptor(
            class: $class,
            stereotype: $shortAttr,
            name: $component->name,
            scope: $component->scope,
            primary: $reflection->getAttributes(Primary::class) !== [],
            order: $this->orderOf($reflection->getAttributes(Order::class)),
            qualifier: $this->qualifierOf($reflection->getAttributes(Qualifier::class)),
            interfaces: $interfaces,
            beans: $shortAttr === 'configuration' ? $this->beansOf($reflection) : [],
        );
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @return list<BeanDescriptor>
     */
    private function beansOf(ReflectionClass $reflection): array
    {
        $beans = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $beanAttrs = $method->getAttributes(Bean::class);
            if ($beanAttrs === []) {
                continue;
            }
            /** @var Bean $bean */
            $bean = $beanAttrs[0]->newInstance();
            $returnType = $method->getReturnType();
            $returns = $returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin()
                ? $returnType->getName()
                : '';

            $beans[] = new BeanDescriptor(
                method: $method->getName(),
                returns: $returns,
                name: $bean->name,
                scope: $bean->scope,
                primary: $method->getAttributes(Primary::class) !== [],
                order: $this->orderOf($method->getAttributes(Order::class)),
            );
        }

        return $beans;
    }

    /**
     * @param  array<int, \ReflectionAttribute<Order>>  $attrs
     */
    private function orderOf(array $attrs): int
    {
        return $attrs === [] ? 0 : $attrs[0]->newInstance()->order;
    }

    /**
     * @param  array<int, \ReflectionAttribute<Qualifier>>  $attrs
     */
    private function qualifierOf(array $attrs): ?string
    {
        return $attrs === [] ? null : $attrs[0]->newInstance()->name;
    }
}
