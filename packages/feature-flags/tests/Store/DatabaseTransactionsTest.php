<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Store\DatabaseFlagStore;
use Firefly\FeatureFlags\Store\FeatureFlagSchema;
use Firefly\FeatureFlags\Tests\Support\FlagStores;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\Assert;

it('keeps flag and audit atomic when a caller catches a failed write and commits', function (string $operation): void {
    $connection = FlagStores::sqlite();
    $store = new DatabaseFlagStore($connection, FlagStores::clock());
    $definition = ['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on'];
    if ($operation !== 'insert') {
        $store->put('k', $definition, 'ada');
    }
    $connection->statement("CREATE TRIGGER refuse_audit BEFORE INSERT ON firefly_feature_flag_changes BEGIN SELECT RAISE(ABORT, 'audit refused'); END");
    $connection->beginTransaction();
    try {
        if ($operation === 'delete') {
            $store->delete('k', 'eve');
        } else {
            $store->put('k', $definition, 'eve');
        }
        Assert::fail('Audit insert must fail');
    } catch (QueryException) {
        expect($connection->transactionLevel())->toBe(1);
    }
    $connection->commit();
    expect($store->get('k')?->version)->toBe($operation === 'insert' ? null : 1)
        ->and($store->get('k')?->updatedBy)->toBe($operation === 'insert' ? null : 'ada')
        ->and($store->revision())->toBe($operation === 'insert' ? 0 : 1);
})->with(['insert', 'update', 'delete']);

it('binds callbacks to actual commit on the store connection even with another transaction active', function (): void {
    $first = FlagStores::sqlite();
    $second = FlagStores::sqlite();
    $dispatcher = new Dispatcher;
    $manager = new DatabaseTransactionsManager;
    foreach ([$first, $second] as $connection) {
        // Deliberately identical connection names and one manager/dispatcher.
        $connection->setEventDispatcher($dispatcher);
        $connection->setTransactionManager($manager);
    }
    $store = new DatabaseFlagStore($first);
    $calls = [];
    $first->beginTransaction();
    $second->beginTransaction();
    expect($store->transactionActive())->toBeTrue();
    $store->afterCommit(function () use (&$calls): void {
        $calls[] = 'first';
    });
    $second->commit();
    expect($calls)->toBe([]);
    $first->commit();
    expect($calls)->toBe(['first'])->and($store->transactionActive())->toBeFalse();
    $second->beginTransaction();
    $store->afterCommit(function () use (&$calls): void {
        $calls[] = 'immediate';
    });
    expect($calls)->toBe(['first', 'immediate'])->and($store->transactionActive())->toBeFalse();
    $second->rollBack();
});

it('discards callbacks with their rolled back ancestor and keeps released siblings', function (bool $rootRollback): void {
    $connection = FlagStores::sqlite();
    $store = new DatabaseFlagStore($connection);
    $calls = [];
    $connection->beginTransaction();
    $store->afterCommit(function () use (&$calls): void {
        $calls[] = 'root';
    });
    $connection->beginTransaction();
    $connection->beginTransaction();
    $store->afterCommit(function () use (&$calls): void {
        $calls[] = 'discarded-child';
    });
    $connection->commit();
    $connection->rollBack();
    $connection->beginTransaction();
    $store->afterCommit(function () use (&$calls): void {
        $calls[] = 'released-sibling';
    });
    $connection->commit();
    $connection->beginTransaction();
    $store->afterCommit(function () use (&$calls): void {
        $calls[] = 'discarded-sibling';
    });
    $connection->rollBack();
    expect($calls)->toBe([]);
    if ($rootRollback) {
        $connection->rollBack();
    } else {
        $connection->commit();
    }
    $connection->beginTransaction();
    $connection->commit();
    expect($calls)->toBe($rootRollback ? [] : ['root', 'released-sibling']);
})->with([false, true]);

it('never resurrects a callback when an identical replacement reuses its rolled back audit id', function (): void {
    $connection = FlagStores::sqlite();
    $store = new DatabaseFlagStore($connection, FlagStores::clock());
    $definition = ['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on'];
    $calls = [];
    $connection->beginTransaction();
    $connection->beginTransaction();
    $old = $store->put('k', $definition, 'ada');
    $store->afterCommit(function () use (&$calls): void {
        $calls[] = 'rolled-back';
    });
    $connection->rollBack();
    $new = $store->put('k', $definition, 'ada');
    $store->afterCommit(function () use (&$calls): void {
        $calls[] = 'replacement';
    });
    expect($new->id)->toBe($old->id);
    $connection->commit();
    expect($calls)->toBe(['replacement'])
        ->and($connection->table(FeatureFlagSchema::CHANGES)->count())->toBe(1);
});
