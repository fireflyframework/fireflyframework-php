<?php

declare(strict_types=1);

namespace Lumen\Tests\Outbox;

use Firefly\Eda\Postgres\EdaPostgresServiceProvider;
use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Lumen\Tests\LumenTestCase;

/**
 * The SAME lumen boot harness as every other S-task test, but switched onto the GENUINE same-transaction Postgres
 * outbox: it adds firefly/eda-postgres's discovered auto-config provider and sets firefly.eda.provider=postgres. That
 * config flip (a test-local override — the default memory-path tests are untouched) makes
 * PostgresOutboxAutoConfiguration (#[Order(900)], #[ConditionalOnProperty(firefly.eda.provider=postgres)]) win the
 * container races: it binds a PostgresEventPublisher outbox writer, the OutboxPreCommitHook, and — over
 * DataAutoConfiguration's #[ConditionalOnMissingBean] — the HOOK-carrying DomainEventDispatcher, while NoOp'ing the
 * after-commit eda leg (double-publish avoidance). So a committed #[Transactional] handler's domain events are written
 * to firefly_eda_outbox IN the aggregate's own transaction, exactly once.
 *
 * The outbox table lives on the same sqlite :memory: connection as the wallets/ledger (created after the app
 * migrations), so the outbox INSERT enlists in the same transaction the handler runs on — which is what makes the
 * same-tx atomicity proof genuine on sqlite (no Postgres required; the pg_notify emit is driver-gated off).
 */
abstract class OutboxPostgresTestCase extends LumenTestCase
{
    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return [...parent::fireflyProviders(), EdaPostgresServiceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'firefly.eda.provider' => 'postgres'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The app migrations (wallets/ledger) ran in LumenTestCase::setUp; add the outbox table on the SAME
        // connection so its INSERT is enlisted in the handler's transaction (the same-tx atomicity under test).
        Schema::create(OutboxSchema::TABLE, fn (Blueprint $table) => OutboxSchema::blueprint($table));
    }
}
