<?php

declare(strict_types=1);

namespace Firefly\Eda\Bus;

use Firefly\Eda\EventEnvelope;

/**
 * The pattern→handler registry both eda adapters compose. subscribe() records an (fnmatch pattern, handler) pair;
 * deliver() invokes every handler whose pattern matches the envelope's eventType, in subscription order. Handlers
 * are plain callables — any retry/DLQ wrapping is applied by the caller (the wiring pass) before subscribe(), so
 * this class stays policy-free and identical across the web process and the queue worker (which is what makes the
 * async adapter's worker-side match+invoke reconstruct the same listener set — see the async-delivery model note).
 */
final class SubscriberRegistry
{
    /** @var list<array{pattern: string, handler: callable}> */
    private array $subscribers = [];

    public function subscribe(string $pattern, callable $handler): void
    {
        $this->subscribers[] = ['pattern' => $pattern, 'handler' => $handler];
    }

    public function deliver(EventEnvelope $envelope): void
    {
        foreach ($this->subscribers as $subscriber) {
            if (fnmatch($subscriber['pattern'], $envelope->eventType)) {
                ($subscriber['handler'])($envelope);
            }
        }
    }
}
