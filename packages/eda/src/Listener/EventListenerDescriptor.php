<?php

declare(strict_types=1);

namespace Firefly\Eda\Listener;

/**
 * A single compiled eda listener: the target class/method, the event-type patterns it subscribes to, its order,
 * and the broker destinations it declared (if any). Every field is scalar-or-list so the manifest var_exports as
 * a plain array literal (no closures/objects), loaded by require+map in production. Mirrors ScheduledDescriptor.
 *
 * `patterns` and `destinations` are DIFFERENT NAMESPACES and must never be substituted for one another:
 * `patterns` are fnmatch globs over EventEnvelope::$eventType (matched in-process by SubscriberRegistry),
 * `destinations` are broker routes over EventEnvelope::$destination (bound by EventConsumer::subscribe()).
 * Conflating them is exactly the defect TopicSubscriptionResolver's docblock records.
 *
 * @phpstan-type EventListenerRow array{class: string, method: string, patterns: list<string>, order: int, destinations?: list<string>}
 */
final readonly class EventListenerDescriptor
{
    /**
     * @param  list<string>  $patterns
     * @param  list<string>  $destinations
     */
    public function __construct(
        public string $class,
        public string $method,
        public array $patterns,
        public int $order = 0,
        public array $destinations = [],
    ) {}

    /**
     * @return EventListenerRow
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'method' => $this->method,
            'patterns' => $this->patterns,
            'order' => $this->order,
            'destinations' => $this->destinations,
        ];
    }

    /**
     * `destinations` is read with a `?? []` fallback rather than as a required key on purpose: a manifest
     * compiled by an older firefly/cli (before listeners could declare broker destinations) is still a valid
     * artifact on disk, and an app that upgrades firefly/eda without re-running `firefly:cache` must keep
     * booting. A missing key means "this listener declared none", which TopicSubscriptionResolver already
     * treats as "cannot narrow safely" — the conservative answer, never a silent subscription gap.
     *
     * @param  EventListenerRow  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['class'], $data['method'], $data['patterns'], $data['order'], $data['destinations'] ?? []);
    }
}
