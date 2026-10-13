<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka\Tests\Fixtures;

use Firefly\Eda\Kafka\Messaging\KafkaRecord;
use Firefly\Eda\Kafka\Messaging\KafkaRecordClient;
use Throwable;

/** A pure-PHP KafkaRecordClient: no ext-rdkafka, no socket — the FakeKafkaConsumerClient precedent, for raw records. */
final class FakeKafkaRecordClient implements KafkaRecordClient
{
    /** @var list<KafkaRecord> */
    public array $incoming = [];

    /** @var list<list<string>> */
    public array $subscriptions = [];

    /** @var list<KafkaRecord> */
    public array $committed = [];

    /** @var list<array{topic: string, value: string, key: string|null, headers: array<string, string>}> */
    public array $produced = [];

    public int $flushes = 0;

    public bool $closed = false;

    public ?Throwable $produceFailure = null;

    /**
     * @param  array<string, string>  $headers
     */
    public static function record(string $topic, string $value, ?string $key = null, array $headers = [], int $offset = 0): KafkaRecord
    {
        return new KafkaRecord($topic, $value, $key, $headers, 0, $offset, null);
    }

    public function subscribe(array $topics): void
    {
        $this->subscriptions[] = $topics;
    }

    public function consume(int $timeoutMs): ?KafkaRecord
    {
        return array_shift($this->incoming);
    }

    public function commit(KafkaRecord $record): void
    {
        $this->committed[] = $record;
    }

    public function produce(string $topic, string $value, ?string $key, array $headers): void
    {
        if ($this->produceFailure !== null) {
            throw $this->produceFailure;
        }

        $this->produced[] = ['topic' => $topic, 'value' => $value, 'key' => $key, 'headers' => $headers];
    }

    public function flush(int $timeoutMs): void
    {
        $this->flushes++;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
