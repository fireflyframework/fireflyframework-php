<?php

declare(strict_types=1);

namespace Firefly\Testing\Double;

use Firefly\Eda\EventPublisher;

/**
 * Records every publish() on the eda EventPublisher port — the M9 broker sink Laravel's Event::fake can't
 * see. $published keeps the exact shape the hand-rolled cqrs FakeEventPublisher used, so promoting it is
 * a drop-in swap.
 */
final class RecordingEventPublisher implements EventPublisher
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

    /**
     * @return list<array{destination: string, eventType: string, payload: array<string,mixed>, headers: array<string,string>}>
     */
    public function publishedOf(string $eventType): array
    {
        return array_values(array_filter($this->published, static fn (array $e): bool => $e['eventType'] === $eventType));
    }
}
