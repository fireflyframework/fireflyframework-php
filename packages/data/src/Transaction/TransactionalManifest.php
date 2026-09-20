<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * The compiled, reflection-free source the data package reads at boot and at dispatch. Loaded via require+map;
 * every value is a scalar/array so the whole manifest var_exports as a plain array literal. Four maps:
 *
 *   proxies      — each #[Transactional] class -> its generated proxy class + per-method descriptor rows
 *                  (TransactionalBeanPostProcessor).
 *   queries      — each #[Query] method's SQL (EloquentRepository::dispatchQuery). Its row shape is frozen.
 *   repositories — each repository method carrying #[Modifying] / #[Projection] / #[Lock] / #[EntityGraph], or
 *                  declaring a Slice/Page return type: what EloquentRepository needs to honour the attribute
 *                  without reflecting anything at runtime (a #[Projection]'s DTO constructor is reflected ONCE,
 *                  at scan time, into `parameters`).
 *   listeners    — every #[TransactionalEventListener] method, event resolved at scan time
 *                  (TransactionalEventListenerWiringPass).
 *
 * A transactional.php compiled before the last two maps existed loads fine: fromArray() defaults them to
 * empty. Mirrors the container ComponentManifest / scheduling ScheduledManifest idiom.
 *
 * @phpstan-import-type TransactionalRow from TransactionalDescriptor
 *
 * @phpstan-type ProxyRow array{proxyClass: string, methods: array<string, TransactionalRow>}
 * @phpstan-type QueryRow array{sql: string, native: bool}
 * @phpstan-type ProjectionParameterRow array{name: string, column: string, type: string|null, nullable: bool, optional: bool}
 * @phpstan-type ProjectionRow array{dto: class-string, columns: list<string>, parameters: list<ProjectionParameterRow>}
 * @phpstan-type EntityGraphRow array{value: string|null, attributePaths: list<string>}
 * @phpstan-type RepositoryMethodRow array{modifying: array{requiresTransaction: bool}|null, projection: ProjectionRow|null, lock: string|null, entityGraph: EntityGraphRow|null, returns: 'slice'|'page'|null}
 * @phpstan-type ListenerRow array{class: class-string, method: string, event: class-string, phase: string, fallbackExecution: bool, order: int}
 * @phpstan-type ManifestData array{proxies: array<class-string, ProxyRow>, queries: array<class-string, array<string, QueryRow>>, repositories?: array<class-string, array<string, RepositoryMethodRow>>, listeners?: list<ListenerRow>}
 */
final class TransactionalManifest
{
    /**
     * @param  array<class-string, ProxyRow>  $proxies
     * @param  array<class-string, array<string, QueryRow>>  $queries
     * @param  array<class-string, array<string, RepositoryMethodRow>>  $repositories
     * @param  list<ListenerRow>  $listeners
     */
    public function __construct(
        private readonly array $proxies,
        private readonly array $queries = [],
        private readonly array $repositories = [],
        private readonly array $listeners = [],
    ) {}

    /**
     * @param  ManifestData  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['proxies'], $data['queries'], $data['repositories'] ?? [], $data['listeners'] ?? []);
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
        return [
            'proxies' => $this->proxies,
            'queries' => $this->queries,
            'repositories' => $this->repositories,
            'listeners' => $this->listeners,
        ];
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

    /**
     * @return array<string, RepositoryMethodRow>
     */
    public function repositoryMethodsFor(string $class): array
    {
        return $this->repositories[$class] ?? [];
    }

    /**
     * @return RepositoryMethodRow|null
     */
    public function repositoryMethod(string $class, string $method): ?array
    {
        return $this->repositories[$class][$method] ?? null;
    }

    /**
     * @return list<ListenerRow>
     */
    public function listeners(): array
    {
        return $this->listeners;
    }
}
