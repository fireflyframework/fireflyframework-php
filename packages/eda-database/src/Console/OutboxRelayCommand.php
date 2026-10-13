<?php

declare(strict_types=1);

namespace Firefly\Eda\Database\Console;

use Firefly\Config\Config;
use Firefly\Eda\Database\DatabaseOutboxSettings;
use Firefly\Eda\Database\Outbox\Claim\OutboxClaimStrategies;
use Firefly\Eda\Database\Outbox\OutboxRelay;
use Firefly\Eda\Database\Outbox\RelayDownstream;
use Firefly\Eda\Tracing\BrokerTracing;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * OPTIONAL: forwards committed firefly_eda_outbox PENDING rows to a DISTINCT downstream broker selected by
 * firefly.eda.database.relay.downstream-provider (alias firefly.eda.postgres.relay.downstream_provider), under either
 * outbox provider. Terminal in-process delivery is firefly:eda:consume's job, so an app that only needs
 * #[EventListener] handlers never runs this.
 *
 * Every misconfiguration is loud and reported before a single row is claimed: an unset or unresolvable downstream
 * (RelayDownstream), an outbox connection that is not a concrete Connection, and a forced claim strategy the server
 * does not support (raised by the first claim, before any lease is written). Exiting FAILURE rather than 0 is
 * deliberate — a relay that forwards nothing must not look like a working one. Any number of relay processes may run:
 * each batch is leased to its relay.
 *
 * Not `final`: the deprecated Firefly\Eda\Postgres\Console\OutboxRelayCommand extends it.
 */
class OutboxRelayCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:outbox:relay {--max-messages= : stop after N published} {--time-limit= : stop after N seconds} {--sleep=1 : seconds between empty batches} {--batch-size=50}';

    /** @var string */
    protected $description = 'OPTIONAL: forward committed firefly_eda_outbox rows to the downstream broker named by firefly.eda.database.relay.downstream-provider (claim -> publish -> mark PUBLISHED/FAILED).';

    public function handle(ConnectionResolverInterface $connections, Container $container, Config $config): int
    {
        try {
            $downstream = RelayDownstream::resolve($container, $config);
            $settings = DatabaseOutboxSettings::from($config);

            $relay = new OutboxRelay(
                $settings->connectionFrom($connections),
                $downstream,
                OutboxClaimStrategies::configured($settings->claimStrategy),
                (int) $this->option('batch-size'),
                $settings->maxAttempts,
                // The UNGATED seam: it CONTINUES the traceparent the claimed row carries; RelayDownstream already gated
                // the downstream publisher on firefly.eda.tracing.brokers.enabled.
                BrokerTracing::bound($container),
                $settings->leaseSeconds,
            );

            return $this->drain($relay);
        } catch (ConfigurationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function drain(OutboxRelay $relay): int
    {
        $maxMessages = $this->option('max-messages');
        $timeLimit = $this->option('time-limit');
        $deadline = is_numeric($timeLimit) ? time() + (int) $timeLimit : null;
        $sleep = max(0, (int) $this->option('sleep'));
        $published = 0;
        $stop = false;

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
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
                || $stop) {
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
