<?php

declare(strict_types=1);

namespace Firefly\Eda\Tests\Fixtures;

use Firefly\Eda\Consumer\EventConsumer;
use Firefly\Eda\Consumer\ReceivedEnvelope;
use Firefly\Eda\EventEnvelope;

/**
 * A scripted in-memory EventConsumer fake for ConsumerLoopTest / ConsumeEventsCommandTest: emits a fixed queue
 * of envelopes then nulls (simulating poll timeouts), and records every ack()/nack() delivery tag, the
 * destination list it was subscribed to, and start()/stop() calls — so the loop's bounded-termination, its
 * ack/nack routing, and the command's destination-routing contract can all be asserted without a real broker.
 */
final class ScriptedEventConsumer implements EventConsumer
{
    /** @var list<mixed> */
    public array $acked = [];

    /** @var list<mixed> */
    public array $nacked = [];

    /**
     * Every destination list handed to subscribe(), in call order — the command's routing contract is asserted on this.
     *
     * @var list<list<string>>
     */
    public array $subscribed = [];

    public bool $started = false;

    public bool $stopped = false;

    /**
     * @param  list<EventEnvelope>  $queue
     */
    public function __construct(private array $queue) {}

    /**
     * @param  list<string>  $destinations
     */
    public function subscribe(array $destinations): void
    {
        $this->subscribed[] = $destinations;
    }

    public function start(): void
    {
        $this->started = true;
    }

    public function poll(int $timeoutMs): ?ReceivedEnvelope
    {
        $envelope = array_shift($this->queue);

        return $envelope === null ? null : new ReceivedEnvelope($envelope, spl_object_id($envelope));
    }

    public function ack(ReceivedEnvelope $received): void
    {
        $this->acked[] = $received->deliveryTag;
    }

    public function nack(ReceivedEnvelope $received, bool $requeue = true): void
    {
        $this->nacked[] = $received->deliveryTag;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }
}
