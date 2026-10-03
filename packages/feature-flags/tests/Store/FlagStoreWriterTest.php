<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\FlagDefinitions;
use Firefly\FeatureFlags\Definition\InvalidFlagDefinition;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Event\FeatureFlagUpdated;
use Firefly\FeatureFlags\Registry\CacheBook;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\FeatureFlags\Source\FlagSource;
use Firefly\FeatureFlags\Source\SourceSnapshot;
use Firefly\FeatureFlags\Store\CommitAwareFlagStore;
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

it('reports a committed write when refresh fails and still emits its update', function (): void {
    $failure = new class
    {
        public bool $enabled = false;
    };
    [$writer, $registry, $events] = featureFlagsWriterFixture(failLoad: static fn (): bool => $failure->enabled);
    $failure->enabled = true;
    expect(fn () => $writer->put('k', ['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on'], 'ops'))
        ->toThrow(RuntimeException::class, 'write committed, but the registry refresh failed')
        ->and($writer->store()->revision())->toBe(1)
        ->and($registry->document()->flag('k'))->toBeNull()
        ->and($events->ofType(FeatureFlagUpdated::class))->toHaveCount(1);
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
    [$writer, $registry, $events] = featureFlagsWriterFixture($store);
    $writer->put('k', ['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on'], 'ops');
    expect($registry->document()->flag('k'))->toBeNull()
        ->and($events->ofType(FeatureFlagUpdated::class))->toBe([]);
    $store->commit();
    expect($registry->document()->flag('k')?->defaultVariant())->toBe('on')
        ->and($events->ofType(FeatureFlagUpdated::class))->toHaveCount(1);
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
