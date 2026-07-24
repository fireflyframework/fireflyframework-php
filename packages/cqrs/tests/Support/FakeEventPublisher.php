<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\Support;

use Firefly\Eda\EventPublisher;

/** The M9 broker sink for the capstone: records every publish() so the bridge's re-emission can be asserted. */
final class FakeEventPublisher implements EventPublisher
{
    /** @var list<array{destination: string, eventType: string, payload: array<string,mixed>, headers: array<string,string>}> */
    public array $published = [];

    public function subscribe(string $eventTypePattern, callable $handler): void {}

    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        $this->published[] = compact('destination', 'eventType', 'payload', 'headers');
    }

    public function start(): void {}

    public function stop(): void {}
}
