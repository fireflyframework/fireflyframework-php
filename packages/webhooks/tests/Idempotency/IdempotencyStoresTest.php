<?php

declare(strict_types=1);

use Firefly\Webhooks\Idempotency\CacheWebhookIdempotencyStore;
use Firefly\Webhooks\Idempotency\DatabaseWebhookIdempotencyStore;
use Firefly\Webhooks\Idempotency\InMemoryWebhookIdempotencyStore;
use Firefly\Webhooks\Idempotency\WebhookIdempotencyStore;
use Firefly\Webhooks\Idempotency\WebhookSchema;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;

function w4SqliteReceipts(): Connection
{
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $connection = $capsule->getConnection();
    WebhookSchema::create($connection->getSchemaBuilder());

    return $connection;
}

dataset('w4 idempotency stores', [
    'memory' => [static fn (): WebhookIdempotencyStore => new InMemoryWebhookIdempotencyStore],
    'cache' => [static fn (): WebhookIdempotencyStore => new CacheWebhookIdempotencyStore(new Repository(new ArrayStore))],
    'database (sqlite)' => [static fn (): WebhookIdempotencyStore => new DatabaseWebhookIdempotencyStore(w4SqliteReceipts())],
]);

it('claims a delivery once, per provider, and lets a released claim be taken again', function (Closure $make) {
    /** @var WebhookIdempotencyStore $store */
    $store = $make();

    expect($store->claim('stripe', 'evt_1', 'e-1', 3600))->toBeTrue()
        ->and($store->claim('stripe', 'evt_1', 'e-2', 3600))->toBeFalse()
        ->and($store->claim('github', 'evt_1', 'e-3', 3600))->toBeTrue();

    $store->release('stripe', 'evt_1');

    expect($store->claim('stripe', 'evt_1', 'e-4', 3600))->toBeTrue();
})->with('w4 idempotency stores');

it('treats keys that differ only in case as different deliveries', function (Closure $make) {
    /** @var WebhookIdempotencyStore $store */
    $store = $make();

    expect($store->claim('p', 'Key', 'e-1', 60))->toBeTrue()
        ->and($store->claim('p', 'key', 'e-2', 60))->toBeTrue();
})->with('w4 idempotency stores');

it('forgets a claim once its TTL is over (memory and database)', function () {
    $now = 1_000;
    $clock = static function () use (&$now): int {
        return $now;
    };
    $connection = w4SqliteReceipts();

    foreach ([new InMemoryWebhookIdempotencyStore($clock), new DatabaseWebhookIdempotencyStore($connection, $clock)] as $store) {
        $now = 1_000;
        expect($store->claim('p', 'k', 'e-1', 60))->toBeTrue();
        $now = 1_059;
        expect($store->claim('p', 'k', 'e-2', 60))->toBeFalse();
        $now = 1_061;
        expect($store->claim('p', 'k', 'e-3', 60))->toBeTrue();
    }

    expect($connection->table(WebhookSchema::TABLE)->where('idempotency_key', 'k')->value('event_id'))->toBe('e-3');
});

it('prunes expired receipts from the table and keeps live ones', function () {
    $now = 5_000;
    $clock = static function () use (&$now): int {
        return $now;
    };
    $connection = w4SqliteReceipts();
    $store = new DatabaseWebhookIdempotencyStore($connection, $clock);
    $store->claim('p', 'old', 'e-1', 10);
    $store->claim('p', 'live', 'e-2', 1000);
    $now = 5_100;

    expect($store->prune())->toBe(1)
        ->and($connection->table(WebhookSchema::TABLE)->pluck('idempotency_key')->all())->toBe(['live']);
});

it('creates the table idempotently', function () {
    $connection = w4SqliteReceipts();
    WebhookSchema::create($connection->getSchemaBuilder());

    expect($connection->getSchemaBuilder()->hasTable(WebhookSchema::TABLE))->toBeTrue();
});
