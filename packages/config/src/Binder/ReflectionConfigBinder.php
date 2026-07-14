<?php

declare(strict_types=1);

namespace Firefly\Config\Binder;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Binds a config array onto a plain readonly DTO by matching constructor parameters to array keys.
 * Scalars are coerced; a parameter typed as another class is bound recursively from its sub-array.
 * The seam (ConfigBinder) lets a richer binder be swapped in without touching call sites.
 */
final class ReflectionConfigBinder implements ConfigBinder
{
    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  array<string,mixed>  $config
     * @return T
     */
    public function bind(string $class, array $config): object
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $parameter) {
            $args[] = $this->resolveParameter($class, $parameter, $config);
        }

        return $reflection->newInstanceArgs($args);
    }

    /**
     * @param  class-string  $class
     * @param  array<string,mixed>  $config
     */
    private function resolveParameter(string $class, ReflectionParameter $parameter, array $config): mixed
    {
        $name = $parameter->getName();

        if (! array_key_exists($name, $config)) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }
            if ($parameter->allowsNull()) {
                return null;
            }

            throw new ConfigurationException("Missing required configuration property [{$name}] for {$class}.");
        }

        $value = $config[$name];
        $type = $parameter->getType();
        if (! $type instanceof ReflectionNamedType) {
            return $value;
        }

        return $this->coerce($type, $value);
    }

    private function coerce(ReflectionNamedType $type, mixed $value): mixed
    {
        if ($type->isBuiltin()) {
            return match ($type->getName()) {
                'int' => is_numeric($value) ? (int) $value : $value,
                'float' => is_numeric($value) ? (float) $value : $value,
                'bool' => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'string' => is_scalar($value) ? (string) $value : $value,
                default => $value, // array, mixed, etc.
            };
        }

        // A nested DTO: recurse when we have a sub-array to bind.
        $nested = $type->getName();
        if (is_array($value) && class_exists($nested)) {
            /** @var class-string $nested */
            /** @var array<string,mixed> $value */
            return $this->bind($nested, $value);
        }

        return $value;
    }
}
