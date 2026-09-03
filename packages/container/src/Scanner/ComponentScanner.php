<?php

declare(strict_types=1);

namespace Firefly\Container\Scanner;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Lazy;
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

        // The stereotype is a REPORTING label (actuator's /beans, BeanDefinition,
        // ContextDescriptor) — never a behavioural switch. See beansOf() below for
        // the switch that used to be built on it and the bug that caused.
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
            beans: $this->beansOf($reflection),
            lazy: $reflection->getAttributes(Lazy::class) !== [],
            dependencies: $this->dependenciesOf($reflection),
        );
    }

    /**
     * The class and interface types this component's constructor asks for — the edges of the bean graph.
     *
     * Scalars, builtins and untyped parameters are skipped: those are configuration, not wiring, and putting
     * them in the graph would bury the edges that matter under `string $name` noise. A nullable or defaulted
     * class parameter IS kept, because an optional collaborator is still a relationship.
     *
     * @param  ReflectionClass<object>  $reflection
     * @return list<string>
     */
    private function dependenciesOf(ReflectionClass $reflection): array
    {
        $constructor = $reflection->getConstructor();

        return $constructor === null ? [] : $this->parameterTypes($constructor);
    }

    /**
     * The class and interface types a callable asks for, in declaration order and de-duplicated.
     *
     * @return list<string>
     */
    private function parameterTypes(ReflectionMethod $method): array
    {
        $types = [];
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $types[] = $type->getName();
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * Collect the #[Bean] factory methods declared on an already-discovered component.
     *
     * WHY THIS IS UNCONDITIONAL. It used to be gated by the caller on a stereotype
     * SHORT-NAME STRING comparison — `$shortAttr === 'configuration'` — the single
     * place in the scanner that abandoned the ReflectionAttribute::IS_INSTANCEOF
     * discipline used everywhere else (describe() finds stereotypes via
     * getAttributes(Component::class, IS_INSTANCEOF) precisely so that a subclass of
     * a stereotype IS that stereotype). String equality is not subtype equality, so
     * the gate silently dropped every bean it did not recognise by exact spelling:
     *
     *  - a user-defined stereotype specialising #[Configuration] — `#[Attribute] final
     *    class ApiConfiguration extends Configuration {}` — reports the short name
     *    'apiconfiguration', so ALL of its #[Bean] methods vanished from the manifest.
     *    The class itself was still discovered and bound, which made the failure
     *    especially confusing: the #[Configuration] was present, its beans were not.
     *  - a #[Bean] method on a plain #[Component] (or on #[Service]/#[Repository])
     *    vanished the same way, even though Spring processes bean factory methods on
     *    ANY component class — its "lite mode" configuration classes.
     *
     * Nothing is lost by always scanning: a component with no #[Bean] method yields
     * an empty list, exactly as the gate used to force. The only stereotype test left
     * in this scanner is describe()'s IS_INSTANCEOF Component check — where it belongs.
     *
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
                lazy: $method->getAttributes(Lazy::class) !== [],
                dependencies: $this->parameterTypes($method),
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
