<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Orchestration;

use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Aggregate\EventSourcedAggregateRoot;
use Firefly\EventSourcing\Aggregate\SnapshotAware;
use Firefly\EventSourcing\Orchestration\Event\ExecutionCheckpointed;
use Firefly\EventSourcing\Orchestration\Event\ExecutionDeleted;
use Firefly\EventSourcing\Orchestration\Event\ExecutionStarted;
use Firefly\EventSourcing\Orchestration\Event\ExecutionStatusChanged;
use Firefly\EventSourcing\Orchestration\Event\SearchAttributesChanged;
use Firefly\EventSourcing\Orchestration\Event\StepResultRecorded;
use Firefly\EventSourcing\Orchestration\Event\StepStatusChanged;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Persistence\StateSerializer;

/**
 * One orchestration execution as an event-sourced aggregate (jafly's OrchestrationAggregate): its id is the
 * correlation id, its state is the ExecutionState::toArray() map, kept in the JSON-canonical form StateSerializer
 * writes.
 *
 * checkpoint() turns the engines' "save this state" into events. The first checkpoint is one `started` with the
 * whole state. Every later one is a diff against the current state:
 *
 *  - `status-changed` when the status or a clock field moved;
 *  - one `step-status-changed` per step whose status moved;
 *  - one `step-result-recorded` per step whose result moved;
 *  - `search-attributes-changed` when the attributes changed;
 *  - then, as the LOSSLESS FALLBACK, one `checkpointed` with every top-level field the events above did not
 *    reproduce exactly (a variable, a timer, a reordered map, a removed key).
 *
 * The diff applies each event to a working copy with the same fold() replay uses, so replaying the stream always
 * gives back exactly the state that was saved. Nothing is recorded when the state did not change. delete() appends
 * a tombstone; a checkpoint after it starts the execution again.
 */
final class ExecutionAggregate extends EventSourcedAggregateRoot implements SnapshotAware
{
    private const array STATUS_FIELDS = ['status', 'updatedAt', 'completedAt', 'wakeAt'];

    /** @var array<string, mixed>|null */
    private ?array $state = null;

    private bool $deleted = false;

    public static function aggregateType(): string
    {
        return OrchestrationEventTypes::AGGREGATE_TYPE;
    }

