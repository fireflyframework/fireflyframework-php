<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Store\DatabaseFlagStore;
use Firefly\FeatureFlags\Store\FeatureFlagSchema;
use Firefly\FeatureFlags\Store\FlagStoreConflict;
use Firefly\FeatureFlags\Tests\Support\FlagStores;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

it('stores an empty-object variant as {}', function (): void {
    $connection = FlagStores::sqlite();
    $store = new DatabaseFlagStore($connection, FlagStores::clock());

    $store->put('banner', Json::members(Json::decode('{"state":"ENABLED","variants":{"none":{},"promo":{"t":"x"}},"defaultVariant":"none","metadata":{}}')), 'ada');

    expect($connection->table(FeatureFlagSchema::FLAGS)->where('flag_key', 'banner')->value('definition'))
        ->toBe('{"defaultVariant":"none","metadata":{},"state":"ENABLED","variants":{"none":{},"promo":{"t":"x"}}}')
        ->and($store->get('banner')?->definition['variants'] ?? null)->toBeArray();
});

it('writes the flag row and the change row in one transaction', function (): void {
    $connection = FlagStores::sqlite();
    $connection->getSchemaBuilder()->drop(FeatureFlagSchema::CHANGES); // the change insert will fail …
    $store = new DatabaseFlagStore($connection, FlagStores::clock());

    expect(fn () => $store->put('k', ['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on'], 'ada'))
        ->toThrow(QueryException::class)
        // … and takes the flag row down with it.
        ->and($connection->table(FeatureFlagSchema::FLAGS)->count())->toBe(0);
});

it('creates the schema idempotently, with the contract\'s index', function (): void {
    $connection = FlagStores::sqlite();
    FeatureFlagSchema::create($connection->getSchemaBuilder());
    $indexes = array_column($connection->getSchemaBuilder()->getIndexes(FeatureFlagSchema::CHANGES), 'name');

    expect($indexes)->toContain(FeatureFlagSchema::CHANGES_INDEX)
        ->and($connection->getSchemaBuilder()->getColumnListing(FeatureFlagSchema::FLAGS))
        ->toEqualCanonicalizing(['flag_key', 'definition', 'version', 'updated_at', 'updated_by']);
});

it('reports audit integrity errors without misclassifying them as a version conflict', function (): void {
    $connection = FlagStores::sqlite();
    $connection->statement('CREATE UNIQUE INDEX audit_actor_unique ON firefly_feature_flag_changes (actor)');
    $store = new DatabaseFlagStore($connection, FlagStores::clock());
    $definition = ['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on'];
    $store->put('first', $definition, 'ada');
    expect(fn () => $store->put('second', $definition, 'ada'))->toThrow(UniqueConstraintViolationException::class)
        ->and($store->get('second'))->toBeNull()->and($store->revision())->toBe(1);
});

it('converts clock zones to UTC and returns canonical detached objects with bounded history', function (): void {
    $connection = FlagStores::sqlite();
    $store = new DatabaseFlagStore($connection, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-01 05:00:00.123456-07:00'));
    $definition = ['state' => 'ENABLED', 'variants' => ['on' => (object) []], 'defaultVariant' => 'on'];
    $change = $store->put('k', $definition, null);
    $definition['variants']['on']->changed = true;
    expect($connection->table(FeatureFlagSchema::FLAGS)->value('updated_at'))->toBe('2026-10-01 12:00:00.123456')
        ->and($change->toHistoryRow()['changedAt'])->toBe('2026-10-01T12:00:00Z')
        ->and(Json::encode($change->definition))->toBe('{"defaultVariant":"on","state":"ENABLED","variants":{"on":{}}}')
        ->and($change->definition)->toEqual($store->get('k')?->definition)
        ->and($store->history('k', 0))->toBe([])->and($store->history('k', -1))->toBe([]);
});

it('reads revision, rows and history from the writer rather than a lagging replica', function (): void {
    $connection = FlagStores::sqlite();
    $connection->setReadPdo(new PDO('sqlite::memory:'));
    $store = new DatabaseFlagStore($connection, FlagStores::clock());
    $store->put('k', ['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on'], 'ada');
    expect($store->get('k')?->version)->toBe(1)->and(array_keys($store->all()))->toBe(['k'])
        ->and($store->revision())->toBe(1)->and($store->history('k'))->toHaveCount(1);
});

/** @param int|null $expected */
it('bounds row-race retries to owned unconditional writes', function (bool $outer, ?int $expected, int $attempts): void {
    $connection = FlagStores::sqlite();
    $writes = 0;
    $connection->beforeExecuting(static function (string $query) use (&$writes): void {
        if (str_starts_with($query, 'insert into "firefly_feature_flags"')) {
            $writes++;
            throw new UniqueConstraintViolationException('sqlite', $query, [], new PDOException('injected competing primary key'));
        }
    });
    $store = new DatabaseFlagStore($connection);
    if ($outer) {
        $connection->beginTransaction();
    }
    expect(fn () => $store->put('race', ['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on'], null, $expected))->toThrow(FlagStoreConflict::class);
    expect($writes)->toBe($attempts)->and($store->all())->toBe([])->and($store->revision())->toBe(0);
    if ($outer) {
        $connection->commit();
    }
})->with([[false, null, 3], [true, null, 1], [false, 0, 1]]);

it('never retries an unrelated conflict exception from the clock', function (): void {
    $calls = 0;
    $store = new DatabaseFlagStore(FlagStores::sqlite(), static function () use (&$calls): DateTimeImmutable {
        $calls++;
        throw new FlagStoreConflict('other', 0, 1);
    });
    expect(fn () => $store->put('race', ['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on'], null))->toThrow(FlagStoreConflict::class);
    expect($calls)->toBe(1)->and($store->revision())->toBe(0);
});
