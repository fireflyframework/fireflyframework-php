<?php

declare(strict_types=1);

use Firefly\Orchestration\Persistence\CacheExecutionPersistenceProvider;
use Firefly\Orchestration\Tests\Support\ExecutionPersistenceContract;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

foreach (ExecutionPersistenceContract::cases() as $case => $assert) {
    it("cache persistence {$case}", function () use ($assert): void {
        $assert(new CacheExecutionPersistenceProvider(new Repository(new ArrayStore), 'test:'));
    });
}

it('keeps two prefixes apart in one store', function (): void {
    $store = new Repository(new ArrayStore);
    $first = new CacheExecutionPersistenceProvider($store, 'one:');
    $second = new CacheExecutionPersistenceProvider($store, 'two:');

    $first->save(ExecutionPersistenceContract::state('c-1'));

    expect($second->findById('c-1'))->toBeNull()
        ->and($second->countInFlight())->toBe(0)
        ->and($first->countInFlight())->toBe(1);
});