    public function state(): ?ExecutionState
    {
        return $this->state === null || $this->deleted ? null : ExecutionState::fromArray($this->state);
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function checkpoint(ExecutionState $state): void
    {
        $new = StateSerializer::decodeArray(StateSerializer::encode($state));
        if ($this->state === null || $this->deleted) {
            $this->recordThat(new ExecutionStarted(StateSerializer::encodeArray($new)));

            return;
        }

        $old = $this->state;
        if ($old === $new) {
            return;
        }

        $working = $old;
        $events = [];

        if (self::differs($old, $new, self::STATUS_FIELDS)) {
            $completedAt = $new['completedAt'] ?? null;
            $wakeAt = $new['wakeAt'] ?? null;
            self::then($working, $events, new ExecutionStatusChanged(
                self::text($new['status'] ?? null),
                self::text($new['updatedAt'] ?? null),
                is_string($completedAt) ? $completedAt : null,
                is_string($wakeAt) ? $wakeAt : null,
            ));
        }

        $oldStatuses = self::map($old['stepStatuses'] ?? null);
        foreach (self::map($new['stepStatuses'] ?? null) as $step => $status) {
            if (is_string($status) && (! array_key_exists($step, $oldStatuses) || $oldStatuses[$step] !== $status)) {
                self::then($working, $events, new StepStatusChanged((string) $step, $status));
            }
        }

        $oldResults = self::map($old['stepResults'] ?? null);
        foreach (self::map($new['stepResults'] ?? null) as $step => $result) {
            if (! array_key_exists($step, $oldResults) || $oldResults[$step] !== $result) {
                self::then($working, $events, new StepResultRecorded((string) $step, StateSerializer::encodeArray(['value' => $result])));
            }
        }

        if (($old['searchAttributes'] ?? null) !== ($new['searchAttributes'] ?? null)) {
            self::then($working, $events, new SearchAttributesChanged(StateSerializer::encodeArray(['value' => $new['searchAttributes'] ?? []])));
        }

        $changes = [];
        foreach ($new as $key => $value) {
            if (! array_key_exists($key, $working) || $working[$key] !== $value) {
                $changes[$key] = $value;
            }
        }
        if ($changes !== []) {
            self::then($working, $events, new ExecutionCheckpointed(StateSerializer::encodeArray($changes)));
        }

        foreach ($events as $event) {
            $this->recordThat($event);
        }
    }

    public function delete(): void
    {
        if ($this->state !== null && ! $this->deleted) {
            $this->recordThat(new ExecutionDeleted);
        }
    }

    /** @return array{state: string|null, deleted: bool} */
    public function snapshotState(): array
    {
        return ['state' => $this->state === null ? null : StateSerializer::encodeArray($this->state), 'deleted' => $this->deleted];
    }

    public function restoreSnapshot(array $state): void
    {
        $encoded = $state['state'] ?? null;
        $this->state = is_string($encoded) ? StateSerializer::decodeArray($encoded) : null;
        $this->deleted = ($state['deleted'] ?? false) === true;
    }

    public static function snapshotVersion(): int
    {
        return 1;
    }

    /** Every execution event is folded by fold(), so the convention's apply<Event> methods are not needed. */
    protected function eventHandlers(): array
    {
        $handlers = [];
        foreach (array_keys(OrchestrationEventTypes::TYPES) as $class) {
            $handlers[$class] = 'fold';
        }

        return $handlers;
    }

    protected function fold(DomainEvent $event): void
    {
        if ($event instanceof ExecutionDeleted) {
            $this->deleted = true;

            return;
        }
        if ($event instanceof ExecutionStarted) {
            $this->deleted = false;
        }

        $this->state = self::applyTo($this->state ?? [], $event);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private static function applyTo(array $state, DomainEvent $event): array
    {
        if ($event instanceof ExecutionStarted) {
            return StateSerializer::decodeArray($event->state);
        }
        if ($event instanceof ExecutionStatusChanged) {
            $state['status'] = $event->status;
            $state['updatedAt'] = $event->updatedAt;
            $state['completedAt'] = $event->completedAt;
            $state['wakeAt'] = $event->wakeAt;
        } elseif ($event instanceof StepStatusChanged) {
            $statuses = self::map($state['stepStatuses'] ?? null);
            $statuses[$event->stepId] = $event->status;
            $state['stepStatuses'] = $statuses;
        } elseif ($event instanceof StepResultRecorded) {
            $results = self::map($state['stepResults'] ?? null);
            $results[$event->stepId] = StateSerializer::decodeArray($event->result)['value'] ?? null;
            $state['stepResults'] = $results;
        } elseif ($event instanceof SearchAttributesChanged) {
            $state['searchAttributes'] = StateSerializer::decodeArray($event->attributes)['value'] ?? [];
        } elseif ($event instanceof ExecutionCheckpointed) {
            foreach (StateSerializer::decodeArray($event->changes) as $key => $value) {
                $state[$key] = $value;
            }
        }

        return $state;
    }

    /**
     * Queues $event and applies it to the working copy, exactly as fold() will.
     *
     * @param  array<string, mixed>  $working
     * @param  list<DomainEvent>  $events
     */
    private static function then(array &$working, array &$events, DomainEvent $event): void
    {
        $events[] = $event;
        $working = self::applyTo($working, $event);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @param  list<string>  $fields
     */
    private static function differs(array $a, array $b, array $fields): bool
    {
        foreach ($fields as $field) {
            if (($a[$field] ?? null) !== ($b[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<array-key, mixed> */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
