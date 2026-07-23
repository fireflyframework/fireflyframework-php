<?php

declare(strict_types=1);

namespace Firefly\Messaging\DeadLetter;

use Closure;
use Firefly\Messaging\Message;
use Throwable;

/**
 * The messaging retry/DLQ decorator (pyfly messaging/error_handling.py wrap_listener parity, raw-bytes flavour).
 * ALWAYS wraps the handler in a retry loop: invoke it; on throw, retry up to $retries more times with LINEAR
 * backoff (attempt N sleeps retryDelay * N seconds); on the final failure, if $deadLetterTopic is set store the
 * message RE-KEYED to that topic (with x-original-topic + x-exception headers) into $dlq, else re-throw. With
 * $retries === 0 && $deadLetterTopic === null the loop runs the handler once and re-throws unchanged (same
 * observable result as a direct call; no unwrapped fast-path branch). A SEPARATE ~30-line helper from eda's
 * (envelope vs bytes payloads differ) — the locked siblings decision, faithful not copy-paste.
 */
final class RetryingMessageHandler
{
    /**
     * @return Closure(Message): void
     */
    public static function wrap(callable $handler, int $retries, float $retryDelay, ?string $deadLetterTopic, DeadLetterStore $dlq): Closure
    {
        return static function (Message $message) use ($handler, $retries, $retryDelay, $deadLetterTopic, $dlq): void {
            $attempt = 0;

            while (true) {
                try {
                    $handler($message);

                    return;
                } catch (Throwable $e) {
                    $attempt++;

                    if ($attempt > $retries) {
                        if ($deadLetterTopic === null) {
                            throw $e;
                        }

                        $dlq->store(
                            new Message(
                                $deadLetterTopic,
                                $message->value,
                                $message->key,
                                array_merge($message->headers, [
                                    'x-original-topic' => $message->topic,
                                    'x-exception' => $e->getMessage(),
                                ]),
                            ),
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
