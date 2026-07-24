<?php

declare(strict_types=1);

namespace Firefly\Security\Access\Method;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * The compiled, reflection-free method-security source the CQRS authorizers + the ControllerSecurityGuard read at
 * enforcement time. Keyed by `Class::method`; loaded via require+map. Mirrors the cqrs HandlerManifest / data
 * TransactionalManifest idiom.
 *
 * @phpstan-import-type SecurityMethodRow from SecurityMethodDescriptor
 */
final class SecurityMethodManifest
{
    /** @var array<string,SecurityMethodDescriptor> */
    private array $rules = [];

    /**
     * @param  list<SecurityMethodDescriptor>  $rules
     */
    public function __construct(array $rules)
    {
        foreach ($rules as $rule) {
            $this->rules[$rule->key()] = $rule;
        }
    }

    /**
     * @param  array<int,SecurityMethodRow>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(
            static fn (array $row): SecurityMethodDescriptor => SecurityMethodDescriptor::fromArray($row),
            array_values($data),
        ));
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new ConfigurationException("Security method manifest not found at {$path}. Run the method-security scan first.");
        }

        /** @var mixed $data */
        $data = require $path;
        if (! is_array($data)) {
            throw new ConfigurationException("Security method manifest at {$path} did not return an array.");
        }

        /** @var array<int,SecurityMethodRow> $data */
        return self::fromArray($data);
    }

    /**
     * @return array<int,SecurityMethodRow>
     */
    public function toArray(): array
    {
        return array_values(array_map(static fn (SecurityMethodDescriptor $r): array => $r->toArray(), $this->rules));
    }

    public function ruleFor(string $class, string $method): ?SecurityMethodDescriptor
    {
        return $this->rules[$class.'::'.$method] ?? null;
    }

    /**
     * @return list<SecurityMethodDescriptor>
     */
    public function all(): array
    {
        return array_values($this->rules);
    }
}
