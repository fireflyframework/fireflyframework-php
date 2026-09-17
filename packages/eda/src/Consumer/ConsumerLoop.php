<?php

declare(strict_types=1);

namespace Firefly\Eda\Consumer;

use Firefly\Eda\EventEnvelope;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The broker-agnostic drive loop: start the consumer, then poll->sink->ack (nack+requeue on a sink throw) until a
 * bound trips (max-messages / time-limit) or a SIGINT/SIGTERM arrives. Signals are registered once via pcntl (with a
 * dispatch tick per poll) so a `kill`/Ctrl-C stops CLEANLY between messages — never mid-ack. The sink is an
 * EnvelopeSink (or a callable taking an EventEnvelope): by default the SubscriberRegistry deliver-callable, whose
 * handlers were already retry/DLQ-wrapped by EventListenerWiringPass; a throw reaching here means the handler
 * exhausted with no DLQ configured, so we nack+requeue (at-least-once).
 *
 * A POISON RECORD IS NACKED WITHOUT REQUEUE AND THE LOOP GOES ON. An adapter that cannot decode a record hands
 * over ReceivedEnvelope::poison() rather than throwing (see that class for the crash this replaces); the loop
 * never offers it to the sink — there is no envelope to offer — logs the destination and the failure, and
 * nacks it with requeue=false, which every adapter maps to its broker's dead-letter path (Kafka: the raw bytes
 * to `<topic>.DLT`, then commit; RabbitMQ: the queue's x-dead-letter-exchange). The record is counted as
 * processed, so a `--max-messages` bound still terminates a run that met nothing but poison.
 */
final class ConsumerLoop
{
    private bool $stopping = false;

    public function __construct(private readonly ?LoggerInterface $logger = null) {}

    /**
     * @param  EnvelopeSink|callable(EventEnvelope): void  $sink
     */
    public function run(EventConsumer $consumer, EnvelopeSink|callable $sink, ConsumerOptions $options): int
    {
        $this->stopping = false;
        $this->installSignals();

        $consumer->start();
        $processed = 0;
        $deadline = $options->timeLimit !== null ? time() + $options->timeLimit : null;

        try {
            while (! $this->stopping) {
                if ($options->maxMessages !== null && $processed >= $options->maxMessages) {
                    break;
                }
                if ($deadline !== null && time() >= $deadline) {
                    break;
                }

                $received = $consumer->poll($options->pollTimeoutMs);

                if ($received === null) {
                    if ($options->idleSleepMs > 0) {
                        usleep($options->idleSleepMs * 1000);
                    }
                    $this->dispatchSignals();

                    continue;
                }

                $envelope = $received->envelope;

                if ($envelope === null) {
                    $this->logger?->error('firefly:eda:consume — undeserialisable record dead-lettered.', [
                        'destination' => $received->destination,
                        'error' => $received->failure?->getMessage(),
                        'bytes' => strlen((string) $received->raw),
                    ]);
                    $consumer->nack($received, false);
                    $processed++;
                    $this->dispatchSignals();

                    continue;
                }

                try {
                    $sink instanceof EnvelopeSink ? $sink->handle($envelope) : $sink($envelope);
                    $consumer->ack($received);
                } catch (Throwable) {
                    $consumer->nack($received, true);
                }

                $processed++;
                $this->dispatchSignals();
            }
        } finally {
            $consumer->stop();
        }

        return $processed;
    }

    private function installSignals(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $stop = function (): void {
            $this->stopping = true;
        };
        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGTERM, $stop);
    }

    private function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
}
