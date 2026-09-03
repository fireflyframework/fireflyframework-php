<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Tests\Support;

use Firefly\Eda\Postgres\EdaPostgresBootServiceProvider;
use Illuminate\Contracts\Console\Kernel;

/**
 * The capstone app plus EdaPostgresBootServiceProvider, which is what registers firefly:outbox:relay through
 * package auto-discovery — so these tests drive the REAL Artisan command, not a hand-called handle().
 *
 * firefly.eda.postgres.relay.downstream_provider is deliberately NOT seeded here. RelayDownstream reads it inside
 * handle(), so each test sets exactly the (mis)configuration it is about, and the default state of the harness is
 * the unconfigured one.
 */
abstract class OutboxRelayCommandTestCase extends OutboxCapstoneTestCase
{
    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [...parent::fireflyProviders(), EdaPostgresBootServiceProvider::class];
    }

    /**
     * `--max-messages=0` makes the daemon loop relay exactly ONE batch and then trip its bound, so the command
     * terminates deterministically instead of sleeping between empty batches forever. Goes through Kernel::call()
     * rather than $this->artisan() for the same PHPStan reason firefly/eda's EdaConsumeCommandTestCase documents:
     * InteractsWithConsole::artisan() is declared `PendingCommand|int`.
     */
    protected function runRelay(): int
    {
        /** @var Kernel $kernel */
        $kernel = $this->app()->make(Kernel::class);

        return $kernel->call('firefly:outbox:relay', ['--max-messages' => 0]);
    }

    protected function relayOutput(): string
    {
        /** @var Kernel $kernel */
        $kernel = $this->app()->make(Kernel::class);

        return $kernel->output();
    }
}
