# Webhooks

`firefly/webhooks` receives webhooks. Every provider you configure gets an endpoint, `POST /webhooks/{provider}`,
that refuses an oversized body, verifies the provider's signature, holds a signed timestamp to a replay window,
answers a redelivery as a duplicate, and publishes everything it accepts to the [event bus](eda.md) — so the code
that reacts to a Stripe payment or a GitHub push is an ordinary `#[EventListener]`, with the bus's retries, dead
letters and brokers behind it. Off until `firefly.webhooks.enabled` is true.

## Turning it on

<!-- illustrative: the keys an application writes into its own config/firefly.php; the shipped reference carries them commented out -->

```php
// config/firefly.php
return [
    'webhooks' => [
        'enabled' => env('FIREFLY_WEBHOOKS_ENABLED', true),
        'idempotency' => ['store' => 'database'],
        'providers' => [
            'stripe' => ['validator' => 'stripe', 'secret' => env('STRIPE_WEBHOOK_SECRET')],
            'github' => ['validator' => 'github', 'secret' => env('GITHUB_WEBHOOK_SECRET')],
            'twilio' => ['validator' => 'twilio', 'secret' => env('TWILIO_AUTH_TOKEN'), 'public-url' => 'https://api.example.com/webhooks/twilio'],
        ],
    ],
];
```

The array key is the provider id and the last segment of its URL: the Stripe endpoint above is
`https://your-app/webhooks/stripe`. A signing provider without a secret refuses the boot, naming the key.

## Providers

| `validator` | Signature | Replay protection | Idempotency key | Event type |
|---|---|---|---|---|
| `hmac` (default) | `X-Signature: sha256=<hex HMAC of the body>` — header, prefix, algorithm and hex/base64 encoding configurable; with `timestamp-header` the signed payload is `"<timestamp>.<body>"` | the timestamp, when `timestamp-header` is set | `X-Idempotency-Key` | body field `type` |
| `stripe` | `Stripe-Signature: t=…,v1=…` (any `v1` may match while a secret is rolled) | `t`, inside `tolerance` | body field `id` | body field `type` |
| `github` | `X-Hub-Signature-256: sha256=…` | none signed — the idempotency key | `X-GitHub-Delivery` | `X-GitHub-Event` |
| `twilio` | `X-Twilio-Signature`, base64 HMAC-SHA1 over the URL and the sorted form parameters; a JSON callback is checked through its `bodySHA256` parameter | none signed — the idempotency key | `I-Twilio-Idempotency-Token` | form field `EventType` |
| `none` | not verified — for a sender on a network you trust | — | `X-Idempotency-Key` | body field `type` |

Every column is a default: `signature-header`, `signature-prefix`, `algorithm`, `encoding`, `timestamp-header`,
`tolerance`, `idempotency-header`, `idempotency-field`, `event-type-header`, `event-type-field`, `destination` and
`public-url` override it per provider. Fields are dotted paths into the decoded body (`data.object.id`).

## What the endpoint answers

| Status | When | Body |
|---|---|---|
| 202 | accepted and published | `{"status": "accepted", "id", "provider", "eventType", "destination", "idempotencyKey"}` |
| 200 | a delivery already accepted (same idempotency key within `idempotency.ttl`) | `{"status": "duplicate", "provider", "idempotencyKey"}` |
| 400 `WEBHOOK_PAYLOAD_INVALID` | the body says it is JSON and is not a JSON object | problem+json |
| 401 `WEBHOOK_SIGNATURE_INVALID` | missing or wrong signature | problem+json |
| 401 `WEBHOOK_TIMESTAMP_OUT_OF_TOLERANCE` | a signed timestamp outside the replay window | problem+json |
| 404 `WEBHOOK_PROVIDER_NOT_FOUND` | no such provider | problem+json |
| 413 `WEBHOOK_PAYLOAD_TOO_LARGE` | above `max-body-bytes` | problem+json |
| 503 `WEBHOOK_PUBLISH_FAILED` | verified, but the bus refused the publish — the claim is released, so the provider's retry is processed | problem+json |

Refusals are rendered by the web layer's problem renderer whatever the sender's `Accept` header says; every 4xx
refusal carries the provider id as a `provider` member, and a 503 is also reported to the exception handler so it
reaches the logs. The route is mounted without a middleware group — no session, no CSRF
token a provider could never send. With firefly/security's `HttpSecurity` on, permit `webhooks/**`: the
signature is the authentication.

