<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * The compiled, reflection-free #[Transactional] source the BeanPostProcessor reads at boot. Loaded via
 * require+map; every value is a scalar/array so the whole manifest var_exports as a plain array literal. The
 * `proxies` map keys each transactional class to its generated proxy class + per-method descriptor rows; the
 * `queries` map keys #[Query] methods for a later chunk's derived-query dispatch. Mirrors the container
 * ComponentManifest / scheduling ScheduledManifest idiom.
 *
 * @phpstan-import-type TransactionalRow from TransactionalDescriptor
 *
 * @phpstan-type ProxyRow array{proxyClass: string, methods: array<string, TransactionalRow>}
 * @phpstan-type QueryRow array{sql: string, native: bool}
 * @phpstan-type ManifestData array{proxies: array<class-string, ProxyRow>, queries: array<class-string, array<string, QueryRow>>}
 */
final class TransactionalManifest
{
    /**
     * @param  array<class-string, ProxyRow>  $proxies
     * @param  array<class-string, array<string, QueryRow>>  $queries
     */
    public function __construct(
        private readonly array $proxies,
        private readonly array $queries = [],
    ) {}

    /**
     * @param  ManifestData  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['proxies'], $data['queries']);
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new ConfigurationException("Transactional manifest not found at {$path}. Run the transactional scan first.");
        }

        /** @var mixed $data */
        $data = require $path;
        if (! is_array($data)) {
            throw new ConfigurationException("Transactional manifest at {$path} did not return an array.");
        }

        /** @var ManifestData $data */
        return self::fromArray($data);
    }

    /**
     * @return ManifestData
     */
    public function toArray(): array
    {
        return ['proxies' => $this->proxies, 'queries' => $this->queries];
    }

    /**
     * @return array<class-string, ProxyRow>
     */
    public function all(): array
    {
        return $this->proxies;
    }

    public function hasProxyFor(string $class): bool
    {
        return isset($this->proxies[$class]);
    }

    public function proxyClassFor(string $class): string
    {
        if (! isset($this->proxies[$class])) {
            throw new ConfigurationException("No transactional proxy is registered for [{$class}].");
        }

        return $this->proxies[$class]['proxyClass'];
    }

    public function descriptorFor(string $class, string $method): TransactionalDescriptor
    {
        if (! isset($this->proxies[$class]['methods'][$method])) {
            throw new ConfigurationException("No transactional descriptor for [{$class}::{$method}].");
        }

        return TransactionalDescriptor::fromArray($this->proxies[$class]['methods'][$method]);
    }

    /**
     * @return array<string, QueryRow>
     */
    public function queriesFor(string $class): array
    {
        return $this->queries[$class] ?? [];
    }
}
