<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Support;

use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Data\Domain\AggregateTracker;
use Firefly\Eda\Database\EdaDatabaseServiceProvider;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;

/**
 * The capstone with firefly.eda.provider=database and publishing on: the bound EventPublisher is the outbox writer,
 * so appended events must land in firefly_eda_outbox in the append's own transaction.
 */
abstract class OutboxPublishingCapstoneTestCase extends EventSourcingCapstoneTestCase
{
    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [...parent::fireflyProviders(), EdaDatabaseServiceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'firefly.eda.provider' => 'database', 'firefly.eventsourcing.publish.enabled' => true, 'firefly.eventsourcing.publish.destination' => 'orders'];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);
        // The bare app registers neither firefly/cqrs nor firefly/data; the outbox beans take these as parameters.
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

        Schema::dropIfExists(OutboxSchema::TABLE);
        Schema::create(OutboxSchema::TABLE, fn (Blueprint $table) => OutboxSchema::blueprint($table));
    }
}
