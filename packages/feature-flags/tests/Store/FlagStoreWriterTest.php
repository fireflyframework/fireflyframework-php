<?php

declare(strict_types=1);

use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\FeatureFlags\Definition\FlagDefinitions;
use Firefly\FeatureFlags\Definition\InvalidFlagDefinition;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Event\FeatureFlagsChanged;
use Firefly\FeatureFlags\Event\FeatureFlagUpdated;
use Firefly\FeatureFlags\Registry\CacheBook;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\FeatureFlags\Source\FlagSource;
use Firefly\FeatureFlags\Source\SourceSnapshot;
use Firefly\FeatureFlags\Store\CommitAwareFlagStore;
use Firefly\FeatureFlags\Store\DatabaseFlagStore;
use Firefly\FeatureFlags\Store\FlagChange;
use Firefly\FeatureFlags\Store\FlagStore;
use Firefly\FeatureFlags\Store\FlagStoreWriter;
use Firefly\FeatureFlags\Store\MemoryFlagStore;
use Firefly\FeatureFlags\Store\StoredFlag;
use Firefly\FeatureFlags\Tests\Support\FlagStores;
use Firefly\FeatureFlags\Tests\Support\RecordingLogger;
use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Psr\Log\AbstractLogger;

/** @return array{FlagStoreWriter, FlagRegistry, RecordingApplicationEventPublisher} */
function featureFlagsWriterFixture(?FlagStore $store = null, ?Closure $failLoad = null): array
{
    $store ??= new MemoryFlagStore(FlagStores::clock());
    $source = new class($store, $failLoad) implements FlagSource
    {
        public function __construct(private readonly FlagStore $store, private readonly ?Closure $failLoad) {}

        public function name(): string
        {
            return self::STORE;
        }

        public function precedence(): int
        {
            return 400;
        }

        public function refreshInterval(): float
        {
            return 3600.0;
        }

        public function failsStartup(): bool
        {
            return false;
        }

        public function reportedRevision(?string $revision): ?string
        {
            return $revision;
        }

        public function load(?string $knownRevision): ?SourceSnapshot
        {
            if ($this->failLoad !== null && ($this->failLoad)()) {
                throw new RuntimeException('store unavailable');
            }
            $revision = (string) $this->store->revision();
            if ($revision === $knownRevision) {
                return null;
            }
            $flags = [];
            foreach ($this->store->all() as $stored) {
                $flags[$stored->key] = $stored->definition;
            }

            return new SourceSnapshot(FlagDefinitions::parseDocument(['flags' => Json::object($flags)]), $revision);
        }
    };
    $events = new RecordingApplicationEventPublisher;
    $registry = new FlagRegistry([$source], new CacheBook(new Repository(new ArrayStore), new RecordingLogger), $events);
    $registry->document();

    return [new FlagStoreWriter($store, $registry, $events), $registry, $events];
}

/**
 * @return list<array{name: string, action: string, faults: list<string>, expect: array{committed: bool, updatedAttempts: int, visible: string}}>
 */
function featureFlagsObserverVectors(): array
{
    $raw = file_get_contents(__DIR__.'/../Conformance/observer-vectors.json');
    if ($raw === false) {
        throw new RuntimeException('Observer vectors cannot be read.');
    }
    $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($data) || ! isset($data['cases']) || ! is_array($data['cases'])) {
        throw new RuntimeException('Observer vectors must contain cases.');
    }
    $cases = [];
    foreach ($data['cases'] as $case) {
        if (! is_array($case)) {
            throw new RuntimeException('Observer case must be an object.');
        }
        $name = $case['name'] ?? null;
        $action = $case['action'] ?? null;
        $faults = $case['faults'] ?? null;
        $expected = $case['expect'] ?? null;
        if (! is_string($name) || ! is_string($action) || ! is_array($faults) || ! is_array($expected)
            || ! is_bool($expected['committed'] ?? null) || ! is_int($expected['updatedAttempts'] ?? null)
            || ! is_string($expected['visible'] ?? null)) {
            throw new RuntimeException('Observer case has an invalid shape.');
        }
        $faultNames = [];
        foreach ($faults as $fault) {
            if (! is_string($fault)) {
                throw new RuntimeException('Observer fault must be a string.');
            }
            $faultNames[] = $fault;
        }
        $cases[] = ['name' => $name, 'action' => $action, 'faults' => $faultNames, 'expect' => [
            'committed' => $expected['committed'],
            'updatedAttempts' => $expected['updatedAttempts'],
            'visible' => $expected['visible'],
        ]];
    }

    return $cases;
}

