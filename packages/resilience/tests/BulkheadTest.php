<?php

declare(strict_types=1);

use Firefly\Resilience\Bulkhead;
use Firefly\Resilience\Exception\BulkheadFullException;
use Firefly\Resilience\Store\CacheResilienceStore;
use Firefly\Resilience\Store\InMemoryResilienceStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

it('rejects calls once max-concurrent permits are held and restores capacity on release', function () {
    $bulkhead = new Bulkhead('bh:test', new InMemoryResilienceStore, maxConcurrent: 2);

    expect($bulkhead->acquire())->toBeTrue()
        ->and($bulkhead->acquire())->toBeTrue()
        ->and(fn () => $bulkhead->call(fn (): string => 'blocked'))->toThrow(BulkheadFullException::class);

    $bulkhead->release();

    expect($bulkhead->call(fn (): string => 'ok'))->toBe('ok');
});

it('shares the permit counter across separate store instances over one cache', function () {
    $cache = new Repository(new ArrayStore);
    $bulkheadA = new Bulkhead('bh:shared', new CacheResilienceStore($cache), maxConcurrent: 1);
    $bulkheadB = new Bulkhead('bh:shared', new CacheResilienceStore($cache), maxConcurrent: 1);

    expect($bulkheadA->acquire())->toBeTrue()
        ->and($bulkheadB->acquire())->toBeFalse(); // A holds the only permit, in the shared cache
});
