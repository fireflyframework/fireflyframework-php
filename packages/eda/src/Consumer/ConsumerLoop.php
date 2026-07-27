<?php

declare(strict_types=1);

namespace Firefly\Eda\Consumer;

use Throwable;

/**
 * The broker-agnostic drive loop: start the consumer, then poll->sink->ack (nack+requeue on a sink throw) until a
 * bound trips (max-messages / time-limit) or a SIGINT/SIGTERM arrives. Signals are registered once via pcntl (with a
 * dispatch tick per poll) so a `kill`/Ctrl-C stops CLEANLY between messages — never mid-ack. The sink is the
 * SubscriberRegistry deliver-callable, whose handlers were already retry/DLQ-wrapped by EventListenerWiringPass; a
 * throw reaching here means the handler exhausted with no DLQ configured, so we nack+requeue (at-least-once).
 */
final class ConsumerLoop
{
    private bool $stopping = false;

    public function run(EventConsumer $consumer, callable $sink, ConsumerOptions $options): int
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

                try {
                    ($sink)($received->envelope);
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
