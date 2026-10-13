<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Subscription;

/**
 * registry.store=memory (the default): the subscriptions firefly.callbacks.subscriptions declares, plus any the
 * process saves. Configuration is the source of truth here; a subscription saved at runtime lasts as long as the
 * process.
 */
final class InMemorySubscriptionRegistry implements SubscriptionRegistry
{
    /** @var array<string, CallbackSubscription> */
    private array $subscriptions = [];

    /**
     * @param  list<CallbackSubscription>  $seed
     */
    public function __construct(array $seed = [])
    {
        foreach ($seed as $subscription) {
            $this->save($subscription);
        }
    }

    public function save(CallbackSubscription $subscription): void
    {
        $this->subscriptions[$subscription->id] = $subscription;
    }

    public function find(string $id): ?CallbackSubscription
    {
        return $this->subscriptions[$id] ?? null;
    }

    public function delete(string $id): bool
    {
        if (! isset($this->subscriptions[$id])) {
            return false;
        }

        unset($this->subscriptions[$id]);

        return true;
    }

    public function all(): array
    {
        return array_values($this->subscriptions);
    }

    public function matching(string $eventType): array
    {
        return array_values(array_filter($this->subscriptions, static fn (CallbackSubscription $s): bool => $s->matches($eventType)));
    }
}
