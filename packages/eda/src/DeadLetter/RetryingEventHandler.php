<?php

declare(strict_types=1);

namespace Firefly\Eda\DeadLetter;

use Closure;
use Firefly\Eda\EventEnvelope;
use Throwable;

/**
 * Adapter-agnostic decorator both eda adapters apply to each subscribed handler (pyfly messaging/error_handling.py
 * wrap_listener parity, envelope flavour). ALWAYS wraps the handler in a retry loop: invoke it; on throw, retry up
 * to $retries more times with LINEAR backoff (attempt N, 1-indexed, sleeps retryDelay * N seconds before the next
 * try); on the final failure, dead-letter the envelope (enriched with x-original-topic + x-exception headers) into
 * $dlq if one is configured, else re-throw. With $retries === 0 && $dlq === null the loop runs the handler exactly
 * once and re-throws its exception unchanged — the same observable result as a direct call, but the handler is
 * still wrapped (there is deliberately NO unwrapped fast-path branch; YAGNI).
 */
final class RetryingEventHandler
{
    /**
     * @return Closure(EventEnvelope): void
     */
    public static function wrap(callable $handler, int $retries, float $retryDelay, ?DeadLetterStore $dlq): Closure
    {
        return static function (EventEnvelope $envelope) use ($handler, $retries, $retryDelay, $dlq): void {
            $attempt = 0;

            while (true) {
                try {
                    $handler($envelope);

                    return;
                } catch (Throwable $e) {
                    $attempt++;

                    if ($attempt > $retries) {
                        if ($dlq === null) {
                            throw $e;
                        }

                        $dlq->store(
                            $envelope->withHeaders([
                                'x-original-topic' => $envelope->destination,
                                'x-exception' => $e->getMessage(),
                            ]),
                            $e,
                        );

                        return;
                    }

                    if ($retryDelay > 0.0) {
                        usleep((int) ($retryDelay * $attempt * 1_000_000));
                    }
                }
            }
        };
    }
}
