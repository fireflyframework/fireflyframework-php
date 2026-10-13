<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Store;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Firefly\EventSourcing\Event\EventEnvelopeCodec;
use Firefly\EventSourcing\Event\NewEvent;
use Firefly\EventSourcing\Event\PayloadNormalizer;
use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Exception\ConcurrencyException;
use Firefly\EventSourcing\Exception\StreamTypeMismatchException;
use Firefly\EventSourcing\Publishing\EventStorePublisher;
use Firefly\EventSourcing\Publishing\NoOpEventStorePublisher;
use Firefly\EventSourcing\Upcasting\UpcasterChain;

/**
 * firefly.eventsourcing.store.provider=memory: one process, lost on exit. It is the reference behavior of the
 * contract, and it stores what the database stores: every event goes through the PayloadNormalizer and the
 * EventEnvelopeCodec on the way in and is decoded and upcast on the way out. A payload that works here therefore
 * works on a database too. readAll() matches its EventFilter against the stored, pre-upcast event and upcasts only
 * the matches, as the database's WHERE does. One process is its own commit order, so global positions are given at
 * append.
 */
final class MemoryEventStore implements EventStore
{
    /** @var array<string, list<array{event: StoredEvent, raw: string}>> */
    private array $streams = [];

    /** @var list<array{0: string, 1: int}> global position - 1 => [aggregate id, index in its stream] */
    private array $global = [];

    /**
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(
        private readonly EventEnvelopeCodec $codec = new EventEnvelopeCodec,
        private readonly UpcasterChain $upcasters = new UpcasterChain,
        private readonly EventStorePublisher $publisher = new NoOpEventStorePublisher,
        private readonly ?Closure $clock = null,
    ) {}

    public function append(string $aggregateId, string $aggregateType, array $events, int $expectedVersion = ExpectedVersion::ANY): array
    {
        if ($events === []) {
            return [];
        }

        $stream = $this->streams[$aggregateId] ?? [];
        $version = count($stream);
        if ($stream !== [] && $stream[0]['event']->aggregateType !== $aggregateType) {
            throw StreamTypeMismatchException::of($aggregateId, $stream[0]['event']->aggregateType, $aggregateType);
        }
        if ($expectedVersion !== ExpectedVersion::ANY && $expectedVersion !== $version) {
            throw ConcurrencyException::conflict($aggregateId, $expectedVersion, $version);
        }

        $recordedAt = $this->now();
        $records = [];
        $stored = [];
        foreach ($events as $event) {
            $normalized = new NewEvent($event->eventType, self::payload($event), $event->eventVersion, $event->metadata, $event->eventId, $event->occurredAt);
            $raw = $this->codec->encode($normalized, $aggregateType);
            $position = count($this->global) + count($stored) + 1;
            $storedEvent = new StoredEvent($event->eventId, $aggregateId, $aggregateType, ++$version, $position, $event->eventType, $event->eventVersion, $normalized->payload, $event->metadata, $event->occurredAt, $recordedAt);
            $stored[] = $storedEvent;
            $records[] = ['event' => $storedEvent, 'raw' => $raw];
        }

        $this->publisher->inTransaction(null, $stored);

        foreach ($records as $record) {
            $this->global[] = [$aggregateId, count($stream)];
            $stream[] = $record;
        }
        $this->streams[$aggregateId] = $stream;

        $this->publisher->afterTransaction(null, $stored);

        return $stored;
    }

    public function load(string $aggregateId, int $fromVersion = 1, ?int $toVersion = null): EventStream
    {
        $events = [];
        foreach ($this->streams[$aggregateId] ?? [] as $record) {
            $version = $record['event']->aggregateVersion;
            if ($version >= $fromVersion && ($toVersion === null || $version <= $toVersion)) {
                $events[] = $this->upcasters->apply($this->decode($record));
            }
        }

        return new EventStream($aggregateId, $events);
    }

    public function version(string $aggregateId): int
    {
        return count($this->streams[$aggregateId] ?? []);
    }

    public function exists(string $aggregateId): bool
    {
        return $this->version($aggregateId) > 0;
    }

    public function readAll(int $afterPosition = 0, int $limit = 500, ?EventFilter $filter = null): array
    {
        $events = [];
        for ($position = max(0, $afterPosition); $position < count($this->global) && count($events) < max(1, $limit); $position++) {
            [$aggregateId, $index] = $this->global[$position];
            $event = $this->decode($this->streams[$aggregateId][$index]);
            if ($filter === null || $filter->matches($event)) {
                $events[] = $this->upcasters->apply($event);
            }
        }

        return $events;
    }

    public function currentPosition(): int
    {
        return count($this->global);
    }

    public function isHealthy(): bool
    {
        return true;
    }

    /**
     * The event as the database would hand it back, before upcasting: the stored type, version and metadata the
     * EventFilter matches, exactly as the database store's WHERE does.
     *
     * @param  array{event: StoredEvent, raw: string}  $record
     */
    private function decode(array $record): StoredEvent
    {
        $decoded = $this->codec->decode($record['raw']);
        $event = $record['event'];

        return new StoredEvent($event->eventId, $event->aggregateId, $event->aggregateType, $event->aggregateVersion, $event->globalPosition, $event->eventType, $event->eventVersion, $decoded['payload'], $decoded['metadata'], $event->occurredAt, $event->recordedAt);
    }

    /** @return array<string, mixed> */
    private static function payload(NewEvent $event): array
    {
        /** @var array<string, mixed> $payload */
        $payload = PayloadNormalizer::normalize($event->payload);

        return $payload;
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock === null ? new DateTimeImmutable : ($this->clock)())->setTimezone(new DateTimeZone('UTC'));
    }
}
