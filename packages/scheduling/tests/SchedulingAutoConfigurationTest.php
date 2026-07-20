<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Scheduling\Lock\CacheLock;
use Firefly\Scheduling\Lock\DistributedLock;
use Firefly\Scheduling\Lock\NoneLock;
use Firefly\Scheduling\SchedulingAutoConfiguration;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;

it('is a #[Configuration] ordered 1000 whose bean is gated #[ConditionalOnMissingBean]', function () {
    $class = new ReflectionClass(SchedulingAutoConfiguration::class);

    expect($class->getAttributes(Configuration::class))->not->toBe([])
        ->and($class->getAttributes(Order::class)[0]->newInstance()->order)->toBe(1000);

    $bean = $class->getMethod('distributedLock')->getAttributes(ConditionalOnMissingBean::class)[0]->newInstance();

    expect($bean->type)->toBe(DistributedLock::class);
});

it('selects NoneLock by default and CacheLock when firefly.scheduling.lock.provider = cache', function () {
    $cache = new CacheRepository(new ArrayStore);
    $auto = new SchedulingAutoConfiguration;

    $default = $auto->distributedLock(new Config(new Repository(['firefly' => ['scheduling' => []]])), $cache);
    $cacheLock = $auto->distributedLock(new Config(new Repository(['firefly' => ['scheduling' => ['lock' => ['provider' => 'cache']]]])), $cache);

    expect($default)->toBeInstanceOf(NoneLock::class)
        ->and($cacheLock)->toBeInstanceOf(CacheLock::class);
});
