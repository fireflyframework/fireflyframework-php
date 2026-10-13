<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Cqrs\Cache\CacheQueryCache;
use Firefly\Cqrs\Cache\NoOpQueryCache;
use Firefly\Cqrs\CqrsAutoConfiguration;
use Firefly\Cqrs\Exception\CqrsConfigurationException;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;

function w4QueryCacheContainer(): Container
{
    $container = new Application;
    $container->instance('config', new Repository(['cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array'], 'second' => ['driver' => 'array']]]]));
    $container->instance('cache', new CacheManager($container));

    return $container;
}

it('defaults to the inert NoOp cache', function () {
    $cache = (new CqrsAutoConfiguration)->queryCache(new Config(new Repository(['firefly' => []])), w4QueryCacheContainer());

    expect($cache)->toBeInstanceOf(NoOpQueryCache::class);
});

it('builds the Laravel-cache adapter on the named store for provider=cache', function () {
    $config = new Config(new Repository(['firefly' => ['cqrs' => ['query' => ['cache' => ['provider' => 'cache', 'store' => 'second']]]]]));

    expect((new CqrsAutoConfiguration)->queryCache($config, w4QueryCacheContainer()))->toBeInstanceOf(CacheQueryCache::class);
});

it('refuses an unknown provider at boot, naming the key', function () {
    $config = new Config(new Repository(['firefly' => ['cqrs' => ['query' => ['cache' => ['provider' => 'redis']]]]]));

    expect(fn () => (new CqrsAutoConfiguration)->queryCache($config, w4QueryCacheContainer()))
        ->toThrow(CqrsConfigurationException::class, 'firefly.cqrs.query.cache.provider');
});
