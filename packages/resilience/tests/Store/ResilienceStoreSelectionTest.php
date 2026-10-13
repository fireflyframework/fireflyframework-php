<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Resilience\ResilienceAutoConfiguration;
use Firefly\Resilience\Store\CacheResilienceStore;
use Firefly\Resilience\Store\InMemoryResilienceStore;
use Firefly\Resilience\Tests\Fixtures\LocklessStore;
use Firefly\Resilience\Tests\Support\RecordingLogger;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Assert;

/**
 * @param  array<string, mixed>  $store
 */
function w4ResilienceConfig(array $store = []): Config
{
    return new Config(new Repository(['firefly' => ['resilience' => ['store' => $store]]]));
}

it('keeps the cache store as the default', function () {
    expect((new ResilienceAutoConfiguration)->resilienceStore(new CacheRepository(new ArrayStore), w4ResilienceConfig()))
        ->toBeInstanceOf(CacheResilienceStore::class);
});

it('selects the in-memory store with provider=memory, whatever the cache is', function () {
    expect((new ResilienceAutoConfiguration)->resilienceStore(new CacheRepository(new LocklessStore), w4ResilienceConfig(['provider' => 'memory'])))
        ->toBeInstanceOf(InMemoryResilienceStore::class);
});

it('refuses an unknown provider, naming the key', function () {
    expect(fn () => (new ResilienceAutoConfiguration)->resilienceStore(new CacheRepository(new ArrayStore), w4ResilienceConfig(['provider' => 'redis'])))
        ->toThrow(ConfigurationException::class, 'firefly.resilience.store.provider');
});

it('refuses a cache store without atomic locks unless allow-lockless is set', function () {
    try {
        (new ResilienceAutoConfiguration)->resilienceStore(new CacheRepository(new LocklessStore), w4ResilienceConfig());
        Assert::fail('a lockless store was accepted');
    } catch (ConfigurationException $e) {
        expect($e->errorCode())->toBe('RESILIENCE_STORE_LOCKLESS')
            ->and($e->getMessage())->toContain('firefly.resilience.store.allow-lockless');
    }
});

it('accepts a lockless store when allowed, runs the section unlocked and says so once', function () {
    $logger = new RecordingLogger;
    $store = (new ResilienceAutoConfiguration)->resilienceStore(new CacheRepository(new LocklessStore), w4ResilienceConfig(['allow-lockless' => true]), $logger);

    $first = $store->withLock('k', 1.0, static fn (): int => 1);
    $second = $store->withLock('k', 1.0, static fn (): int => 2);
    $warnings = $logger->warnings();

    expect($first)->toBe(1)
        ->and($second)->toBe(2)
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0] ?? '')->toContain('without a critical section');
});

it('no longer degrades silently when constructed directly over a lockless store', function () {
    $store = new CacheResilienceStore(new CacheRepository(new LocklessStore));

    expect(fn () => $store->withLock('k', 1.0, static fn (): int => 1))
        ->toThrow(ConfigurationException::class, 'has no atomic locks');
});
