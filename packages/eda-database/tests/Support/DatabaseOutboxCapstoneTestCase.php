<?php

declare(strict_types=1);

namespace Firefly\Eda\Database\Tests\Support;

use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Data\Domain\AggregateTracker;
use Firefly\Eda\Database\EdaDatabaseServiceProvider;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\Eda\Listener\EventListenerManifest;
use Firefly\Eda\Scanner\EventListenerScanner;
use Firefly\Testing\FireflyDatabaseTestCase;
use Firefly\Testing\Fixture\ListenerSpy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;

/**
 * Boots the REAL provider=database stack — firefly/eda's auto-configuration and EventListenerWiringPass plus
 * firefly/eda-database's outbox auto-configuration — over sqlite, with one compiled #[EventListener]. A boot-level
 * capstone is the only test that can see "the row was published" and "a handler ran" disagree (see
 * CapstoneOutboxDeliveryTest in eda-postgres for the defect that taught this).
 */
abstract class DatabaseOutboxCapstoneTestCase extends FireflyDatabaseTestCase
{
    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [EdaServiceProvider::class, EdaWiringProvider::class, EdaDatabaseServiceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return ['firefly.eda.provider' => 'database'];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        $app->instance(EventListenerManifest::class, new EventListenerManifest(
            (new EventListenerScanner)->scan([
                'Firefly\\Eda\\Database\\Tests\\CapstoneFixtures\\' => dirname(__DIR__).'/CapstoneFixtures',
            ]),
        ));
        $app->singleton(ListenerSpy::class);

        // The bare app registers neither firefly/cqrs nor firefly/data; the outbox beans name their collaborators as
        // constructor parameters, so the empty real objects are supplied (never doubles).
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
