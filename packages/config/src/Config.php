<?php

declare(strict_types=1);

namespace Firefly\Config;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Contracts\Config\Repository;

/**
 * A typed, fail-fast accessor over Laravel's config repository. A required key (default === null) that is
 * missing, or a value that cannot be coerced to the requested type, throws a ConfigurationException rather
 * than silently returning null or a wrong-typed value.
 */
final class Config
{
    public function __construct(private readonly Repository $repository) {}

    public function has(string $key): bool
    {
        return $this->repository->has($key);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->repository->get($key, $default);
    }

    public function string(string $key, ?string $default = null): string
    {
        $value = $this->required($key, $default);
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        throw $this->mismatch($key, 'string', $value);
    }

    public function int(string $key, ?int $default = null): int
    {
        $value = $this->required($key, $default);
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw $this->mismatch($key, 'int', $value);
    }

    public function bool(string $key, ?bool $default = null): bool
    {
        $value = $this->required($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value) || is_int($value)) {
            $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        throw $this->mismatch($key, 'bool', $value);
    }

    /**
     * @param  array<mixed>|null  $default
     * @return array<mixed>
     */
    public function array(string $key, ?array $default = null): array
    {
        $value = $this->required($key, $default);
        if (is_array($value)) {
            return $value;
        }

        throw $this->mismatch($key, 'array', $value);
    }

    private function required(string $key, mixed $default): mixed
    {
        $value = $this->repository->has($key) ? $this->repository->get($key) : null;

        if ($value === null) {
            if ($default !== null) {
                return $default;
            }

            throw new ConfigurationException("Required configuration key [{$key}] is not set.");
        }

        return $value;
    }

    private function mismatch(string $key, string $expected, mixed $actual): ConfigurationException
    {
        $type = get_debug_type($actual);

        return new ConfigurationException("Configuration key [{$key}] must be {$expected}, got {$type}.");
    }
}
