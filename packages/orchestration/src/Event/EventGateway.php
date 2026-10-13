<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Event;

use Closure;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Orchestration\Core\ExecutionPattern;

/**
 * Starts executions from EDA events (jafly EventGateway). OrchestrationRegistryPass registers one executor per
 * definition that declares a `triggerEventType`; EventGatewayWiringPass subscribes each event type on the
 * application's EventPublisher, and a delivered envelope's payload becomes the execution input, its headers (plus
 * `eventId` and `eventType`) the execution headers. One event type starts exactly one definition: a second
 * registration refuses the boot rather than racing two executions.
 *
 * Delivery is at least once on every transport (Kafka, RabbitMQ, the database outbox), so the execution's
 * correlation id is derived from the triggering event (correlationIdFor()): a redelivered event names the
 * execution its first delivery started, and the executor acknowledges it as a no-op instead of starting again.
 */
final class EventGateway
{
    /** @var array<string, array{target: string, pattern: ExecutionPattern, executor: Closure(array<string, mixed>, array<string, string>, ?string): mixed}> */
    private array $triggers = [];

    /**
     * The executor is called with the payload, the headers and the correlation id the execution must use.
     *
     * @param  Closure(array<string, mixed>, array<string, string>, ?string): mixed  $executor
     */
    public function register(string $eventType, string $target, ExecutionPattern $pattern, Closure $executor): void
    {
        if (isset($this->triggers[$eventType])) {
            $existing = $this->triggers[$eventType];

            throw new ConfigurationException("Trigger event type [{$eventType}] is declared by {$existing['pattern']->value} [{$existing['target']}] and by {$pattern->value} [{$target}]; one event type may start one definition.");
        }

        $this->triggers[$eventType] = ['target' => $target, 'pattern' => $pattern, 'executor' => $executor];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function route(string $eventType, array $payload, array $headers = [], ?string $correlationId = null): mixed
    {
        $trigger = $this->triggers[$eventType] ?? null;

        return $trigger === null ? null : ($trigger['executor'])($payload, $headers, $correlationId);
    }

    /**
     * The correlation id of the execution an event starts: a name-based (version 5 layout) UUID over the event
     * type and the event id, so it is stable across redeliveries, fits the ledger's id column whatever the
     * producer's id looks like, and never collides with the random ids code-started executions get.
     */
    public static function correlationIdFor(string $eventType, string $eventId): string
    {
        $hash = sha1("firefly.orchestration.trigger\0{$eventType}\0{$eventId}");
        $variant = dechex((hexdec($hash[16]) & 0x3) | 0x8);

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-5'.substr($hash, 13, 3).'-'.$variant.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
    }

    public function has(string $eventType): bool
    {
        return isset($this->triggers[$eventType]);
    }

    public function count(): int
    {
        return count($this->triggers);
    }

    /** @return list<string> */
    public function eventTypes(): array
    {
        return array_map('strval', array_keys($this->triggers));
    }

    /** @return list<array{eventType: string, target: string, pattern: string}> */
    public function describe(): array
    {
        $rows = [];
        foreach ($this->triggers as $eventType => $trigger) {
            $rows[] = ['eventType' => (string) $eventType, 'target' => $trigger['target'], 'pattern' => $trigger['pattern']->value];
        }

        return $rows;
    }
}
