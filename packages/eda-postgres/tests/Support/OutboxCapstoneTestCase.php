<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Tests\Support;

use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Data\Domain\AggregateTracker;
use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\Eda\Listener\EventListenerManifest;
use Firefly\Eda\Postgres\EdaPostgresServiceProvider;
use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Firefly\Eda\Scanner\EventListenerScanner;
use Firefly\Testing\FireflyDatabaseTestCase;
use Firefly\Testing\Fixture\ListenerSpy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;

/**
 * Boots the REAL provider=postgres stack — firefly/eda's auto-configuration + its EventListenerWiringPass AND
 * firefly/eda-postgres's outbox auto-configuration — over an in-memory sqlite connection, with one compiled
 * #[EventListener] in the manifest.
 *
 * Why a full boot and not a hand-wired container: every pre-existing unit test in this package constructed the
 * SubscriberRegistry ITSELF and subscribed to it by hand, which silently papered over the real wiring. In a booted
 * app, EventListenerWiringPass resolves the bound EventPublisher and calls subscribe() on IT — so a publisher whose
 * subscribe() drops the handler produces an application in which the outbox is written and drained correctly while
 * NO listener ever runs. Only a boot-level capstone can see that difference.
 */
abstract class OutboxCapstoneTestCase extends FireflyDatabaseTestCase
{
    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [
            EdaServiceProvider::class,
            EdaWiringProvider::class,
            EdaPostgresServiceProvider::class,
        ];
    }

    /**
     * Seeded BEFORE registration so the #[ConditionalOnProperty(firefly.eda.provider=postgres)] gates on the
     * outbox beans (which are evaluated at register time) actually fire.
     *
     * @return array<string, mixed>
     */
    protected function configOverrides(): array
    {
        return ['firefly.eda.provider' => 'postgres'];
    }

    /**
     * Compile the capstone listener manifest INLINE with the real scanner (exactly what `firefly:cache` emits) and
     * bind the ListenerSpy singleton — both before boot, so EventListenerWiringPass has something to subscribe and
     * the handler it resolves shares the spy the assertions read.
     */
    protected function defineFireflyEnvironment(Application $app): void
    {
        $app->instance(EventListenerManifest::class, new EventListenerManifest(
            (new EventListenerScanner)->scan([
                'Firefly\\Eda\\Postgres\\Tests\\CapstoneFixtures\\' => dirname(__DIR__).'/CapstoneFixtures',
            ]),
        ));
        $app->singleton(ListenerSpy::class);

        // The bare capstone app registers neither firefly/cqrs nor firefly/data, yet PostgresOutboxAutoConfiguration's
        // outboxPreCommitHook()/domainEventDispatcher() beans declare their collaborators as constructor parameters —
        // and the container resolves those the moment the configuration class is registered. Supplying the empty
        // real objects (never doubles) keeps the capstone about the DELIVERY path without pulling two more capability
        // packages into the boot.
        $app->instance(HandlerManifest::class, new HandlerManifest([], []));
        $app->instance(CorrelationContext::class, new CorrelationContext);
        $app->instance(AggregateTracker::class, new AggregateTracker);
        $app->instance(ApplicationEventPublisher::class, new class implements ApplicationEventPublisher
        {
            public function publish(object $event): void {}
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create(OutboxSchema::TABLE, fn (Blueprint $table) => OutboxSchema::blueprint($table));
    }
}
