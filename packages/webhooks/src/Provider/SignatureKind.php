<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Provider;

/**
 * How a provider signs its webhooks, and the headers it is known to send. The defaults are each provider's own
 * documented header names, so `['validator' => 'github', 'secret' => …]` is a complete configuration; every
 * default can be overridden per provider.
 *
 *   * hmac   — a generic HMAC over the raw body (or "<timestamp>.<body>" when a timestamp header is configured).
 *   * stripe — `Stripe-Signature: t=…,v1=…`, HMAC-SHA256 over "<t>.<body>", with a replay window.
 *   * github — `X-Hub-Signature-256: sha256=…`, HMAC-SHA256 over the body.
 *   * twilio — `X-Twilio-Signature`, base64(HMAC-SHA1) over the URL plus the sorted form parameters.
 *   * none   — no verification at all; an explicit choice for a sender on a private network.
 */
enum SignatureKind: string
{
    case Hmac = 'hmac';
    case Stripe = 'stripe';
    case GitHub = 'github';
    case Twilio = 'twilio';
    case None = 'none';

    /**
     * @return array{signature-header: string, signature-prefix: string, idempotency-header: string, idempotency-field: string, event-type-header: string, event-type-field: string}
     */
    public function defaults(): array
    {
        return match ($this) {
            self::Hmac => ['signature-header' => 'X-Signature', 'signature-prefix' => 'sha256=', 'idempotency-header' => 'X-Idempotency-Key', 'idempotency-field' => '', 'event-type-header' => '', 'event-type-field' => 'type'],
            self::Stripe => ['signature-header' => 'Stripe-Signature', 'signature-prefix' => '', 'idempotency-header' => '', 'idempotency-field' => 'id', 'event-type-header' => '', 'event-type-field' => 'type'],
            self::GitHub => ['signature-header' => 'X-Hub-Signature-256', 'signature-prefix' => 'sha256=', 'idempotency-header' => 'X-GitHub-Delivery', 'idempotency-field' => '', 'event-type-header' => 'X-GitHub-Event', 'event-type-field' => ''],
            self::Twilio => ['signature-header' => 'X-Twilio-Signature', 'signature-prefix' => '', 'idempotency-header' => 'I-Twilio-Idempotency-Token', 'idempotency-field' => '', 'event-type-header' => '', 'event-type-field' => 'EventType'],
            self::None => ['signature-header' => '', 'signature-prefix' => '', 'idempotency-header' => 'X-Idempotency-Key', 'idempotency-field' => '', 'event-type-header' => '', 'event-type-field' => 'type'],
        };
    }
}
