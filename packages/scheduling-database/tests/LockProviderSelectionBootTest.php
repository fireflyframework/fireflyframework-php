<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Context\Boot\FireflyKernel;
use Firefly\Scheduling\Database\DatabaseLeaseLock;
use Firefly\Scheduling\Database\SchedulingDatabaseAutoConfiguration;
use Firefly\Scheduling\Database\SchedulingDatabaseServiceProvider;
use Firefly\Scheduling\Lock\CacheLock;
use Firefly\Scheduling\Lock\DistributedLock;
use Firefly\Scheduling\Lock\NoneLock;
use Firefly\Scheduling\Postgres\PgAdvisoryLock;
use Firefly\Scheduling\Postgres\PgAdvisoryLockAutoConfiguration;
use Firefly\Scheduling\Postgres\SchedulingPostgresServiceProvider;
use Firefly\Scheduling\SchedulingAutoConfiguration;
use Firefly\Scheduling\SchedulingServiceProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactoryContract;
use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Application;

/**
 * All three scheduling packages installed at once over the REAL condition pass: for every value of
 * firefly.scheduling.lock.provider exactly ONE DistributedLock bean survives, and it is the one the value names.
 * Registration order is deliberately the reverse of the winner's: the condition pass's (#[Order], FQCN) sort decides.
 */
function lockSelectionApp(?string $provider): Application
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:');

    return fireflyApplication(
        ['firefly' => ['scheduling' => $provider === null ? [] : ['lock' => ['provider' => $provider]]]],
        [SchedulingServiceProvider::class, SchedulingPostgresServiceProvider::class, SchedulingDatabaseServiceProvider::class],
        bindings: [
            CacheRepositoryContract::class => new CacheRepository(new ArrayStore),
            CacheFactoryContract::class => new class implements CacheFactoryContract
            {
                public function store($name = null)
                {
                    return new CacheRepository(new ArrayStore);
                }
            },
            ConnectionResolverInterface::class => new class($connection) implements ConnectionResolverInterface
            {
                public function __construct(private readonly SQLiteConnection $connection) {}

                public function connection($name = null): SQLiteConnection
                {
                    return $this->connection;
                }

                public function getDefaultConnection(): string
                {
                    return 'default';
                }

                public function setDefaultConnection($name): void {}
            },
        ],
    );
}

/** @return list<string> */
function lockSelectionBeans(Application $app): array
{
    /** @var FireflyKernel $kernel */
    $kernel = $app->make(FireflyKernel::class);

    $beans = [];
    foreach ($kernel->context()->definitions->all() as $definition) {
        foreach ($definition->descriptor->beans as $bean) {
            if ($bean->returns === DistributedLock::class) {
                $beans[] = $definition->class().'::'.$bean->method;
            }
        }
    }
    sort($beans);

    return $beans;
}

it('resolves exactly the lock the provider names', function (?string $provider, string $class, string $bean) {
    $app = lockSelectionApp($provider);
    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    // The exact class, not instanceof: every lock is final, and a dataset string is not a class-string to PHPStan.
    expect(get_debug_type($context->get(DistributedLock::class)))->toBe($class)
        ->and(lockSelectionBeans($app))->toBe([$bean]);
})->with([
    'database' => ['database', DatabaseLeaseLock::class, SchedulingDatabaseAutoConfiguration::class.'::databaseLeaseLock'],
    'postgres' => ['postgres', PgAdvisoryLock::class, PgAdvisoryLockAutoConfiguration::class.'::distributedLock'],
    'cache' => ['cache', CacheLock::class, SchedulingAutoConfiguration::class.'::distributedLock'],
    'none' => [null, NoneLock::class, SchedulingAutoConfiguration::class.'::distributedLock'],
]);
