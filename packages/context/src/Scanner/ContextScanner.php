<?php

declare(strict_types=1);

namespace Firefly\Context\Scanner;

use Firefly\Container\Attributes\Bean;
use Firefly\Context\Condition\ConditionAttribute;
use Firefly\Context\Event\AsEventListener;
use Firefly\Context\Lifecycle\PostConstruct;
use Firefly\Context\Lifecycle\PreDestroy;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Discovers, per class, #[PostConstruct]/#[PreDestroy] method names, #[AsEventListener] methods,
 * and #[ConditionalOn*] attributes (class-level and per #[Bean] method) — mirroring M2's
 * ComponentScanner / M3's ConfigPropertiesScanner idiom exactly: reflection happens ONCE, here, at
 * scan time; the compiled manifest it produces is loaded with zero reflection (see
 * ContextManifest/ContextManifestCompiler).
 *
 * A class contributes NO descriptor at all (scan() simply omits it) unless it has at least one of
 * the things this scanner looks for — keeps the manifest sparse, matching ConfigPropertiesScanner
 * (which likewise only records classes carrying #[ConfigProperties]).
 *
 * A null #[AsEventListener] $event is INFERRED from the listener method's first parameter type
 * HERE, at scan time — never left for RegisterEventListenersPass to infer at boot. If it cannot be
 * inferred (no parameters, or a builtin-typed first parameter), scanning THROWS: this is a
 * developer error that must surface loudly, once, rather than silently reaching boot.
 */
final class ContextScanner
{
    /**
     * @param  array<string,string>  $psr4  namespace-prefix => absolute directory
     * @return list<ContextDescriptor>
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
        $realDir = rtrim((string) realpath($dir), DIRECTORY_SEPARATOR);
        $classes = [];

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
    private function describe(string $class): ?ContextDescriptor
    {
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || $reflection->isInterface()) {
            return null;
        }

        $postConstruct = [];
        $preDestroy = [];
        $listeners = [];
        $beanConditions = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(PostConstruct::class) !== []) {
                $postConstruct[] = $method->getName();
            }

            if ($method->getAttributes(PreDestroy::class) !== []) {
                $preDestroy[] = $method->getName();
            }

            foreach ($method->getAttributes(AsEventListener::class) as $attribute) {
                /** @var AsEventListener $listener */
                $listener = $attribute->newInstance();

                $listeners[] = [
                    'method' => $method->getName(),
                    'event' => $listener->event ?? $this->inferEventType($method, $class),
                    'order' => $listener->order,
                ];
            }

            if ($method->getAttributes(Bean::class) !== []) {
                $methodConditions = $this->conditionsOf(
                    $method->getAttributes(ConditionAttribute::class, ReflectionAttribute::IS_INSTANCEOF),
                );

                if ($methodConditions !== []) {
                    $beanConditions[] = ['method' => $method->getName(), 'conditions' => $methodConditions];
                }
            }
        }

        $conditions = $this->conditionsOf(
            $reflection->getAttributes(ConditionAttribute::class, ReflectionAttribute::IS_INSTANCEOF),
        );

        if ($postConstruct === [] && $preDestroy === [] && $listeners === [] && $conditions === [] && $beanConditions === []) {
            return null;
        }

        return new ContextDescriptor(
            class: $class,
            postConstruct: $postConstruct,
            preDestroy: $preDestroy,
            listeners: $listeners,
            conditions: $conditions,
            beanConditions: $beanConditions,
        );
    }

    /**
     * @param  list<ReflectionAttribute<ConditionAttribute>>  $attributes
     * @return list<array{type: string, args: list<mixed>}>
     */
    private function conditionsOf(array $attributes): array
    {
        $conditions = [];
        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();
            $conditions[] = [
                'type' => $instance::class,
                'args' => $this->argsOf($instance),
            ];
        }

        return $conditions;
    }

    /**
     * Extracts the exact POSITIONAL argument list that reconstructs $instance via
     * `new $type(...$args)` — including a VARIADIC constructor parameter (e.g.
     * ConditionalOnProfile(string ...$profiles)), whose values are spread as trailing positional
     * entries rather than collected under one named key (spreading a single named argument onto a
     * variadic parameter would re-key it, which a positional list sidesteps entirely). Every
     * default value the caller omitted is already resolved by newInstance(), so the resulting list
     * is complete and order alone reconstructs the instance faithfully.
     *
     * Requires a public property with the SAME NAME as each constructor parameter — true of every
     * #[ConditionalOn*] attribute Firefly ships (promoted or not).
     *
     * @return list<mixed>
     */
    private function argsOf(object $instance): array
    {
        $constructor = (new ReflectionClass($instance))->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $args = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (! property_exists($instance, $name)) {
                throw new ConfigurationException(
                    'Cannot serialize ['.$instance::class."]: constructor parameter \${$name} has no matching ".
                    'public property, so it cannot round-trip through the compiled context manifest.',
                );
            }

            /** @var mixed $value */
            $value = $instance->{$name};

            if ($parameter->isVariadic() && is_array($value)) {
                array_push($args, ...array_values($value));
            } else {
                $args[] = $value;
            }
        }

        return $args;
    }

    private function inferEventType(ReflectionMethod $method, string $declaringClass): string
    {
        $parameters = $method->getParameters();

        if ($parameters === []) {
            throw new ConfigurationException(
                "#[AsEventListener] on {$declaringClass}::{$method->getName()}() has no explicit event and no ".
                'parameters to infer one from.',
            );
        }

        $type = $parameters[0]->getType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            return $type->getName();
        }

        throw new ConfigurationException(
            "#[AsEventListener] on {$declaringClass}::{$method->getName()}() has no explicit event and its first ".
            'parameter has no inferable class type.',
        );
    }
}
