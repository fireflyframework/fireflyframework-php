<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Resilience\ResilienceAutoConfiguration;
use Firefly\Resilience\ResilienceRegistry;
use Firefly\Resilience\Store\CacheResilienceStore;
use Firefly\Resilience\Store\InMemoryResilienceStore;
use Firefly\Resilience\Store\ResilienceStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;

it('is a #[Configuration] ordered 1000 whose beans are gated #[ConditionalOnMissingBean]', function () {
    $class = new ReflectionClass(ResilienceAutoConfiguration::class);

    expect($class->getAttributes(Configuration::class))->not->toBe([])
        ->and($class->getAttributes(Order::class)[0]->newInstance()->order)->toBe(1000);

    $store = $class->getMethod('resilienceStore')->getAttributes(ConditionalOnMissingBean::class)[0]->newInstance();
    $registry = $class->getMethod('resilienceRegistry')->getAttributes(ConditionalOnMissingBean::class)[0]->newInstance();

    expect($store->type)->toBe(ResilienceStore::class)
        ->and($registry->type)->toBe(ResilienceRegistry::class);
});

it('its bean factories build the Cache-backed store and a config-driven registry', function () {
    $config = new Config(new Repository(['firefly' => ['resilience' => []]]));
    $store = (new ResilienceAutoConfiguration)->resilienceStore(new CacheRepository(new ArrayStore));
    $registry = (new ResilienceAutoConfiguration)->resilienceRegistry($config, new InMemoryResilienceStore);

    expect($store)->toBeInstanceOf(CacheResilienceStore::class)
        ->and($registry)->toBeInstanceOf(ResilienceRegistry::class);
});