it('validates, persists canonical JSON, refreshes and publishes after a memory commit', function (): void {
    [$writer, $registry, $events] = featureFlagsWriterFixture();
    $definition = ['state' => 'ENABLED', 'variants' => ['on' => (object) [], 'off' => (object) []], 'defaultVariant' => 'on'];
    $writer->put('0', $definition, 'ops');
    $definition['variants']['on']->changed = true;
    /** @var FeatureFlagUpdated $event */
    $event = $events->ofType(FeatureFlagUpdated::class)[0];
    $storedVariants = Json::members($writer->store()->get('0')?->definition['variants'] ?? null);
    $eventVariants = Json::members($event->current['variants'] ?? null);
    expect($registry->document()->keys())->toBe(['0'])
        ->and(Json::encode($storedVariants['on'] ?? null))->toBe('{}')
        ->and([$event->key, $event->action, $event->actor])->toBe(['0', 'put', 'ops'])
        ->and(Json::encode($eventVariants['on'] ?? null))->toBe('{}');
});

it('returns a committed write when refresh fails and still emits its update', function (): void {
    $failure = new class
    {
        public bool $enabled = false;
    };
    [$writer, $registry, $events] = featureFlagsWriterFixture(failLoad: static fn (): bool => $failure->enabled);
    $failure->enabled = true;
    $change = $writer->put('k', ['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on'], 'ops');
    expect($change->id)->toBe(1)
        ->and($writer->store()->revision())->toBe(1)
        ->and($registry->document()->flag('k'))->toBeNull()
        ->and($events->ofType(FeatureFlagUpdated::class))->toHaveCount(1);
});

it('keeps a committed change when post-commit observers fail, for each shared vector', function (): void {
    foreach (featureFlagsObserverVectors() as $case) {
        $faults = $case['faults'];
        $store = new MemoryFlagStore(FlagStores::clock());
        $definition = ['state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'on'];
        if ($case['action'] === 'delete') {
            $store->put('k', $definition, 'seed');
        }
        $source = new class($store, $faults) implements FlagSource
        {
            public bool $fail = false;

            /** @param list<string> $faults */
            public function __construct(private readonly FlagStore $store, private readonly array $faults) {}

            public function name(): string
            {
                return self::STORE;
            }

            public function precedence(): int
            {
                return 400;
            }

            public function refreshInterval(): float
            {
                return 3600.0;
            }

            public function failsStartup(): bool
            {
                return false;
            }

            public function reportedRevision(?string $revision): ?string
            {
                return $revision;
            }

            public function load(?string $knownRevision): SourceSnapshot
            {
                if ($this->fail && (in_array('source_error', $this->faults, true) || in_array('refresh_refused', $this->faults, true) || in_array('logger', $this->faults, true) || in_array('refresh_throw', $this->faults, true))) {
                    throw new RuntimeException('source refused');
                }
                $flags = [];
                foreach ($this->store->all() as $stored) {
                    $flags[$stored->key] = $stored->definition;
                }

                return new SourceSnapshot(FlagDefinitions::parseDocument(['flags' => Json::object($flags)]), (string) $this->store->revision());
            }
        };
        $attempts = [];
        $record = static function (string $type) use (&$attempts): void {
            $attempts[] = $type;
        };
        $events = new class($record, $faults) implements ApplicationEventPublisher
        {
            /** @param list<string> $faults */
            public function __construct(private readonly Closure $record, private readonly array $faults) {}

            public function publish(object $event): void
            {
                ($this->record)($event::class);
                if (($event instanceof FeatureFlagsChanged && in_array('change_listener', $this->faults, true)) || ($event instanceof FeatureFlagUpdated && in_array('update_listener', $this->faults, true))) {
                    throw new RuntimeException('listener failed');
                }
            }
        };
        $warnings = new RecordingLogger;
        $throwingLogger = new class extends AbstractLogger
        {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('logger failed');
            }
        };
        $registry = new FlagRegistry([$source], new CacheBook(new Repository(new ArrayStore), $warnings), $events, in_array('refresh_throw', $faults, true) ? $throwingLogger : $warnings);
        $registry->document();
        $source->fail = true;
        $writer = new FlagStoreWriter($store, $registry, $events, in_array('logger', $faults, true) ? $throwingLogger : $warnings);
        $change = $case['action'] === 'put' ? $writer->put('k', $definition, 'ops') : $writer->delete('k', 'ops');

        $previousVisible = $case['expect']['visible'] === 'previous';
        expect($change !== null)->toBe($case['expect']['committed'], $case['name'])
            ->and($change?->action)->toBe($case['action'], $case['name'])
            ->and($store->revision())->toBe($case['action'] === 'put' ? 1 : 2, $case['name'])
            ->and($registry->document()->flag('k')?->defaultVariant())->toBe($previousVisible ? ($case['action'] === 'delete' ? 'on' : null) : ($case['action'] === 'put' ? 'on' : null), $case['name'])
            ->and(count(array_filter($attempts, static fn (string $type): bool => $type === FeatureFlagUpdated::class)))->toBe($case['expect']['updatedAttempts'], $case['name']);
    }
});

