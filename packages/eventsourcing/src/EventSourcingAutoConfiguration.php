<?php

declare(strict_types=1);

namespace Firefly\EventSourcing;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Lazy;
use Firefly\Container\Attributes\Order;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Eda\Database\Outbox\OutboxWriter;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\JsonSerializer;
use Firefly\Eda\Serializer;
use Firefly\EventSourcing\Aggregate\EventSourcingSupport;
use Firefly\EventSourcing\Event\EventEnvelopeCodec;
use Firefly\EventSourcing\Event\EventMapper;
use Firefly\EventSourcing\Event\EventTypeRegistry;
use Firefly\EventSourcing\Manifest\EventSourcingManifest;
use Firefly\EventSourcing\Metadata\AuthenticatedUserMetadataContributor;
use Firefly\EventSourcing\Metadata\CorrelationIdMetadataContributor;
use Firefly\EventSourcing\Metadata\EventMetadataContributor;
use Firefly\EventSourcing\Metadata\MetadataResolver;
use Firefly\EventSourcing\Projection\CheckpointStore;
use Firefly\EventSourcing\Projection\DatabaseCheckpointStore;
use Firefly\EventSourcing\Projection\MemoryCheckpointStore;
use Firefly\EventSourcing\Projection\Projection;
use Firefly\EventSourcing\Projection\ProjectionRegistry;
use Firefly\EventSourcing\Projection\ProjectionRunner;
use Firefly\EventSourcing\Publishing\AfterCommitEventStorePublisher;
use Firefly\EventSourcing\Publishing\EventStorePublisher;
use Firefly\EventSourcing\Publishing\NoOpEventStorePublisher;
use Firefly\EventSourcing\Publishing\OutboxEventStorePublisher;
use Firefly\EventSourcing\Snapshot\DatabaseSnapshotStore;
use Firefly\EventSourcing\Snapshot\MemorySnapshotStore;
use Firefly\EventSourcing\Snapshot\SnapshotPolicy;
use Firefly\EventSourcing\Snapshot\SnapshotStore;
use Firefly\EventSourcing\Store\DatabaseEventStore;
use Firefly\EventSourcing\Store\EventStore;
use Firefly\EventSourcing\Store\MemoryEventStore;
use Firefly\EventSourcing\Store\NullTenantResolver;
use Firefly\EventSourcing\Store\TenantResolver;
use Firefly\EventSourcing\Store\TenantScopedEventStore;
use Firefly\EventSourcing\Upcasting\EventUpcaster;
use Firefly\EventSourcing\Upcasting\UpcasterChain;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Scheduling\Lock\DistributedLock;
use Firefly\Scheduling\Lock\NoneLock;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Log\Context\Repository as ContextRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Wires firefly/eventsourcing. #[Order(1000)] places it after user definitions, and every bean backs off
 * #[ConditionalOnMissingBean], so an application binds its own store, publisher, tenant resolver or registry and
 * wins. Only the settings are EAGER, so a configuration the package cannot honour refuses the boot. Everything else
 * is #[Lazy]: a request that never touches an aggregate opens no connection.
 *
 * Collections come from scanned components through the Firefly container's getAll(): EventUpcaster beans make up the
 * UpcasterChain, and EventMetadataContributor beans join the framework's correlation-id and user contributors.
 * Projection components make up the ProjectionRegistry, and the ProjectionRunner leases each projection on whatever
 * DistributedLock firefly.scheduling.lock.provider binds (NoneLock when firefly/scheduling is not booted).
 */
