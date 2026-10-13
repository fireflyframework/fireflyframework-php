<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\EventSourcing\EventSourcingServiceProvider;
use Firefly\EventSourcing\EventSourcingWiringProvider;
use Firefly\EventSourcing\Manifest\EventSourcingManifest;
use Firefly\EventSourcing\Snapshot\MemorySnapshotStore;
use Firefly\EventSourcing\Snapshot\SnapshotStore;
use Firefly\EventSourcing\Store\EventStore;
use Firefly\EventSourcing\Store\MemoryEventStore;
use Firefly\EventSourcing\Store\TenantScopedEventStore;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Support\ServiceProvider;

/**
 * The two discovered providers boot over the real pipeline with nothing configured: installing the package is
 * inert until an application uses an event store.
 */
it('boots green with nothing configured', function (): void {
    $app = fireflyApplication([], [EventSourcingServiceProvider::class, EventSourcingWiringProvider::class]);

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class);
});

it('binds an empty event-sourcing manifest when the application names no scan paths', function (): void {
    $app = fireflyApplication([], [EventSourcingServiceProvider::class, EventSourcingWiringProvider::class]);

    expect($app->make(EventSourcingManifest::class)->isEmpty())->toBeTrue();
});

it('binds the memory store by default and refuses at boot a provider it does not know', function (): void {
    $app = fireflyApplication([], [EventSourcingServiceProvider::class, EventSourcingWiringProvider::class]);

    expect($app->make(EventStore::class))->toBeInstanceOf(MemoryEventStore::class)
        ->and($app->make(SnapshotStore::class))->toBeInstanceOf(MemorySnapshotStore::class)
        ->and(fn () => fireflyApplication(['firefly' => ['eventsourcing' => ['store' => ['provider' => 'mongo']]]], [EventSourcingServiceProvider::class, EventSourcingWiringProvider::class]))
        ->toThrow(ConfigurationException::class, 'firefly.eventsourcing.store.provider');
});

it('wraps the store in the tenant scope when tenancy is enabled', function (): void {
    $app = fireflyApplication(['firefly' => ['eventsourcing' => ['tenancy' => ['enabled' => true]]]], [EventSourcingServiceProvider::class, EventSourcingWiringProvider::class]);

    expect($app->make(EventStore::class))->toBeInstanceOf(TenantScopedEventStore::class);
});

it('loads the migrations only while an eventsourcing store is database, and publishes them under their tag', function (): void {
    // A recording migrator: loadMigrationsFrom() hands its paths to whatever 'migrator' resolves to.
    $migrator = static fn (): object => new class
    {
        /** @var list<string> */
        public array $paths = [];

        public function path(string $path): void
        {
            $this->paths[] = $path;
        }
    };
    $memory = $migrator();
    $database = $migrator();
    $memoryApp = fireflyApplication([], [EventSourcingServiceProvider::class, EventSourcingWiringProvider::class]);
    $databaseApp = fireflyApplication(['firefly' => ['eventsourcing' => ['snapshot' => ['store' => 'database']]]], [EventSourcingServiceProvider::class, EventSourcingWiringProvider::class]);
    // Bound after boot and resolved through make(), so the provider's after-resolving callback runs on it.
    $memoryApp->bind('migrator', static fn (): object => $memory);
    $databaseApp->bind('migrator', static fn (): object => $database);
    $memoryApp->make('migrator');
    $databaseApp->make('migrator');

    expect($memory->paths)->toBe([])
        ->and($database->paths)->toBe([EventSourcingWiringProvider::MIGRATIONS])
        ->and(ServiceProvider::pathsToPublish(EventSourcingWiringProvider::class, 'firefly-eventsourcing-migrations'))->toHaveKey(EventSourcingWiringProvider::MIGRATIONS);
});
