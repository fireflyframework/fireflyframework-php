<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Event;

use Closure;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Orchestration\Core\ExecutionPattern;

/**
 * Starts executions from EDA events (jafly EventGateway). OrchestrationRegistryPass registers one executor per
 * definition that declares a `triggerEventType`; EventGatewayWiringPass subscribes each event type on the
 * application's EventPublisher, and a delivered envelope's payload becomes the execution input. One event type
 * starts exactly one definition: a second registration refuses the boot rather than racing two executions.
 */
final class EventGateway
{
    /** @var array<string, array{target: string, pattern: ExecutionPattern, executor: Closure(array<string, mixed>): mixed}> */
    private array $triggers = [];

    /**
     * @param  Closure(array<string, mixed>): mixed  $executor
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
     */
    public function route(string $eventType, array $payload): mixed
    {
        $trigger = $this->triggers[$eventType] ?? null;

        return $trigger === null ? null : ($trigger['executor'])($payload);
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
