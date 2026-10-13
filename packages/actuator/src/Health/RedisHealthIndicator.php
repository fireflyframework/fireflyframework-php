<?php

declare(strict_types=1);

namespace Firefly\Actuator\Health;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Redis\Factory;
use Throwable;

/**
 * The `redis` health component: PING on each configured connection of Laravel's Redis manager (phpredis or predis,
 * whichever database.redis.client names). Spring Boot's RedisHealthIndicator, minus the INFO dump.
 *
 * Off by default (firefly.management.endpoint.health.redis.enabled). available() is false when the application has
 * no `redis` binding at all, so enabling the key on an application without Redis support adds no DOWN component it
 * cannot fix. Connections come from firefly.management.endpoint.health.redis.connections (default ['default']).
 * The manager is resolved at probe time, not at boot, so building the indicator never opens a connection.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.management.endpoint.health.redis.enabled', havingValue: 'true')]
final class RedisHealthIndicator implements ConditionalHealthIndicator
{
    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
    ) {}

    public function available(): bool
    {
        return $this->container->bound('redis') && is_array($this->config->get('database.redis'));
    }

    public function health(): Health
    {
        $connections = [];
        $down = false;

        try {
            $redis = $this->container->make(Factory::class);
        } catch (Throwable $e) {
            return Health::down(['error' => $e::class.': '.$e->getMessage()]);
        }

        foreach ($this->connections() as $name) {
            try {
                $redis->connection($name)->command('ping');
                $connections[$name] = ['status' => 'UP'];
            } catch (Throwable $e) {
                $down = true;
                $connections[$name] = ['status' => 'DOWN', 'error' => $e::class.': '.$e->getMessage()];
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
            $this->config->array('firefly.management.endpoint.health.redis.connections', ['default']),
            static fn (mixed $name): bool => is_string($name) && $name !== '',
        ));

        /** @var list<string> $configured */
        return $configured === [] ? ['default'] : $configured;
    }
}
