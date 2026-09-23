<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka\Tests\Fixtures;

use Firefly\Eda\Kafka\KafkaProducerClient;

/**
 * A pure-PHP KafkaProducerClient fake: records every produced record (topic, body, partition key) and every
 * lifecycle call, with no ext-rdkafka and no broker — the FakeKafkaConsumerClient precedent, on the produce side.
 * This is what lets the publisher's correctness core (the envelope it builds, the partition key it derives, and
 * the tracing headers it stamps) be unit-tested on a machine with no ext-rdkafka installed.
 */
final class FakeKafkaProducerClient implements KafkaProducerClient
{
    /** @var list<array{0: string, 1: string, 2: string}> every [topic, body, partitionKey], in order */
    public array $produced = [];

    /** @var list<int> the timeout of every flush() call, in order */
    public array $flushes = [];

    public int $connectCalls = 0;

    public function connect(): void
    {
        $this->connectCalls++;
    }

    public function produce(string $topic, string $body, string $partitionKey): void
    {
        $this->produced[] = [$topic, $body, $partitionKey];
    }

    public function flush(int $timeoutMs): void
    {
        $this->flushes[] = $timeoutMs;
    }

    /**
     * The decoded envelope of the $index-th produced record.
     *
     * @return array<string, mixed>
     */
    public function decoded(int $index = 0): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->produced[$index][1], true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
