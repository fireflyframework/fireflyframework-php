<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Aggregate;

use Firefly\Domain\AggregateRoot;
use Firefly\Domain\DomainEvent;
use LogicException;

/**
 * An aggregate whose state IS its events, in firefly/domain's idiom: it extends AggregateRoot, and its pending
 * buffer is the one raiseEvent() fills, with no reflection.
 *
 *  - A command method calls recordThat($event): the event is applied at once, so the aggregate's own invariants
 *    see the new state, and is buffered until the repository saves it.
 *  - Applying calls a PROTECTED `apply<EventShortName>(Event $e)` by convention (`applyOrderPlaced`), or the method
 *    eventHandlers() names for the class. An event with no handler changes nothing, which is fine for events that
 *    carry no state.
 *  - committedVersion() is the stream version this object was loaded at, and the expected version of the next save.
 *    version() adds the buffered events.
 *
 * The constructor is final and takes the id only, so the repository can create an empty aggregate before replay
 * (`new static($id)`). Create a new aggregate through a static named constructor that records its first event.
 */
abstract class EventSourcedAggregateRoot extends AggregateRoot
{
    private int $committedVersion = 0;

    final public function __construct(string $id)
    {
        parent::__construct($id);
    }

    public function id(): string
    {
        return (string) $this->id;
    }

    /** The stream's aggregate type. Override with a stable name: it is stored with every event. */
    public static function aggregateType(): string
    {
        $class = static::class;
        $position = strrpos($class, '\\');

        return strtolower($position === false ? $class : substr($class, $position + 1));
    }

    public function version(): int
    {
        return $this->committedVersion + count($this->pendingEvents());
    }

    public function committedVersion(): int
    {
        return $this->committedVersion;
    }

    /** @return list<DomainEvent> */
    public function uncommittedEvents(): array
    {
        return $this->pendingEvents();
    }

    public function markEventsCommitted(): void
    {
        $this->committedVersion += count($this->pendingEvents());
        $this->clearEvents();
    }

    /** @param iterable<DomainEvent> $history */
    public function loadFromHistory(iterable $history): void
    {
        foreach ($history as $event) {
            $this->route($event);
            $this->committedVersion++;
        }
    }

    /**
     * @param  iterable<DomainEvent>  $history  the events after $fromVersion
     * @param  array<string, mixed>|null  $snapshotState  the state at $fromVersion, for a SnapshotAware aggregate
     */
    public static function reconstitute(string $id, iterable $history, int $fromVersion = 0, ?array $snapshotState = null): static
    {
        $aggregate = new static($id);
        if ($snapshotState !== null) {
            if (! $aggregate instanceof SnapshotAware) {
                throw new LogicException(static::class.' was given a snapshot but does not implement SnapshotAware.');
            }
            $aggregate->restoreSnapshot($snapshotState);
        }
        $aggregate->committedVersion = $fromVersion;
        $aggregate->loadFromHistory($history);

        return $aggregate;
    }

    protected function recordThat(DomainEvent $event): void
    {
        $this->route($event);
        $this->raiseEvent($event);
    }

    /** @return array<class-string<DomainEvent>, string> event class => handler method, for names the convention cannot give */
    protected function eventHandlers(): array
    {
        return [];
    }

    /**
     * Calls the event's handler. The name deliberately does not start with "apply": an event short-named `Event`
     * must reach the subclass's applyEvent(), never this method. A handler eventHandlers() names must exist, since
     * a misspelled name would otherwise drop the event's state without a word.
     */
    private function route(DomainEvent $event): void
    {
        $named = $this->eventHandlers()[$event::class] ?? null;
        $method = $named ?? 'apply'.self::shortName($event::class);
        if (! method_exists($this, $method)) {
            if ($named !== null) {
                throw new LogicException(static::class.'::eventHandlers() maps '.$event::class." to {$named}(), which the aggregate does not declare.");
            }

            return;
        }
        if (! is_callable([$this, $method])) {
            throw new LogicException(static::class."::{$method}() must be protected or public: the aggregate base calls it.");
        }

        $this->{$method}($event);
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
