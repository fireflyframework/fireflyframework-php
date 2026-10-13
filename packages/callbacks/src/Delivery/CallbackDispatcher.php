<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Delivery;

use Closure;
use Firefly\Callbacks\Execution\CallbackExecution;
use Firefly\Callbacks\Execution\CallbackStatus;
use Firefly\Callbacks\Execution\ExecutionLog;
use Firefly\Callbacks\Subscription\SubscriptionRegistry;
use Firefly\Eda\EventEnvelope;

/**
 * Fans one EDA envelope out to every matching subscription. The execution is logged PENDING before the first
 * attempt, so a worker killed mid-delivery leaves a trace, and logged again with its outcome. An envelope the broker
 * redelivers maps to the same execution id: one already DELIVERED is not sent again; one that FAILED is attempted
 * again — the redelivery is a free retry. A registry or log failure propagates, so the event bus's own retry and
 * dead-letter policy applies; a failing endpoint never does, it is recorded on the execution.
 */
final class CallbackDispatcher
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param  (Closure(): int)|null  $clock  unix seconds
     */
    public function __construct(
        private readonly SubscriptionRegistry $subscriptions,
        private readonly ExecutionLog $log,
        private readonly CallbackDeliverer $deliverer,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return list<CallbackExecution>
     */
    public function dispatch(EventEnvelope $envelope): array
    {
        $results = [];

        foreach ($this->subscriptions->matching($envelope->eventType) as $subscription) {
            $existing = $this->log->find(CallbackExecution::idFor($subscription->id, $envelope->eventId));
            if ($existing !== null && $existing->status === CallbackStatus::Delivered) {
                $results[] = $existing;

                continue;
            }

            $execution = $existing ?? CallbackExecution::pending($subscription, $envelope, ($this->clock)());
            $this->log->record($execution);

            $execution = $this->deliverer->deliver($subscription, $execution);
            $this->log->record($execution);
            $results[] = $execution;
        }

        return $results;
    }

    /**
     * Sends a logged execution again (`firefly:callbacks:replay`); null when its subscription no longer exists. A
     * deactivated subscription is refused by the deliverer (failed, "is inactive") and the log is left as it was,
     * unless $includeInactive is set.
     */
    public function replay(CallbackExecution $execution, bool $includeInactive = false): ?CallbackExecution
    {
        $subscription = $this->subscriptions->find($execution->subscriptionId);
        if ($subscription === null) {
            return null;
        }

        $result = $this->deliverer->deliver($subscription, $execution, $includeInactive);
        if ($subscription->active || $includeInactive) {
            $this->log->record($result);
        }

        return $result;
    }
}
