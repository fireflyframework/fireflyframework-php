<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Data\Domain\AggregateTracker;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Postgres\EdaPostgresServiceProvider;
use Firefly\Eda\Postgres\PostgresHealthIndicator;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\SQLiteConnection;

/**
 * Regression gate for the SP-4 "surprise /actuator/health 503" precedent (see RabbitMqHealthIndicatorGatingTest):
 * installing firefly/eda-postgres must stay fully INERT — PostgresHealthIndicator AND the outbox beans (the
 * EventPublisher) must NOT be registered — unless firefly.eda.provider=postgres is the active provider. Without
 * this, an app that installs the package for a prod outbox but runs a different provider (memory/rabbitmq) would
 * get a postgres HealthIndicator aggregated into /actuator/health for a broker it isn't using. Drives the REAL
 * boot pipeline (ConditionPassTwo + EagerSingletonsPass over the committed manifests), not a hand-wired container.
 *
 * The cqrs/data collaborators the outbox beans depend on are bound as instances so EagerSingletonsPass can resolve
 * the provider=postgres beans (the scheduling-postgres LockProviderCoexistenceBootTest precedent) — a real app has
 * them from the full cqrs/data stack; here we supply the minimum menu.
 */
function bootEdaPostgres(?string $provider): ApplicationContext
{
    $eda = $provider === null ? [] : ['provider' => $provider];

    // A resolver over a real in-memory SQLite connection so the outbox publisher + health indicator are
    // constructible in the bare app (no DatabaseServiceProvider). It is a genuine Illuminate Connection, so the
    // driver check reads 'sqlite' and emitNotify stays off.
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

    return bootFireflyApp(
        ['firefly' => ['eda' => $eda]],
        [EdaPostgresServiceProvider::class],
        [
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

it('does not register PostgresHealthIndicator or the outbox EventPublisher when provider = memory', function () {
    $context = bootEdaPostgres('memory');

    expect($context->has(PostgresHealthIndicator::class))->toBeFalse()
        ->and($context->has(EventPublisher::class))->toBeFalse();
});

it('does not register PostgresHealthIndicator or the outbox EventPublisher when firefly.eda.provider is absent', function () {
    $context = bootEdaPostgres(null);

    expect($context->has(PostgresHealthIndicator::class))->toBeFalse()
        ->and($context->has(EventPublisher::class))->toBeFalse();
});

it('registers PostgresHealthIndicator and the outbox EventPublisher when provider = postgres', function () {
    $context = bootEdaPostgres('postgres');

    expect($context->has(PostgresHealthIndicator::class))->toBeTrue()
        ->and($context->get(PostgresHealthIndicator::class))->toBeInstanceOf(PostgresHealthIndicator::class)
        ->and($context->has(EventPublisher::class))->toBeTrue();
});
