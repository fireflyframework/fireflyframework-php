<?php

declare(strict_types=1);

namespace Firefly\Messaging\Container;

use Closure;
use Firefly\Messaging\Message;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs one listener on one delivery under a RetryPolicy: attempt, and after a failure sleep the back-off and attempt
 * again, until the handler returns or the attempts run out. Shared by both broker containers so Kafka and RabbitMQ
 * retry identically. The sleeper is injectable so unit tests assert the back-off instead of waiting it out; in a
 * worker it blocks, which is the documented trade-off (spec Decision 7): the consumer is single-threaded anyway, and
 * a delivery is acknowledged only once its attempts are over.
 */
final class ListenerInvoker
{
    /** @var Closure(float): void */
    private readonly Closure $sleep;

    /**
     * @param  (Closure(float): void)|null  $sleep
     */
    public function __construct(?Closure $sleep = null, private readonly ?LoggerInterface $logger = null)
    {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds > 0.0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
    }

    public function invoke(callable $handler, Message $message, RetryPolicy $policy): DeliveryOutcome
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $handler($message);

                return new DeliveryOutcome(true, $attempt);
            } catch (Throwable $e) {
                if ($attempt >= $policy->maxAttempts) {
                    return new DeliveryOutcome(false, $attempt, $e);
                }

                $delay = $policy->backOff->delayAfter($attempt);
                $this->logger?->warning("Listener on [{$message->topic}] failed attempt {$attempt}/{$policy->maxAttempts}; retrying in {$delay}s: {$e->getMessage()}");
                ($this->sleep)($delay);
            }
        }
    }
}
