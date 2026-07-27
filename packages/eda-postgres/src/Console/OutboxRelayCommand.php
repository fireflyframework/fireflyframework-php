<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Console;

use Firefly\Config\Config;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Postgres\Outbox\OutboxRelay;
use Firefly\Eda\Postgres\PostgresEventPublisher;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * OPTIONAL: forwards committed firefly_eda_outbox PENDING rows to a DISTINCT downstream broker configured via
 * firefly.eda.postgres.relay.downstream_provider (rabbitmq|kafka). If that key is unset, terminal Postgres delivers
 * in-process via firefly:eda:consume, so this command is a documented no-op. It NEVER resolves to PostgresEventPublisher
 * (that would re-insert PENDING rows) — the guard below + OutboxRelay's ctor guard both refuse it (B1).
 */
final class OutboxRelayCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:outbox:relay {--max-messages= : stop after N published} {--time-limit= : stop after N seconds} {--sleep=1 : seconds between empty batches} {--batch-size=50}';

    /** @var string */
    protected $description = 'OPTIONAL: forward committed firefly_eda_outbox rows to a downstream broker (claim -> publish -> mark PUBLISHED/FAILED). No-op unless firefly.eda.postgres.relay.downstream_provider is set.';

    public function handle(ConnectionResolverInterface $connections, Container $container, Config $config): int
    {
        $downstreamProvider = $config->has('firefly.eda.postgres.relay.downstream_provider')
            ? $config->string('firefly.eda.postgres.relay.downstream_provider')
            : null;

        if ($downstreamProvider === null) {
            $this->info('firefly:outbox:relay — no firefly.eda.postgres.relay.downstream_provider configured; terminal Postgres delivers in-process via firefly:eda:consume. No-op.');

            return self::SUCCESS;
        }

        /** @var EventPublisher $downstream */
        $downstream = $container->make(EventPublisher::class);
        if ($downstream instanceof PostgresEventPublisher) {
            $this->error("firefly.eda.postgres.relay.downstream_provider={$downstreamProvider} but the resolved EventPublisher is the Postgres outbox publisher itself — that would re-insert PENDING rows (infinite loop). Bind a real rabbitmq/kafka downstream EventPublisher for the relay.");

            return self::FAILURE;
        }

        $name = $config->has('firefly.eda.postgres.connection') ? $config->string('firefly.eda.postgres.connection') : null;
        $conn = $connections->connection($name);
        $useSkipLocked = $conn instanceof Connection && $conn->getDriverName() === 'pgsql'; // narrow ONLY to read the driver (B2)

        $relay = new OutboxRelay(
            $conn,
            $downstream,
            (int) $this->option('batch-size'),
            $config->int('firefly.eda.postgres.max_attempts', 3),
            $useSkipLocked,
        );

        $maxMessages = $this->option('max-messages');
        $timeLimit = $this->option('time-limit');
        $deadline = is_numeric($timeLimit) ? time() + (int) $timeLimit : null;
        $sleep = max(0, (int) $this->option('sleep'));
        $published = 0;

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $stop = false;
            pcntl_signal(SIGINT, function () use (&$stop): void {
                $stop = true;
            });
            pcntl_signal(SIGTERM, function () use (&$stop): void {
                $stop = true;
            });
        }

        while (true) {
            $n = $relay->relayBatch();
            $published += $n;

            if (($maxMessages !== null && is_numeric($maxMessages) && $published >= (int) $maxMessages)
                || ($deadline !== null && time() >= $deadline)
                || (isset($stop) && $stop)) {
                break;
            }
            if ($n === 0) {
                sleep($sleep);
            }
        }

        $this->info("firefly:outbox:relay — published {$published} row(s).");

        return self::SUCCESS;
    }
}
