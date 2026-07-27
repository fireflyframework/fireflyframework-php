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
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Postgres\EdaPostgresServiceProvider;
use Firefly\Eda\Postgres\Outbox\OutboxPreCommitHook;
use Firefly\Eda\Postgres\PostgresOutboxAutoConfiguration;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Application;

/**
 * (A) THE OVERRIDE-RACE COEXISTENCE GATE the I2 OutboxAtLeastOncePathTest cannot see. That test constructs
 * DomainEventDispatcher($tracker, $afterCommit, $hook) BY HAND, so it proves the hook path writes exactly one row —
 * but it never proves the CONTAINER RESOLVES the hook-carrying dispatcher under provider=postgres. This test boots
 * BOTH firefly/data (DataAutoConfiguration::domainEventDispatcher — the PLAIN dispatcher, gated
 * #[ConditionalOnMissingBean(DomainEventDispatcher::class)] at #[Order(1000)]) AND firefly/eda-postgres
 * (PostgresOutboxAutoConfiguration::domainEventDispatcher — the HOOK-carrying dispatcher, gated
 * #[ConditionalOnProperty(firefly.eda.provider=postgres)] at #[Order(900)]) through the REAL boot pipeline
 * (ConditionPassTwo over the committed manifests, NOT a hand-wired container). It mirrors the scheduling-postgres
 * LockProviderCoexistenceBootTest precedent T4 used.
 *
 * The race it closes: if the #[Order(900)] override were ever silently lost, a provider=postgres app would resolve
 * Data's PLAIN dispatcher (NO pre-commit hook), and with the after-commit eda leg NoOp'd by
 * PostgresOutboxAutoConfiguration::commandEventPublisher that means ZERO outbox rows AND NO error — a silent data
 * loss. The surviving-bean scan below fails loudly the instant the wrong dispatcher wins.
 */
function bootOutboxCoexistence(?string $provider): Application
{
    $eda = $provider === null ? [] : ['provider' => $provider];

    // A resolver over a real in-memory SQLite connection so the outbox beans (publisher/hook/consumer) are
    // constructible in the bare app. It is a genuine Illuminate Connection, so the driver check reads 'sqlite'.
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
        ['firefly' => ['eda' => $eda]],
        [DataServiceProvider::class, EdaPostgresServiceProvider::class],
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

/**
 * @return list<string> "FQCN::method" of every surviving auto-config #[Bean] method that returns
 *                      DomainEventDispatcher — after the condition pass has removed the loser's bean method.
 *                      Exactly one entry proves the race resolved to a single dispatcher (the other backed off).
 */
function survivingDomainEventDispatcherBeans(Application $app): array
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

it('resolves the HOOK-carrying DomainEventDispatcher (eda-postgres override wins Data) when provider = postgres', function () {
    $app = bootOutboxCoexistence('postgres');

    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    // The #[Order(900)] eda-postgres dispatcher wins the #[ConditionalOnMissingBean] race; Data backs off, so
    // EXACTLY ONE DomainEventDispatcher bean survives and it is the hook-carrying one. The OutboxPreCommitHook it
    // is built with is itself a registered bean, and the container resolves the dispatcher without error.
    expect(survivingDomainEventDispatcherBeans($app))->toBe([PostgresOutboxAutoConfiguration::class.'::domainEventDispatcher'])
        ->and($context->has(OutboxPreCommitHook::class))->toBeTrue()
        ->and($context->get(OutboxPreCommitHook::class))->toBeInstanceOf(OutboxPreCommitHook::class)
        ->and($context->get(DomainEventDispatcher::class))->toBeInstanceOf(DomainEventDispatcher::class);
});

it('resolves Data PLAIN DomainEventDispatcher with NO hook and NO outbox writer when provider is non-postgres', function () {
    $app = bootOutboxCoexistence('memory');

    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    // eda-postgres's #[ConditionalOnProperty] does not fire: Data's plain dispatcher provides (exactly one bean),
    // the pre-commit hook is NOT bound, and the outbox EventPublisher (writer) is NOT bound — so there is no
    // outbox write path at all for a non-postgres provider.
    expect(survivingDomainEventDispatcherBeans($app))->toBe([DataAutoConfiguration::class.'::domainEventDispatcher'])
        ->and($context->has(OutboxPreCommitHook::class))->toBeFalse()
        ->and($context->has(EventPublisher::class))->toBeFalse();
});
