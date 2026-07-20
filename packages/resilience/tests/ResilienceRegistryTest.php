<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Resilience\Bulkhead;
use Firefly\Resilience\CircuitBreaker;
use Firefly\Resilience\RateLimiter;
use Firefly\Resilience\ResilienceRegistry;
use Firefly\Resilience\Retry;
use Firefly\Resilience\Store\InMemoryResilienceStore;
use Firefly\Resilience\TimeLimiter;
use Illuminate\Config\Repository;

/** @param array<string, mixed> $resilience */
function makeResilienceRegistry(array $resilience = []): ResilienceRegistry
{
    return ResilienceRegistry::fromConfig(
        new Config(new Repository(['firefly' => ['resilience' => $resilience]])),
        new InMemoryResilienceStore,
    );
}

it('builds each pattern from its named configuration', function () {
    $reg = makeResilienceRegistry([
        'retry' => ['default' => ['max-attempts' => 5]],
        'circuit-breaker' => ['payments' => ['failure-threshold' => 2]],
        'rate-limiter' => ['api' => ['max-tokens' => 3]],
        'bulkhead' => ['db' => ['max-concurrent' => 4]],
        'time-limiter' => ['slow' => ['timeout' => '2s']],
    ]);

    expect($reg->retry('default'))->toBeInstanceOf(Retry::class)
        ->and($reg->circuitBreaker('payments'))->toBeInstanceOf(CircuitBreaker::class)
        ->and($reg->rateLimiter('api'))->toBeInstanceOf(RateLimiter::class)
        ->and($reg->bulkhead('db'))->toBeInstanceOf(Bulkhead::class)
        ->and($reg->timeLimiter('slow'))->toBeInstanceOf(TimeLimiter::class);
});

it('memoizes one instance per name', function () {
    $reg = makeResilienceRegistry(['retry' => ['default' => []]]);

    expect($reg->retry('default'))->toBe($reg->retry('default'));
});

it('builds a working default-config instance for an empty named entry', function () {
    $reg = makeResilienceRegistry(['retry' => ['default' => []]]);
    $calls = 0;

    $result = $reg->retry('default')->call(function () use (&$calls) {
        $calls++;
        if ($calls < 3) {
            throw new RuntimeException('transient');
        }

        return 'ok';
    });

    expect($result)->toBe('ok')->and($calls)->toBe(3); // default max-attempts 3
});

it('throws a ConfigurationException naming available instances for an unknown name', function () {
    $reg = makeResilienceRegistry(['retry' => ['default' => [], 'aggressive' => []]]);

    expect(fn () => $reg->retry('missing'))->toThrow(ConfigurationException::class, 'Available: default, aggressive');
});

it('an empty resilience config still rejects unknown names cleanly', function () {
    expect(fn () => makeResilienceRegistry()->circuitBreaker('none'))->toThrow(ConfigurationException::class, '(none configured)');
});
