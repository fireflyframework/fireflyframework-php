<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Subscription;

/** Where callback subscriptions live. matching() is what the dispatcher asks on every event. */
interface SubscriptionRegistry
{
    /** Inserts, or replaces the subscription with the same id. */
    public function save(CallbackSubscription $subscription): void;

    public function find(string $id): ?CallbackSubscription;

    public function delete(string $id): bool;

    /** @return list<CallbackSubscription> in the order they were first saved */
    public function all(): array;

    /** @return list<CallbackSubscription> the active subscriptions one of whose patterns matches $eventType */
    public function matching(string $eventType): array;
}
