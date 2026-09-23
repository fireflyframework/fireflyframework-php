<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Data\Domain\AggregateTracker;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Postgres\EdaPostgresServiceProvider;
use Firefly\Eda\Postgres\Outbox\OutboxPreCommitHook;
use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Firefly\Eda\Postgres\Tests\Fixtures\OutboxSampleEvent;
use Firefly\Eda\Postgres\Tests\Fixtures\StampingEdaTracing;
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Eda\Tracing\NoOpEdaTracing;
use Illuminate\Config\Repository;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Application;

/**
 * THE WIRING, NOT THE CLASSES.
 *
 * The three tracing suites added alongside this one construct the publishers BY HAND with an explicit $tracing
 * argument, which proves the classes route publish() through the seam and nothing at all about the branch the
 * auto-configuration takes. That branch is where the interesting failures live, and it had two:
 *
 *   1. Under firefly.eda.provider=postgres the bound EventPublisher bean is NOT the path a DomainEvent takes.
 *      commandEventPublisher() NoOps the after-commit leg precisely so the row is written exactly once, in-tx,
 *      by OutboxPreCommitHook — which built its own PostgresEventPublisher with four arguments and therefore got
 *      NoOpEdaTracing. Every flagship row, the ones that commit with the aggregate, carried no traceparent while
 *      the class docblock claimed the opposite. Only a booted container can see that.
 *   2. firefly.eda.tracing.brokers.enabled is a compliance control ("keep in-process spans, put no trace id on a
 *      wire someone else reads"), so "the key downgrades the bean to the NoOp" has to be pinned, not assumed.
 *
 * The app is built by hand rather than through firefly/testing's Testbench base for one reason the Testbench base
 * cannot give: the third case needs EdaTracing to be genuinely UNBOUND, which is impossible once firefly/eda's own
 * auto-configuration is registered (EdaAutoConfiguration::edaTracing() always binds at least the NoOp). Registering
 * eda-postgres ALONE is also the honest shape of the `?EdaTracing $tracing = null` parameter's justification.
 *
 * @return array{0: Application, 1: SQLiteConnection}
 */
function bootOutboxApp(?EdaTracing $tracing, ?bool $brokersEnabled = null): array
{
    $eda = ['provider' => 'postgres'];
    if ($brokersEnabled !== null) {
        $eda['tracing'] = ['brokers' => ['enabled' => $brokersEnabled]];
    }

    $app = new Application;
    $app->instance('config', new Repository(['firefly' => ['eda' => $eda]]));

    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:');
    $resolver = new ConnectionResolver(['testing' => $connection]);
    $resolver->setDefaultConnection('testing');
    $app->instance(ConnectionResolverInterface::class, $resolver);

    // The collaborators the outbox beans declare and this bare app does not otherwise have — real, empty objects,
    // never doubles, exactly as OutboxCapstoneTestCase supplies them.
    $app->instance(HandlerManifest::class, new HandlerManifest([], []));
    $app->instance(CorrelationContext::class, new CorrelationContext);
    $app->instance(AggregateTracker::class, new AggregateTracker);
    $app->instance(ApplicationEventPublisher::class, new class implements ApplicationEventPublisher
    {
        public function publish(object $event): void {}
    });

    if ($tracing !== null) {
        $app->instance(EdaTracing::class, $tracing);
    }

    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new EdaPostgresServiceProvider($app));
    $app->boot();

    $connection->getSchemaBuilder()->create(OutboxSchema::TABLE, fn (Blueprint $t) => OutboxSchema::blueprint($t));

    return [$app, $connection];
}

/** @return array<string, string> */
function outboxHeaders(SQLiteConnection $connection): array
{
    $headers = $connection->table(OutboxSchema::TABLE)->value('headers');
    if (! is_string($headers)) {
        throw new RuntimeException('Expected exactly one outbox row carrying a headers column.');
    }

    $decoded = json_decode($headers, true, 512, JSON_THROW_ON_ERROR);
    $map = [];
    foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
        $map[(string) $key] = is_scalar($value) ? (string) $value : '';
    }

    return $map;
}

it('hands the auto-configured EventPublisher bean the EdaTracing the app bound', function () {
    [$app, $connection] = bootOutboxApp(new StampingEdaTracing);

    /** @var EventPublisher $publisher */
    $publisher = $app->make(EventPublisher::class);
    $publisher->publish('order.events', 'order.created', ['id' => 1], ['x-correlation-id' => 'c1']);

    expect(outboxHeaders($connection))->toHaveKey('traceparent')
        ->and(outboxHeaders($connection)['x-correlation-id'])->toBe('c1');
});

it('stamps the IN-TRANSACTION row the pre-commit hook writes — the only path a DomainEvent takes', function () {
    [$app, $connection] = bootOutboxApp(new StampingEdaTracing);

    /** @var OutboxPreCommitHook $hook */
    $hook = $app->make(OutboxPreCommitHook::class);

    // The hook runs during the aggregate's pre-commit drain, so the row it writes commits WITH the aggregate.
    $connection->transaction(function () use ($hook): void {
        $hook->handle(new OutboxSampleEvent(7, 'PLACED'), null);
    });

    // Committed, and traced: the traceparent is on the row that is atomic with the aggregate, which is the whole
    // claim PostgresEventPublisher's docblock makes. Before the hook was given the seam this was absent.
    expect($connection->table(OutboxSchema::TABLE)->where('event_type', 'OutboxSampleEvent')->count())->toBe(1)
        ->and(outboxHeaders($connection))->toHaveKey('traceparent');
});

it('downgrades BOTH write paths to the NoOp when firefly.eda.tracing.brokers.enabled is false', function () {
    [$app, $connection] = bootOutboxApp(new StampingEdaTracing, brokersEnabled: false);

    /** @var EventPublisher $publisher */
    $publisher = $app->make(EventPublisher::class);
    $publisher->publish('order.events', 'order.created', ['id' => 1], ['x-correlation-id' => 'c1']);

    expect(outboxHeaders($connection))->toBe(['x-correlation-id' => 'c1']);

    $connection->table(OutboxSchema::TABLE)->delete();

    /** @var OutboxPreCommitHook $hook */
    $hook = $app->make(OutboxPreCommitHook::class);
    $connection->transaction(function () use ($hook): void {
        $hook->handle(new OutboxSampleEvent(7, 'PLACED'), null);
    });

    // The gate is a compliance control, so it has to hold on the in-tx path too — that is the row a relay would
    // later put on a third party's wire.
    expect(outboxHeaders($connection))->not->toHaveKey('traceparent');
});

it('resolves both beans in an app with no EdaTracing bound at all', function () {
    [$app, $connection] = bootOutboxApp(null);

    /** @var EventPublisher $publisher */
    $publisher = $app->make(EventPublisher::class);
    $publisher->publish('order.events', 'order.created', ['id' => 1]);

    /** @var OutboxPreCommitHook $hook */
    $hook = $app->make(OutboxPreCommitHook::class);
    $connection->transaction(function () use ($hook): void {
        $hook->handle(new OutboxSampleEvent(7, 'PLACED'), null);
    });

    // This is the justification for the nullable parameter in all three adapters' docblocks: an app that never
    // registered firefly/eda's own auto-configuration still boots, and the publishers fall back to the NoOp.
    expect($connection->table(OutboxSchema::TABLE)->count())->toBe(2)
        ->and((new ReflectionProperty($publisher, 'tracing'))->getValue($publisher))->toBeInstanceOf(NoOpEdaTracing::class);
});
