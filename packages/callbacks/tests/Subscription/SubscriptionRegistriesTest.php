<?php

declare(strict_types=1);

use Firefly\Callbacks\Schema\CallbackSchema;
use Firefly\Callbacks\Subscription\CallbackSubscription;
use Firefly\Callbacks\Subscription\DatabaseSubscriptionRegistry;
use Firefly\Callbacks\Subscription\InMemorySubscriptionRegistry;
use Firefly\Callbacks\Subscription\SubscriptionRegistry;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;

function w4CallbacksSqlite(): Connection
{
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $connection = $capsule->getConnection();
    CallbackSchema::create($connection->getSchemaBuilder());

    return $connection;
}

dataset('w4 subscription registries', [
    'memory' => [static fn (): SubscriptionRegistry => new InMemorySubscriptionRegistry],
    // A ticking clock: every save is one second later, so created_at orders all() exactly as the memory registry
    // does (two saves inside one wall-clock second would otherwise tie and fall back to the random ids).
    'database (sqlite)' => [static function (): SubscriptionRegistry {
        $tick = 0;

        return new DatabaseSubscriptionRegistry(w4CallbacksSqlite(), static function () use (&$tick): int {
            return ++$tick;
        });
    }],
]);

it('saves, finds, updates, lists and deletes subscriptions', function (Closure $make) {
    /** @var SubscriptionRegistry $registry */
    $registry = $make();
    $orders = CallbackSubscription::create('orders', 'https://partner.example.com/orders', ['order.*'], 'secret', ['X-Partner' => 'p1'], 4);
    $billing = CallbackSubscription::create('billing', 'https://billing.example.com/hook', ['invoice.paid']);

    $registry->save($orders);
    $registry->save($billing);
    $registry->save(new CallbackSubscription($orders->id, 'orders v2', $orders->url, ['order.created'], 'rotated', [], false, null));

    expect($registry->find($orders->id))->toEqual(new CallbackSubscription($orders->id, 'orders v2', $orders->url, ['order.created'], 'rotated', [], false, null))
        ->and($registry->find($billing->id))->toEqual($billing)
        ->and(array_map(static fn (CallbackSubscription $s): string => $s->name, $registry->all()))->toBe(['orders v2', 'billing'])
        ->and($registry->delete($billing->id))->toBeTrue()
        ->and($registry->delete($billing->id))->toBeFalse()
        ->and($registry->find('nope'))->toBeNull();
})->with('w4 subscription registries');

it('matches active subscriptions by event-type pattern only', function (Closure $make) {
    /** @var SubscriptionRegistry $registry */
    $registry = $make();
    $registry->save($all = CallbackSubscription::create('all orders', 'https://a.example.com', ['order.*']));
    $registry->save(CallbackSubscription::create('paid', 'https://b.example.com', ['invoice.paid']));
    $registry->save(new CallbackSubscription('off', 'paused', 'https://c.example.com', ['order.*'], null, [], false, null));

    expect(array_map(static fn (CallbackSubscription $s): string => $s->id, $registry->matching('order.created')))->toBe([$all->id])
        ->and($registry->matching('user.created'))->toBe([]);
})->with('w4 subscription registries');

it('seeds the memory registry and derives a stable id for a seed without one', function () {
    $seed = CallbackSubscription::fromArray(['url' => 'https://a.example.com/x', 'event-types' => ['a']], 'firefly.callbacks.subscriptions.0');

    expect($seed->id)->toBe(CallbackSubscription::fromArray(['url' => 'https://a.example.com/x', 'event-types' => ['a']], 'k')->id)
        ->and($seed->name)->toBe('a.example.com')
        ->and((new InMemorySubscriptionRegistry([$seed]))->all())->toEqual([$seed]);
});
