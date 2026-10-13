<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Context\Boot\FireflyKernel;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Data\DataAutoConfiguration;
use Firefly\Data\DataServiceProvider;
use Firefly\Data\Domain\AggregateTracker;
use Firefly\Data\Domain\DomainEventDispatcher;
use Firefly\Eda\Database\DatabaseOutboxAutoConfiguration;
use Firefly\Eda\Database\EdaDatabaseServiceProvider;
use Firefly\Eda\Database\OutboxHealthIndicator;
use Firefly\Eda\Postgres\EdaPostgresServiceProvider;
use Firefly\Eda\Postgres\PostgresHealthIndicator;
use Firefly\Eda\Postgres\PostgresOutboxAutoConfiguration;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Application;

/**
 * Both outbox packages installed at once — the shape of every app, since the library ships them together — over the
 * REAL condition pass. For each provider value exactly ONE DomainEventDispatcher bean may survive: the hook-carrying
 * one of the selected outbox, or Data's plain one for any other provider. Two surviving would double-write; none
 * would silently lose the in-transaction write.
 */
function outboxCoexistenceApp(string $provider): Application
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:');
    $resolver = new class($connection) implements ConnectionResolverInterface
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
    };

    return fireflyApplication(
        ['firefly' => ['eda' => ['provider' => $provider]]],
        [DataServiceProvider::class, EdaDatabaseServiceProvider::class, EdaPostgresServiceProvider::class],
        bindings: [
            ConnectionResolverInterface::class => $resolver,
            HandlerManifest::class => new HandlerManifest([], []),
            CorrelationContext::class => new CorrelationContext,
            AggregateTracker::class => new AggregateTracker,
            ApplicationEventPublisher::class => new class implements ApplicationEventPublisher
            {
                public function publish(object $event): void {}
            },
        ],
    );
}

/** @return list<string> */
function outboxCoexistenceDispatchers(Application $app): array
{
    /** @var FireflyKernel $kernel */
    $kernel = $app->make(FireflyKernel::class);

    $beans = [];
    foreach ($kernel->context()->definitions->all() as $definition) {
        foreach ($definition->descriptor->beans as $bean) {
            if ($bean->returns === DomainEventDispatcher::class) {
                $beans[] = $definition->class().'::'.$bean->method;
            }
        }
    }
    sort($beans);

    return $beans;
}

it('lets exactly one dispatcher and one outbox health indicator survive per provider', function (string $provider, string $dispatcher, bool $database, bool $postgres) {
    $app = outboxCoexistenceApp($provider);
    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    expect(outboxCoexistenceDispatchers($app))->toBe([$dispatcher])
        ->and($context->has(OutboxHealthIndicator::class))->toBe($database)
        ->and($context->has(PostgresHealthIndicator::class))->toBe($postgres);
})->with([
    'database' => ['database', DatabaseOutboxAutoConfiguration::class.'::domainEventDispatcher', true, false],
    'postgres' => ['postgres', PostgresOutboxAutoConfiguration::class.'::domainEventDispatcher', false, true],
    'memory' => ['memory', DataAutoConfiguration::class.'::domainEventDispatcher', false, false],
]);