#[Configuration]
#[Order(1000)]
final class EventSourcingAutoConfiguration
{
    #[Bean]
    #[ConditionalOnMissingBean(EventSourcingSettings::class)]
    public function eventSourcingSettings(Config $config): EventSourcingSettings
    {
        return EventSourcingSettings::fromConfig($config);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(EventTypeRegistry::class)]
    public function eventTypeRegistry(Container $app): EventTypeRegistry
    {
        $manifest = $app->bound(EventSourcingManifest::class) ? $app->make(EventSourcingManifest::class) : null;

        return new EventTypeRegistry($manifest instanceof EventSourcingManifest ? $manifest->eventTypes() : []);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(EventMapper::class)]
    public function eventMapper(EventTypeRegistry $types): EventMapper
    {
        return new EventMapper($types);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(UpcasterChain::class)]
    public function upcasterChain(FireflyContainer $beans): UpcasterChain
    {
        return new UpcasterChain(array_values(array_filter($beans->getAll(EventUpcaster::class), static fn (object $upcaster): bool => $upcaster instanceof EventUpcaster)));
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(EventEnvelopeCodec::class)]
    public function eventEnvelopeCodec(Container $app): EventEnvelopeCodec
    {
        $serializer = $app->bound(Serializer::class) ? $app->make(Serializer::class) : null;

        return new EventEnvelopeCodec($serializer instanceof Serializer ? $serializer : new JsonSerializer);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(EventStorePublisher::class)]
    public function eventStorePublisher(EventSourcingSettings $settings, Container $app): EventStorePublisher
    {
        if (! $settings->publishEnabled) {
            return new NoOpEventStorePublisher;
        }

        if (! $app->bound(EventPublisher::class)) {
            throw new ConfigurationException('Configuration key [firefly.eventsourcing.publish.enabled] is true but no firefly/eda EventPublisher is bound.');
        }
        /** @var EventPublisher $publisher whichever eda provider is selected: the outbox writer, a broker, or the in-memory bus. */
        $publisher = $app->make(EventPublisher::class);

        return $publisher instanceof OutboxWriter
            ? new OutboxEventStorePublisher($publisher, $settings->publishDestination, $settings->publishExcludedAggregateTypes)
            : new AfterCommitEventStorePublisher($publisher, $settings->publishDestination, $settings->publishExcludedAggregateTypes);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(TenantResolver::class)]
    public function tenantResolver(): TenantResolver
    {
        return new NullTenantResolver;
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(EventStore::class)]
    public function eventStore(EventSourcingSettings $settings, EventEnvelopeCodec $codec, UpcasterChain $upcasters, EventStorePublisher $publisher, TenantResolver $tenants, Container $app): EventStore
    {
        $store = $settings->storeProvider === 'database'
            ? new DatabaseEventStore(self::connection($app, $settings->connection, 'firefly.eventsourcing.store.provider'), $codec, $upcasters, $publisher, $settings->batchSize)
            : new MemoryEventStore($codec, $upcasters, $publisher);

        return $settings->tenancyEnabled ? new TenantScopedEventStore($store, $tenants) : $store;
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(SnapshotStore::class)]
    public function snapshotStore(EventSourcingSettings $settings, Container $app): SnapshotStore
    {
        return $settings->snapshotStore === 'database'
            ? new DatabaseSnapshotStore(self::connection($app, $settings->connection, 'firefly.eventsourcing.snapshot.store'))
            : new MemorySnapshotStore;
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(MetadataResolver::class)]
    public function metadataResolver(EventSourcingSettings $settings, FireflyContainer $beans, Container $app): MetadataResolver
    {
        $contributors = [new CorrelationIdMetadataContributor(static function () use ($app): ContextRepository {
            /** @var ContextRepository $context the scoped Context of the current request. */
            $context = $app->make(ContextRepository::class);

            return $context;
        })];
        if ($settings->captureUser && $app->bound(AuthFactory::class)) {
            /** @var AuthFactory $auth */
            $auth = $app->make(AuthFactory::class);
            $contributors[] = new AuthenticatedUserMetadataContributor($auth);
        }
        foreach ($beans->getAll(EventMetadataContributor::class) as $contributor) {
            if ($contributor instanceof EventMetadataContributor) {
                $contributors[] = $contributor;
            }
        }

        return new MetadataResolver($contributors);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(EventSourcingSupport::class)]
    public function eventSourcingSupport(EventStore $store, EventMapper $mapper, SnapshotStore $snapshots, EventSourcingSettings $settings, MetadataResolver $metadata, Container $app): EventSourcingSupport
    {
        $logger = $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null;

        return new EventSourcingSupport($store, $mapper, $snapshots, SnapshotPolicy::fromSettings($settings), $metadata, $logger instanceof LoggerInterface ? $logger : new NullLogger);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(CheckpointStore::class)]
    public function checkpointStore(EventSourcingSettings $settings, Container $app): CheckpointStore
    {
        return $settings->checkpointStore === 'database'
            ? new DatabaseCheckpointStore(self::connection($app, $settings->connection, 'firefly.eventsourcing.projection.checkpoint-store'))
            : new MemoryCheckpointStore;
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(ProjectionRegistry::class)]
    public function projectionRegistry(FireflyContainer $beans): ProjectionRegistry
    {
        $registry = new ProjectionRegistry;
        foreach ($beans->getAll(Projection::class) as $projection) {
            if ($projection instanceof Projection) {
                $registry->register($projection);
            }
        }

        return $registry;
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(ProjectionRunner::class)]
    public function projectionRunner(EventStore $store, CheckpointStore $checkpoints, ProjectionRegistry $projections, EventSourcingSettings $settings, Container $app): ProjectionRunner
    {
        $lock = $app->bound(DistributedLock::class) ? $app->make(DistributedLock::class) : null;
        $logger = $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null;

        return new ProjectionRunner(
            $store,
            $checkpoints,
            $projections,
            $lock instanceof DistributedLock ? $lock : new NoneLock,
            $settings->projectionBatchSize,
            $settings->leaseTtl,
            $settings->pollInterval,
            $logger instanceof LoggerInterface ? $logger : new NullLogger,
        );
    }

    /** The concrete connection $name names (null = the default), refused with the key that asked for it. */
    public static function connection(Container $app, ?string $name, string $key): Connection
    {
        $databases = $app->bound('db') ? $app->make('db') : null;
        if (! $databases instanceof ConnectionResolverInterface) {
            throw new ConfigurationException("Configuration key [{$key}] is database but no database connection resolver (db) is bound.");
        }
        /** @var ConnectionInterface $connection the resolver's declared type: a custom resolver may hand back any implementation. */
        $connection = $databases->connection($name);

        return $connection instanceof Connection ? $connection : throw new ConfigurationException("Configuration key [{$key}] is database but the connection [".($name ?? 'default').'] is not an Illuminate\Database\Connection.');
    }
}
