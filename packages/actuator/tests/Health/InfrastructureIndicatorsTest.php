<?php

declare(strict_types=1);

use Firefly\Actuator\Health\CacheHealthIndicator;
use Firefly\Actuator\Health\ConditionalHealthIndicator;
use Firefly\Actuator\Health\Health;
use Firefly\Actuator\Health\QueueHealthIndicator;
use Firefly\Actuator\Health\RedisHealthIndicator;
use Firefly\Actuator\Health\Status;
use Firefly\Config\Config;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\SyncQueue;

/**
 * @param  array<string, CacheContract>  $stores
 */
function w4Caches(array $stores): CacheFactory
{
    return new class($stores) implements CacheFactory
    {
        /** @param array<string, CacheContract> $stores */
        public function __construct(private readonly array $stores) {}

        public function store($name = null): CacheContract
        {
            $key = is_string($name) ? $name : 'array';

            return $this->stores[$key] ?? throw new InvalidArgumentException("Cache store [{$key}] is not defined.");
        }
    };
}

/**
 * @param  array<string, Queue>  $queues
 */
function w4Queues(array $queues): QueueFactory
{
    return new class($queues) implements QueueFactory
    {
        /** @param array<string, Queue> $queues */
        public function __construct(private readonly array $queues) {}

        public function connection($name = null): Queue
        {
            $key = is_string($name) ? $name : 'sync';

            return $this->queues[$key] ?? throw new InvalidArgumentException("Queue connection [{$key}] is not configured.");
        }
    };
}

/**
 * Walks a health reading's nested details (`stores.<name>.status`), so an assertion names the path it reads.
 */
function w4HealthDetail(Health $health, string ...$path): mixed
{
    $value = $health->details;

    foreach ($path as $segment) {
        if (! is_array($value) || ! array_key_exists($segment, $value)) {
            return null;
        }

        $value = $value[$segment];
    }

    return $value;
}

/**
 * @param  array<string, mixed>  $values
 */
function w4HealthConfig(array $values): Config
{
    return new Config(new Repository($values));
}

it('gates every indicator off by default behind its own key', function () {
    foreach ([CacheHealthIndicator::class => 'cache', QueueHealthIndicator::class => 'queue', RedisHealthIndicator::class => 'redis'] as $class => $name) {
        $condition = (new ReflectionClass($class))->getAttributes(ConditionalOnProperty::class)[0]->newInstance();

        expect($condition->name)->toBe("firefly.management.endpoint.health.{$name}.enabled")
            ->and($condition->havingValue)->toBe('true')
            ->and($condition->matchIfMissing)->toBeFalse()
            ->and(class_implements($class))->toHaveKey(ConditionalHealthIndicator::class);
    }
});

it('reports every configured cache store UP after a write-read-forget round trip', function () {
    $config = w4HealthConfig(['cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array'], 'second' => ['driver' => 'array']]], 'firefly' => ['management' => ['endpoint' => ['health' => ['cache' => ['stores' => ['array', 'second']]]]]]]);
    $indicator = new CacheHealthIndicator(w4Caches(['array' => new CacheRepository(new ArrayStore), 'second' => new CacheRepository(new ArrayStore)]), $config);
    $health = $indicator->health();

    expect($indicator->available())->toBeTrue()
        ->and($health->status)->toBe(Status::Up)
        ->and(w4HealthDetail($health, 'stores', 'array', 'status'))->toBe('UP')
        ->and(w4HealthDetail($health, 'stores', 'second', 'driver'))->toBe('array');
});

it('reports DOWN, naming the store and the error, when one store fails', function () {
    $config = w4HealthConfig(['cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]], 'firefly' => ['management' => ['endpoint' => ['health' => ['cache' => ['stores' => ['array', 'missing']]]]]]]);
    $health = (new CacheHealthIndicator(w4Caches(['array' => new CacheRepository(new ArrayStore)]), $config))->health();

    expect($health->status)->toBe(Status::Down)
        ->and(w4HealthDetail($health, 'stores', 'array', 'status'))->toBe('UP')
        ->and(w4HealthDetail($health, 'stores', 'missing', 'status'))->toBe('DOWN')
        ->and(w4HealthDetail($health, 'stores', 'missing', 'error'))->toContain('not defined');
});

it('is unavailable when no default cache store is configured', function () {
    expect((new CacheHealthIndicator(w4Caches([]), w4HealthConfig(['cache' => []])))->available())->toBeFalse();
});

it('reports queue connections with their size, DOWN when one cannot be reached', function () {
    $config = w4HealthConfig(['queue' => ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]], 'firefly' => ['management' => ['endpoint' => ['health' => ['queue' => ['connections' => ['sync', 'redis']]]]]]]);
    $indicator = new QueueHealthIndicator(w4Queues(['sync' => new SyncQueue]), $config);
    $health = $indicator->health();

    expect($indicator->available())->toBeTrue()
        ->and($health->status)->toBe(Status::Down)
        ->and(w4HealthDetail($health, 'connections', 'sync'))->toBe(['status' => 'UP', 'driver' => 'sync', 'size' => 0])
        ->and(w4HealthDetail($health, 'connections', 'redis', 'status'))->toBe('DOWN');
});

it('declines when no redis binding exists', function () {
    $indicator = new RedisHealthIndicator(new Container, w4HealthConfig(['database' => ['redis' => ['default' => ['host' => '127.0.0.1']]]]));

    expect($indicator->available())->toBeFalse();
});
