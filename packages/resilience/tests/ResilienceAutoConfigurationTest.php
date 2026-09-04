<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Kernel\Exception\Infrastructure\ServiceUnavailableException;
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
    $store = (new ResilienceAutoConfiguration)->resilienceStore(new CacheRepository(new ArrayStore), $config);
    $registry = (new ResilienceAutoConfiguration)->resilienceRegistry($config, new InMemoryResilienceStore);

    expect($store)->toBeInstanceOf(CacheResilienceStore::class)
        ->and($registry)->toBeInstanceOf(ResilienceRegistry::class);
});

it('reads the store lock-block timeout from config so a fail-fast primitive is not stuck with a default', function () {
    // The bean must honour firefly.resilience.store.lock-block-timeout; a duration string is parsed the same
    // way every other resilience duration is. Asserted through behaviour: a 0-second budget makes a
    // contended lock reject immediately rather than wait.
    $arrayStore = new ArrayStore;
    $config = new Config(new Repository([
        'firefly' => ['resilience' => ['store' => ['lock-block-timeout' => '0ms']]],
    ]));

    $store = (new ResilienceAutoConfiguration)->resilienceStore(new CacheRepository($arrayStore), $config);
    expect($arrayStore->lock('cfg:lock', 10)->get())->toBeTrue();

    $started = microtime(true);
    expect(fn () => $store->withLock('cfg', 5.0, static fn (): string => 'unreached'))
        ->toThrow(ServiceUnavailableException::class);

    expect(microtime(true) - $started)->toBeLessThan(0.2);
});
