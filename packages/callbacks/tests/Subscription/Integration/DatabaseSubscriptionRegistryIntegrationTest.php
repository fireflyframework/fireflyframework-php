<?php

declare(strict_types=1);

use Firefly\Callbacks\Schema\CallbackSchema;
use Firefly\Callbacks\Subscription\CallbackSubscription;
use Firefly\Callbacks\Subscription\DatabaseSubscriptionRegistry;
use Firefly\Testing\Integration\IntegrationDsn;

foreach (IntegrationDsn::lanes() as $lane) {
    it("round-trips subscriptions, re-saves unchanged ones and matches patterns on {$lane}", function () use ($lane): void {
        $connection = IntegrationDsn::connect($lane, 'callback-subscriptions', $lane === 'sqlite' ? ['database' => IntegrationDsn::sqlitePath('callback-subscriptions')] : []);
        CallbackSchema::drop($connection->getSchemaBuilder());
        CallbackSchema::create($connection->getSchemaBuilder());
        CallbackSchema::create($connection->getSchemaBuilder());
        $registry = new DatabaseSubscriptionRegistry($connection);

        try {
            $orders = CallbackSubscription::create('órdenes', 'https://partner.example.com/hooks?x=1', ['order.*', 'refund.created'], 'sécret', ['X-Partner' => 'p1'], 4);
            $registry->save($orders);
            $registry->save($orders); // unchanged: MySQL reports 0 affected rows, which must not insert a duplicate
            $registry->save(new CallbackSubscription('UPPER', 'case', 'https://a.example.com', ['a'], null, [], true, null));
            $registry->save(new CallbackSubscription('upper', 'case', 'https://b.example.com', ['a'], null, [], false, null));

            expect($registry->find($orders->id))->toEqual($orders)
                ->and($registry->all())->toHaveCount(3)
                ->and($registry->find('UPPER')?->url)->toBe('https://a.example.com')
                ->and($registry->find('upper')?->url)->toBe('https://b.example.com')
                ->and(array_map(static fn (CallbackSubscription $s): string => $s->id, $registry->matching('refund.created')))->toBe([$orders->id])
                ->and($registry->matching('a'))->toHaveCount(1);
        } finally {
            CallbackSchema::drop($connection->getSchemaBuilder());
        }
    })->group('integration')->skip(IntegrationDsn::skipReason($lane) !== null, (string) IntegrationDsn::skipReason($lane));
}
