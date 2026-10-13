<?php

declare(strict_types=1);

namespace Firefly\Actuator\Health;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Illuminate\Contracts\Cache\Factory;
use Throwable;

/**
 * The `cache` health component: each configured Laravel cache store must take a write, give it back and forget it.
 * A random sentinel key per probe, so two instances probing one shared store never read each other's value, and a
 * one-minute TTL so a probe interrupted between put and forget leaves nothing behind for long.
 *
 * OFF BY DEFAULT (firefly.management.endpoint.health.cache.enabled): turning it on adds a component that can be
 * DOWN, and an existing application's /health must not change status because the framework was upgraded. The
 * stores probed are firefly.management.endpoint.health.cache.stores, or the default store when that list is empty.
 * Uses Illuminate's cache contract directly — no new package edge.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.management.endpoint.health.cache.enabled', havingValue: 'true')]
final class CacheHealthIndicator implements ConditionalHealthIndicator
{
    public function __construct(
        private readonly Factory $caches,
        private readonly Config $config,
    ) {}

    public function available(): bool
    {
        $default = $this->config->get('cache.default');

        return is_string($default) && $default !== '';
    }

    public function health(): Health
    {
        $stores = [];
        $down = false;

        foreach ($this->stores() as $name) {
            $driver = $this->config->get("cache.stores.{$name}.driver");
            $entry = ['status' => 'UP', 'driver' => is_string($driver) ? $driver : 'unknown'];

            try {
                $cache = $this->caches->store($name);
                $key = 'firefly:health:'.bin2hex(random_bytes(8));
                $value = bin2hex(random_bytes(4));
                $cache->put($key, $value, 60);
                $read = $cache->get($key);
                $cache->forget($key);

                if ($read !== $value) {
                    $entry = ['status' => 'DOWN', 'driver' => $entry['driver'], 'error' => 'the store did not return the value just written'];
                }
            } catch (Throwable $e) {
                $entry = ['status' => 'DOWN', 'driver' => $entry['driver'], 'error' => $e::class.': '.$e->getMessage()];
            }

            $down = $down || $entry['status'] === 'DOWN';
            $stores[$name] = $entry;
        }

        return $down ? Health::down(['stores' => $stores]) : Health::up(['stores' => $stores]);
    }

    /**
     * @return list<string>
     */
    private function stores(): array
    {
        $configured = array_values(array_filter(
            $this->config->array('firefly.management.endpoint.health.cache.stores', []),
            static fn (mixed $name): bool => is_string($name) && $name !== '',
        ));

        if ($configured !== []) {
            /** @var list<string> $configured */
            return $configured;
        }

        $default = $this->config->get('cache.default');

        return is_string($default) && $default !== '' ? [$default] : [];
    }
}
