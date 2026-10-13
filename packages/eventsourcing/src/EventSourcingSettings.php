<?php

declare(strict_types=1);

namespace Firefly\EventSourcing;

use Firefly\Config\Config;
use Firefly\Eda\Database\DatabaseOutboxSettings;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Resilience\Duration;

/**
 * firefly.eventsourcing.* read ONCE. Every key is spelled out in full where it is read, so
 * tests/ConfigReferenceTest.php sees it, and every value the package cannot honour refuses the boot here, naming
 * its key: the settings bean is the one EAGER bean of the package.
 *
 * `snapshot.store` and `projection.checkpoint-store` follow `store.provider` while they are unset, so one key
 * moves the whole package onto a database. Same-transaction publishing (publish.enabled under
 * firefly.eda.provider database|postgres) needs the outbox row and the events in ONE transaction, so the event
 * store's connection must be the outbox's connection; two connections cannot be atomic, and the boot is refused
 * rather than quietly publishing after the fact.
 */
final readonly class EventSourcingSettings
{
    /** @var list<string> */
    public const array PROVIDERS = ['memory', 'database'];

    /**
     * @param  list<string>  $publishExcludedAggregateTypes
     */
    public function __construct(
        public string $storeProvider = 'memory',
        public ?string $connection = null,
        public int $batchSize = 500,
        public bool $snapshotEnabled = true,
        public string $snapshotStore = 'memory',
        public int $snapshotThreshold = 50,
        public int $snapshotKeepCount = 3,
        public string $checkpointStore = 'memory',
        public int $projectionBatchSize = 100,
        public float $leaseTtl = 30.0,
        public float $pollInterval = 1.0,
        public int $maxLag = 0,
        public bool $publishEnabled = false,
        public string $publishDestination = 'eventsourcing.events',
        public array $publishExcludedAggregateTypes = ['orchestration.execution'],
        public bool $tenancyEnabled = false,
        public bool $captureUser = true,
        public bool $healthEnabled = true,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $store = self::provider($config->string('firefly.eventsourcing.store.provider', 'memory'), 'firefly.eventsourcing.store.provider');
        $snapshotStore = $config->get('firefly.eventsourcing.snapshot.store');
        $checkpointStore = $config->get('firefly.eventsourcing.projection.checkpoint-store');
        $connection = $config->get('firefly.eventsourcing.store.connection');
        if ($connection !== null && (! is_string($connection) || $connection === '')) {
            throw new ConfigurationException('Configuration key [firefly.eventsourcing.store.connection] must be a connection name or null.');
        }

        $excluded = [];
        foreach ($config->array('firefly.eventsourcing.publish.exclude-aggregate-types', ['orchestration.execution']) as $type) {
            if (! is_string($type) || $type === '') {
                throw new ConfigurationException('Configuration key [firefly.eventsourcing.publish.exclude-aggregate-types] must be a list of aggregate types.');
            }
            $excluded[] = $type;
        }

        $destination = $config->string('firefly.eventsourcing.publish.destination', 'eventsourcing.events');
        if ($destination === '') {
            throw new ConfigurationException('Configuration key [firefly.eventsourcing.publish.destination] must not be empty.');
        }

        $settings = new self(
            storeProvider: $store,
            connection: $connection,
            batchSize: self::atLeast($config->int('firefly.eventsourcing.store.batch-size', 500), 1, 'firefly.eventsourcing.store.batch-size'),
            snapshotEnabled: $config->bool('firefly.eventsourcing.snapshot.enabled', true),
            snapshotStore: $snapshotStore === null || $snapshotStore === '' ? $store : self::provider(is_string($snapshotStore) ? $snapshotStore : get_debug_type($snapshotStore), 'firefly.eventsourcing.snapshot.store'),
            snapshotThreshold: self::atLeast($config->int('firefly.eventsourcing.snapshot.threshold', 50), 0, 'firefly.eventsourcing.snapshot.threshold'),
            snapshotKeepCount: self::atLeast($config->int('firefly.eventsourcing.snapshot.keep-count', 3), 1, 'firefly.eventsourcing.snapshot.keep-count'),
            checkpointStore: $checkpointStore === null || $checkpointStore === '' ? $store : self::provider(is_string($checkpointStore) ? $checkpointStore : get_debug_type($checkpointStore), 'firefly.eventsourcing.projection.checkpoint-store'),
            projectionBatchSize: self::atLeast($config->int('firefly.eventsourcing.projection.batch-size', 100), 1, 'firefly.eventsourcing.projection.batch-size'),
            leaseTtl: self::seconds($config->get('firefly.eventsourcing.projection.lease-ttl'), 30.0, 'firefly.eventsourcing.projection.lease-ttl', 1.0),
            pollInterval: self::seconds($config->get('firefly.eventsourcing.projection.poll-interval'), 1.0, 'firefly.eventsourcing.projection.poll-interval', 0.0),
            maxLag: self::atLeast($config->int('firefly.eventsourcing.projection.max-lag', 0), 0, 'firefly.eventsourcing.projection.max-lag'),
            publishEnabled: $config->bool('firefly.eventsourcing.publish.enabled', false),
            publishDestination: $destination,
            publishExcludedAggregateTypes: $excluded,
            tenancyEnabled: $config->bool('firefly.eventsourcing.tenancy.enabled', false),
            captureUser: $config->bool('firefly.eventsourcing.metadata.capture-user', true),
            healthEnabled: $config->bool('firefly.eventsourcing.health.enabled', true),
        );

        self::refuseSplitOutbox($config, $settings);

        return $settings;
    }

    public function usesDatabase(): bool
    {
        return in_array('database', [$this->storeProvider, $this->snapshotStore, $this->checkpointStore], true);
    }

    private static function refuseSplitOutbox(Config $config, self $settings): void
    {
        $eda = $config->string('firefly.eda.provider', 'memory');
        if (! $settings->publishEnabled || $settings->storeProvider !== 'database' || ! in_array($eda, ['database', 'postgres'], true)) {
            return;
        }

        $default = $config->get('database.default');
        $default = is_string($default) ? $default : null;
        $store = $settings->connection ?? $default;
        $outbox = DatabaseOutboxSettings::from($config)->connection ?? $default;

        if ($store !== $outbox) {
            throw new ConfigurationException(sprintf(
                'Configuration key [firefly.eventsourcing.store.connection] is [%s] but the %s outbox writes on [%s] '
                .'(firefly.eda.database.connection): events and their outbox rows must share one transaction, so put them on one connection.',
                $store ?? 'default',
                $eda,
                $outbox ?? 'default',
            ));
        }
    }

    private static function provider(string $value, string $key): string
    {
        if (! in_array($value, self::PROVIDERS, true)) {
            throw new ConfigurationException("Configuration key [{$key}] must be memory or database, got [{$value}].");
        }

        return $value;
    }

    private static function atLeast(int $value, int $minimum, string $key): int
    {
        if ($value < $minimum) {
            throw new ConfigurationException("Configuration key [{$key}] must be at least {$minimum}, got [{$value}].");
        }

        return $value;
    }

    /** A duration in the framework grammar ('250ms', '30s', '5m', 'PT1H') or a number of seconds; null is the default. */
    private static function seconds(mixed $value, float $default, string $key, float $minimum): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_int($value) || is_float($value)) {
            $seconds = (float) $value;
        } elseif (is_string($value)) {
            try {
                $seconds = Duration::parse($value);
            } catch (ConfigurationException $exception) {
                throw new ConfigurationException("Configuration key [{$key}] is not a duration this framework can parse. {$exception->getMessage()}", previous: $exception);
            }
        } else {
            throw new ConfigurationException("Configuration key [{$key}] must be a duration such as '30s', got ".get_debug_type($value).'.');
        }

        if (! is_finite($seconds) || $seconds < $minimum) {
            throw new ConfigurationException("Configuration key [{$key}] must be at least {$minimum} seconds, got [".var_export($value, true).'].');
        }

        return $seconds;
    }
}
