<?php

declare(strict_types=1);

namespace Firefly\Messaging\Console;

use Firefly\Config\Config;
use Firefly\Messaging\Container\ListenerContainerBroker;
use Firefly\Messaging\MessageBrokerPort;
use Illuminate\Console\Command;

/**
 * The worker for the broker providers (firefly.messaging.provider=kafka|rabbitmq): polls the container broker one
 * delivery at a time until --max-messages or --time-limit, then stops the broker so its consumers leave their group
 * and its channel closes. `memory` delivers inside publish() and `queue` runs in `php artisan queue:work`, so for
 * those the command refuses with the reason rather than idling forever. Run it under a supervisor, as
 * firefly:eda:consume is: like its ConsumerLoop, a SIGINT/SIGTERM (when pcntl is loaded) is honored BETWEEN
 * deliveries, so a deploy's `kill` never interrupts a delivery mid-retry and the broker is still stopped cleanly.
 * The previous handlers are put back afterwards, so the command leaves the process's signal disposition as it found it.
 */
final class ConsumeMessagesCommand extends Command
{
    private bool $stopping = false;

    /** @var string */
    protected $signature = 'firefly:messaging:consume {--max-messages= : stop after N messages} {--time-limit= : stop after N seconds} {--poll-timeout=1000 : block ms per poll} {--sleep=0 : idle ms between empty polls}';

    /** @var string */
    protected $description = 'Consume the #[MessageListener] topics from the Kafka or RabbitMQ messaging broker.';

    public function handle(MessageBrokerPort $broker, Config $config): int
    {
        if (! $broker instanceof ListenerContainerBroker) {
            $provider = $config->string('firefly.messaging.provider', 'memory');
            $this->error("firefly.messaging.provider={$provider} has no consumer loop: memory delivers in process, queue runs in `php artisan queue:work`.");

            return self::FAILURE;
        }

        $maxMessages = $this->positive('max-messages');
        $timeLimit = $this->positive('time-limit');
        $pollTimeout = max(1, (int) $this->option('poll-timeout'));
        $sleep = max(0, (int) $this->option('sleep'));
        $deadline = $timeLimit === null ? null : microtime(true) + $timeLimit;
        $processed = 0;

        $this->info('firefly:messaging:consume — consuming.');

        $this->stopping = false;
        $previous = $this->installSignals();

        try {
            while (! $this->stopping && ($maxMessages === null || $processed < $maxMessages) && ($deadline === null || microtime(true) < $deadline)) {
                if ($broker->poll($pollTimeout)) {
                    $processed++;
                } elseif ($sleep > 0) {
                    usleep($sleep * 1000);
                }

                $this->dispatchSignals();
            }
        } finally {
            $broker->stop();
            $this->restoreSignals($previous);
        }

        $this->info("firefly:messaging:consume — processed {$processed} message(s).");

        return self::SUCCESS;
    }

    /**
     * @return array<int, mixed> the handlers that were installed before, by signal number
     */
    private function installSignals(): array
    {
        if (! function_exists('pcntl_signal') || ! function_exists('pcntl_signal_get_handler')) {
            return [];
        }

        $previous = [];
        $stop = function (): void {
            $this->stopping = true;
        };

        foreach ([SIGINT, SIGTERM] as $signal) {
            $previous[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, $stop);
        }

        return $previous;
    }

    private function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    /**
     * @param  array<int, mixed>  $previous
     */
    private function restoreSignals(array $previous): void
    {
        foreach ($previous as $signal => $handler) {
            if (is_callable($handler) || is_int($handler)) {
                pcntl_signal($signal, $handler);
            }
        }
    }

    private function positive(string $option): ?int
    {
        $value = $this->option($option);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
