<?php

declare(strict_types=1);

namespace Firefly\Actuator\Health;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Illuminate\Contracts\Queue\Factory;
use Throwable;

/**
 * The `queue` health component: each configured Laravel queue connection must answer size() for its default queue
 * — a query on the database driver, LLEN on redis, an attribute read on SQS, zero on sync — and the answer is
 * published as a detail, which is the number an operator looks for when a backlog is the outage.
 *
 * Off by default (firefly.management.endpoint.health.queue.enabled), for CacheHealthIndicator's reason. Connections
 * come from firefly.management.endpoint.health.queue.connections, or queue.default when that list is empty.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.management.endpoint.health.queue.enabled', havingValue: 'true')]
final class QueueHealthIndicator implements ConditionalHealthIndicator
{
    public function __construct(
        private readonly Factory $queues,
        private readonly Config $config,
    ) {}

    public function available(): bool
    {
        $default = $this->config->get('queue.default');

        return is_string($default) && $default !== '';
    }

    public function health(): Health
    {
        $connections = [];
        $down = false;

        foreach ($this->connections() as $name) {
            $driver = $this->config->get("queue.connections.{$name}.driver");
            $driver = is_string($driver) ? $driver : 'unknown';

            try {
                $connections[$name] = ['status' => 'UP', 'driver' => $driver, 'size' => $this->queues->connection($name)->size()];
            } catch (Throwable $e) {
                $down = true;
                $connections[$name] = ['status' => 'DOWN', 'driver' => $driver, 'error' => $e::class.': '.$e->getMessage()];
            }
        }

        return $down ? Health::down(['connections' => $connections]) : Health::up(['connections' => $connections]);
    }

    /**
     * @return list<string>
     */
    private function connections(): array
    {
        $configured = array_values(array_filter(
            $this->config->array('firefly.management.endpoint.health.queue.connections', []),
            static fn (mixed $name): bool => is_string($name) && $name !== '',
        ));

        if ($configured !== []) {
            /** @var list<string> $configured */
            return $configured;
        }

        $default = $this->config->get('queue.default');

        return is_string($default) && $default !== '' ? [$default] : [];
    }
}
