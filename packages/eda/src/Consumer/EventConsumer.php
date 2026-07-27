<?php

declare(strict_types=1);

namespace Firefly\Eda\Consumer;

/**
 * The run-until-stopped consume PORT M9's EventPublisher never had (start()/stop() existed; a poll loop did not).
 * A broker adapter (SP-4) implements this; ConsumerLoop drives it and firefly:eda:consume hosts it. PHP-FPM has no
 * in-process event loop, so consumption is a separate long-running process — this is the honest map of the
 * references' in-process asyncio/Spring listener containers onto Laravel.
 */
interface EventConsumer
{
    /**
     * Bind to the concrete broker destinations (topics/queues/channels) derived from the compiled manifest.
     *
     * @param  list<string>  $destinations
     */
    public function subscribe(array $destinations): void;

    public function start(): void;

    /** Block up to $timeoutMs for one message; null on timeout (no message). */
    public function poll(int $timeoutMs): ?ReceivedEnvelope;

    public function ack(ReceivedEnvelope $received): void;

    public function nack(ReceivedEnvelope $received, bool $requeue = true): void;

    public function stop(): void;
}
