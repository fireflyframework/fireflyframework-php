<?php

declare(strict_types=1);

namespace Firefly\Eda\Database\Tests\Fixtures;

use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use RuntimeException;

/** A distinct downstream broker for the relay: records every forward, or fails when told to. */
final class RecordingDownstream implements EventPublisher
{
    /** @var list<EventEnvelope> */
    public array $published = [];

    public bool $fail = false;

    public function subscribe(string $eventTypePattern, callable $handler): void {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        if ($this->fail) {
            throw new RuntimeException('downstream down');
        }

        $this->published[] = new EventEnvelope($eventType, $destination, $payload, $headers);
    }

    public function start(): void {}

    public function stop(): void {}
}