## What reaches the event bus

An accepted webhook is published as `webhook.{provider}.{type}` (`firefly.webhooks.event-type`) on
`webhooks.{provider}` (`firefly.webhooks.destination`, or the provider's own `destination`), with the payload
`id`, `provider`, `type`, `receivedAt`, `idempotencyKey`, `headers` (without `authorization`,
`proxy-authorization` and `cookie`) and `body` — the decoded JSON object, the form parameters, or `raw` for
anything else (`rawBase64` when it is not UTF-8). The event type is `unknown` when neither the provider's header
nor its field names one, and the envelope carries `x-webhook-provider` and `x-webhook-event-id` headers.

<!-- illustrative: an application's own listener; the class lives in the reader's app/ tree -->

```php
<?php

declare(strict_types=1);

namespace App\Billing;

use Firefly\Container\Attributes\Component;
use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\EventEnvelope;

#[Component]
final class StripePayments
{
    #[EventListener('webhook.stripe.payment_intent.*', destinations: 'webhooks.stripe')]
    public function onPaymentIntent(EventEnvelope $envelope): void
    {
        $intent = $envelope->payload['body']['data']['object'] ?? [];
        // record the payment …
    }
}
```

On `firefly.eda.provider=memory` the listener runs inside the webhook request; on `queue` or a broker it runs in
a worker and the endpoint answers as soon as the event is on the bus.

## Idempotency

`firefly.webhooks.idempotency.store` picks where accepted delivery ids are remembered for `idempotency.ttl`:

| Store | Backing | Notes |
|---|---|---|
| `cache` (default) | the Laravel cache store `idempotency.cache-store` names (`''` = default), `add()` with a TTL | atomic on redis, memcached, database and array stores |
| `database` | `firefly_webhook_receipts` on `idempotency.connection` | portable table, primary key (provider, key), case-sensitive keys on every driver; run the migration and prune it daily |
| `memory` | the process | one process only — under PHP-FPM it forgets every request |

<!-- illustrative: the commands an application runs once and on a schedule -->

```bash
php artisan vendor:publish --tag=firefly-webhooks-migrations
php artisan migrate
php artisan firefly:webhooks:prune
```

A claim is atomic: two simultaneous deliveries of one webhook produce one 202 and one 200, never two publishes.

## Behind a proxy

A Twilio signature covers the exact URL Twilio called. When a load balancer rewrites the host or the scheme, set
the provider's `public-url` to the public endpoint; the query string of the request is appended to it.

## Receiving firefly/callbacks deliveries

A LaraFly application that sends [callbacks](callbacks.md) signs them the way the `hmac` validator checks with a
timestamp header, so the receiving side is configuration only:

<!-- illustrative: the provider entry a receiving application writes into its own config/firefly.php -->

```php
// config/firefly.php
return [
    'webhooks' => [
        'enabled' => true,
        'providers' => [
            'orders-service' => [
                'secret' => env('ORDERS_CALLBACK_SECRET'),
                'signature-header' => 'X-Firefly-Signature',
                'timestamp-header' => 'X-Firefly-Timestamp',
                'idempotency-header' => 'X-Firefly-Delivery',
                'event-type-header' => 'X-Firefly-Event',
            ],
        ],
    ],
];
```

## Configuration (`firefly.webhooks.*`)

| Key | Default | Meaning |
|---|---|---|
| `firefly.webhooks.enabled` | `false` | Master gate: no route, no bean while off. |
| `firefly.webhooks.path` | `webhooks` | Route prefix. |
| `firefly.webhooks.max-body-bytes` | `1048576` | Larger bodies answer 413 before any signature work. |
| `firefly.webhooks.destination` | `webhooks.{provider}` | EDA destination template. |
| `firefly.webhooks.event-type` | `webhook.{provider}.{type}` | EDA event-type template. |
| `firefly.webhooks.replay.tolerance` | `5m` | Default replay window for providers that sign a timestamp. |
| `firefly.webhooks.idempotency.store` | `cache` | `memory`, `cache` or `database`. |
| `firefly.webhooks.idempotency.ttl` | `24h` | How long an accepted delivery id is remembered. |
| `firefly.webhooks.idempotency.cache-store` | `''` | Cache store for `cache` (`''` = default). |
| `firefly.webhooks.idempotency.connection` | `null` | Connection for `database` (`null` = default). |
| `firefly.webhooks.providers.<id>.*` | — | See [Providers](#providers). |