it('defers refresh and event through a transaction-aware store callback', function (): void {
    $store = new class implements CommitAwareFlagStore, FlagStore
    {
        private MemoryFlagStore $inner;

        /** @var list<Closure(): void> */
        private array $callbacks = [];

        public function __construct()
        {
            $this->inner = new MemoryFlagStore(FlagStores::clock());
        }

        public function all(): array
        {
            return $this->inner->all();
        }

        public function get(string $key): ?StoredFlag
        {
            return $this->inner->get($key);
        }

        public function revision(): int
        {
            return $this->inner->revision();
        }

        public function put(string $key, array $definition, ?string $actor, ?int $expectedVersion = null): FlagChange
        {
            return $this->inner->put($key, $definition, $actor, $expectedVersion);
        }

        public function delete(string $key, ?string $actor, ?int $expectedVersion = null): ?FlagChange
        {
            return $this->inner->delete($key, $actor, $expectedVersion);
        }

        public function history(string $key, int $limit = 50): array
        {
            return $this->inner->history($key, $limit);
        }

        public function afterCommit(Closure $callback): void
        {
            $this->callbacks[] = $callback;
        }

        public function commit(): void
        {
            foreach ($this->callbacks as $callback) {
                $callback();
            }
            $this->callbacks = [];
        }
    };
    $failure = new class
    {
        public bool $enabled = false;
    };
    [$writer, $registry, $events] = featureFlagsWriterFixture($store, static fn (): bool => $failure->enabled);
    $writer->put('k', ['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on'], 'ops');
    expect($registry->document()->flag('k'))->toBeNull()
        ->and($events->ofType(FeatureFlagUpdated::class))->toBe([]);
    $store->commit();
    expect($registry->document()->flag('k')?->defaultVariant())->toBe('on')
        ->and($events->ofType(FeatureFlagUpdated::class))->toHaveCount(1);
    $writer->put('k', ['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on'], 'ops');
    $failure->enabled = true;
    $store->commit();
    expect($events->ofType(FeatureFlagUpdated::class))->toHaveCount(2)
        ->and($store->revision())->toBe(2);
});

it('rejects invalid definitions before writing and only publishes actual deletes', function (): void {
    [$writer, $registry, $events] = featureFlagsWriterFixture();
    expect(fn () => $writer->put('k', ['state' => 'ON'], 'ops'))->toThrow(InvalidFlagDefinition::class, 'state must be ENABLED or DISABLED')
        ->and($writer->store()->revision())->toBe(0);
    $writer->put('k', ['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on'], 'ops');
    $writer->delete('k', 'ops');
    expect($writer->delete('k', 'ops'))->toBeNull()
        ->and($registry->document()->flag('k'))->toBeNull()
        ->and(array_map(static fn (object $event): string => $event instanceof FeatureFlagUpdated ? $event->action : '', $events->ofType(FeatureFlagUpdated::class)))->toBe(['put', 'delete']);
});

it('refreshes and publishes SQL writes only at their root commit', function (bool $rollback, string $action): void {
    $connection = FlagStores::sqlite();
    $store = new DatabaseFlagStore($connection, FlagStores::clock());
    $definition = ['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on'];
    if ($action === 'delete') {
        $store->put('k', $definition, 'ada');
    }
    [$writer, $registry, $events] = featureFlagsWriterFixture($store);
    $connection->beginTransaction();
    $change = $action === 'put' ? $writer->put('k', $definition, 'ada') : $writer->delete('k', 'ada');
    expect($change?->action)->toBe($action)
        ->and($registry->document()->flag('k')?->defaultVariant())->toBe($action === 'delete' ? 'on' : null)
        ->and($events->ofType(FeatureFlagUpdated::class))->toBe([]);
    if ($rollback) {
        $connection->rollBack();
    } else {
        $connection->commit();
    }
    expect($events->ofType(FeatureFlagUpdated::class))->toHaveCount($rollback ? 0 : 1)
        ->and($registry->document()->flag('k')?->defaultVariant())->toBe(($action === 'put') !== $rollback ? 'on' : null);
})->with([false, true])->with(['put', 'delete']);
