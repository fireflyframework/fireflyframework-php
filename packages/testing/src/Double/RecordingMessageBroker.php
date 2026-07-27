<?php

declare(strict_types=1);

namespace Firefly\Testing\Double;

use Firefly\Messaging\MessageBrokerPort;

/** Records every publish() on the raw-bytes broker port. */
final class RecordingMessageBroker implements MessageBrokerPort
{
    /** @var list<array{topic: string, value: string, key: string|null, headers: array<string,string>}> */
    public array $published = [];

    public function publish(string $topic, string $value, ?string $key = null, array $headers = []): void
    {
        $this->published[] = compact('topic', 'value', 'key', 'headers');
    }

    public function subscribe(string $topic, callable $handler, ?string $group = null): void {}

    public function start(): void {}

    public function stop(): void {}

    /**
     * @return list<array{topic: string, value: string, key: string|null, headers: array<string,string>}>
     */
    public function publishedTo(string $topic): array
    {
        return array_values(array_filter($this->published, static fn (array $m): bool => $m['topic'] === $topic));
    }
}
