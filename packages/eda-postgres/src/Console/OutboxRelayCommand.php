<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Console;

use Firefly\Config\Config;
use Firefly\Eda\Postgres\Outbox\OutboxRelay;
use Firefly\Eda\Postgres\Outbox\RelayDownstream;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * OPTIONAL: forwards committed firefly_eda_outbox PENDING rows to a DISTINCT downstream broker selected by
 * firefly.eda.postgres.relay.downstream_provider. Terminal in-process delivery is NOT this command's job —
 * firefly:eda:consume owns that — so an app that only needs #[EventListener] handlers never runs this at all.
 *
 * WHAT CHANGED AND WHY. This command used to treat downstream_provider as a mere on/off flag and then resolve
 * EventPublisher::class from the container. Under firefly.eda.provider=postgres — the only provider that has an
 * outbox table to relay — that binding IS the outbox writer, so the guard below rejected the one thing the command
 * could ever resolve, and the relay could not be made to work under any configuration. Selection now goes through
 * RelayDownstream (see its docblock for the full resolution order and why the sibling adapter packages are
 * referenced as class-strings), which returns a REAL downstream publisher or throws a ConfigurationException
 * naming exactly what to change.
 *
 * Both failure modes are now loud. An UNSET key is no longer an exit-0 "no-op": running a relay with nothing to
 * relay to is a misconfiguration, and exiting 0 while forwarding nothing is precisely the silent behaviour that hid
 * the defect — so it exits FAILURE with a message pointing at firefly:eda:consume for the in-process case. A
 * MISCONFIGURED key (unknown name, package not installed, unconstructible class, wrong type, or the outbox writer
 * itself) is reported before a single row is claimed, so a failed relay can never mark rows PUBLISHED.
 */
final class OutboxRelayCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:outbox:relay {--max-messages= : stop after N published} {--time-limit= : stop after N seconds} {--sleep=1 : seconds between empty batches} {--batch-size=50}';

    /** @var string */
    protected $description = 'OPTIONAL: forward committed firefly_eda_outbox rows to the downstream broker named by firefly.eda.postgres.relay.downstream_provider (claim -> publish -> mark PUBLISHED/FAILED).';

    public function handle(ConnectionResolverInterface $connections, Container $container, Config $config): int
    {
        try {
            $downstream = RelayDownstream::resolve($container, $config);
        } catch (ConfigurationException $e) {
            // Reported as a clean console error rather than an escaping exception: the operator needs the remedy,
            // not a stack trace through the container.
            $this->error($e->getMessage());

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
