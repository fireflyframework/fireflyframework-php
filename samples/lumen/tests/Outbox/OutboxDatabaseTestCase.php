<?php

declare(strict_types=1);

namespace Lumen\Tests\Outbox;

use Firefly\Eda\Database\EdaDatabaseServiceProvider;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\Eda\EdaConsumerServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Lumen\Tests\LumenTestCase;

/**
 * The lumen boot harness on the PORTABLE same-transaction outbox: firefly/eda-database's auto-configuration plus
 * firefly.eda.provider=database. DatabaseOutboxAutoConfiguration (#[Order(900)]) then binds the outbox writer, the
 * OutboxPreCommitHook-carrying DomainEventDispatcher and a NoOp after-commit eda leg, so a committed #[Transactional]
 * handler's domain events are written to firefly_eda_outbox INSIDE the aggregate's transaction — on whatever
 * connection the aggregate uses — and reach the #[EventListener]s only when a consumer delivers them.
 *
 * EdaConsumerServiceProvider registers `firefly:eda:consume`, the long-running worker each test drives once with
 * --max-messages. The table is dropped first so a persistent server database (OutboxDatabaseIntegrationTestCase)
 * starts empty; on sqlite :memory: the drop is a no-op.
 */
abstract class OutboxDatabaseTestCase extends LumenTestCase
{
    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return [...parent::fireflyProviders(), EdaDatabaseServiceProvider::class, EdaConsumerServiceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'firefly.eda.provider' => 'database'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists(OutboxSchema::TABLE);
        Schema::create(OutboxSchema::TABLE, fn (Blueprint $table) => OutboxSchema::blueprint($table));
    }
}
