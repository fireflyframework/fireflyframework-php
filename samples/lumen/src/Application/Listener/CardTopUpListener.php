<?php

declare(strict_types=1);

namespace Lumen\Application\Listener;

use Firefly\Container\Attributes\Component;
use Firefly\Cqrs\Command\CommandBus;
use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\EventEnvelope;
use Lumen\Application\Command\Deposit;

/**
 * Turns the card processor's "top-up succeeded" webhook into a Deposit. firefly/webhooks has already refused anything
 * unsigned, oversized, replayed or redelivered before this runs: what reaches the bus is one verified delivery,
 * published as webhook.{provider}.{type} — here webhook.card-processor.top_up.succeeded — with the decoded JSON body
 * under the payload's `body` key. A body that names no wallet or no positive amount is ignored rather than thrown:
 * the processor would only redeliver the same malformed body.
 *
 * A Deposit that fails (an unknown wallet) throws out of this listener; RetryingEventHandler then stores the envelope
 * in the bound DeadLetterStore, so `php artisan firefly:eda:dead-letters:replay` can deliver it again once the cause
 * is fixed.
 */
#[Component]
final class CardTopUpListener
{
    public function __construct(private readonly CommandBus $commands) {}

    #[EventListener('webhook.card-processor.top_up.succeeded')]
    public function onTopUp(EventEnvelope $envelope): void
    {
        $body = $envelope->payload['body'] ?? null;
        $data = is_array($body) ? ($body['data'] ?? null) : null;
        $walletId = is_array($data) ? ($data['wallet_id'] ?? null) : null;
        $amountMinor = is_array($data) ? ($data['amount_minor'] ?? null) : null;

        if (! is_string($walletId) || ! is_int($amountMinor) || $amountMinor <= 0) {
            return;
        }

        $this->commands->send(new Deposit($walletId, $amountMinor));
    }
}
