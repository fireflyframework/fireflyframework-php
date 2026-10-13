# Callbacks

`firefly/callbacks` sends webhooks. A subscription is a URL, the event types it wants and the secret its
deliveries are signed with; every [event-bus](eda.md) event whose type matches `firefly.callbacks.event-types` is
POSTed to each matching subscription, signed, retried with back-off, guarded by a circuit breaker per endpoint and
recorded in an execution log you can list and replay. Off until `firefly.callbacks.enabled` is true.

## Turning it on

<!-- illustrative: the keys an application writes into its own config/firefly.php -->

```php
// config/firefly.php
return [
    'callbacks' => [
        'enabled' => env('FIREFLY_CALLBACKS_ENABLED', true),
        'event-types' => ['order.*'],
        'subscriptions' => [
            ['id' => 'partner-orders', 'url' => 'https://partner.example.com/hooks', 'event-types' => ['order.created', 'order.shipped'], 'secret' => env('PARTNER_CALLBACK_SECRET')],
        ],
    ],
];
```

`event-types` is what the dispatcher subscribes to on the bus — one subscription per pattern, nothing at all while
the list is empty; each subscription then narrows it with its own patterns. On a broker provider, the worker binds
the destinations those events are published to with `firefly.eda.destinations`, as for any listener.

## Subscriptions

With `registry.store=memory` (the default) the subscriptions are the configured ones. With `database` they are
rows of `firefly_callback_subscriptions`, managed through the `SubscriptionRegistry` port:

<!-- illustrative: application code that registers a partner at runtime, e.g. from an admin action -->

```php
<?php

declare(strict_types=1);

namespace App\Partners;

use Firefly\Callbacks\Subscription\CallbackSubscription;
use Firefly\Callbacks\Subscription\SubscriptionRegistry;

final class RegisterPartner
{
    public function __construct(private readonly SubscriptionRegistry $subscriptions) {}

    public function __invoke(string $name, string $url, string $secret): string
    {
        $subscription = CallbackSubscription::create($name, $url, ['order.*'], $secret);
        $this->subscriptions->save($subscription);

        return $subscription->id;
    }
}
```

The secret is stored as given; encrypt it before `save()` if your threat model needs that.

## What a delivery looks like

A JSON `POST` with this body — fixed when the execution is created, so every attempt and every replay sends the
same bytes:

<!-- illustrative: the shape of the body a receiver gets; the values are an example event -->

```json
{"id": "9f2c…", "eventId": "4b1e…", "eventType": "order.created", "occurredAt": "2026-10-12T10:00:00+00:00", "data": {"id": 7}}
```

and these headers: `Content-Type: application/json`, `User-Agent: LaraFly-Callbacks`, `X-Firefly-Event`,
`X-Firefly-Event-Id`, `X-Firefly-Delivery` (the execution id — the receiver's idempotency key), the
subscription's own headers, and, when it has a secret, `X-Firefly-Timestamp: <unix>` and
`X-Firefly-Signature: sha256=<hex HMAC-SHA256(secret, "<timestamp>.<body>")>`. The timestamp is inside the
signature, so a captured delivery cannot be replayed later; a receiver on [firefly/webhooks](webhooks.md) verifies
it with the `hmac` validator and `timestamp-header: X-Firefly-Timestamp`.

## Retries, back-off and the circuit breaker

- 2xx is delivered. 408, 429, 5xx and transport errors are attempted again after `retry.initial-delay` ×
  `retry.multiplier`^(n−1), capped at `retry.max-delay`, up to `retry.max-attempts` (a subscription's own
  `max-attempts` wins). Any other status is the receiver's final answer: failed, not retried. Redirects are not
  followed.
- Each endpoint (`scheme://host:port`) has a [circuit breaker](resilience.md#circuitbreaker) on the application's
  resilience store: after `circuit-breaker.failure-threshold` failures it opens for
  `circuit-breaker.wait-duration-in-open`, and deliveries to that endpoint fail at once with `circuit open`
  instead of waiting on a partner that is down.
- URLs must be https unless `http.require-https` is false, and on a host in `http.allowed-hosts` (or a subdomain
  of one) when that list is not empty.
- Attempts run where the event is consumed. On `firefly.eda.provider=memory` that is the request that published
  the event — choose `queue` or a broker so deliveries run in workers.
- An event the broker redelivers maps to the same execution: one already delivered is not sent again, one that
  failed is attempted again.

## The execution log and the commands

`log.store=memory` keeps the deliveries of the process; `database` keeps them in `firefly_callback_executions`.

<!-- illustrative: operator commands; the ids come from the list output -->

```bash
php artisan firefly:callbacks:list
php artisan firefly:callbacks:list --executions --status=failed --limit=50
php artisan firefly:callbacks:replay --failed
php artisan firefly:callbacks:replay 9f2c4e…
```

`replay` re-sends the logged body re-signed with a fresh timestamp; its attempts keep counting, and it exits 1 if
any replay did not end delivered — schedule it and the scheduler's output says so.

<!-- illustrative: the commands that create the two tables for the database stores -->

```bash
php artisan vendor:publish --tag=firefly-callbacks-migrations
php artisan migrate
```

## Configuration (`firefly.callbacks.*`)

| Key | Default | Meaning |
|---|---|---|
| `firefly.callbacks.enabled` | `false` | Master gate. |
| `firefly.callbacks.event-types` | `[]` | Event-type patterns the dispatcher subscribes to. |
| `firefly.callbacks.registry.store` | `memory` | `memory` or `database`. |
| `firefly.callbacks.log.store` | `memory` | `memory` or `database`. |
| `firefly.callbacks.connection` | `null` | Connection for the database stores. |
| `firefly.callbacks.subscriptions` | `[]` | Subscriptions of the memory registry (`id`, `name`, `url`, `event-types`, `secret`, `headers`, `active`, `max-attempts`). |
| `firefly.callbacks.retry.max-attempts` | `3` | Attempts per delivery. |
| `firefly.callbacks.retry.initial-delay` | `1s` | First back-off. |
| `firefly.callbacks.retry.multiplier` | `2.0` | Back-off multiplier. |
| `firefly.callbacks.retry.max-delay` | `30s` | Back-off cap. |
| `firefly.callbacks.http.timeout` | `10s` | Per request. |
| `firefly.callbacks.http.require-https` | `true` | Refuse plain http URLs. |
| `firefly.callbacks.http.allowed-hosts` | `[]` | Host allow-list (suffix match); empty = any. |
| `firefly.callbacks.circuit-breaker.enabled` | `true` | One breaker per endpoint. |
| `firefly.callbacks.circuit-breaker.failure-threshold` | `5` | Failures that open it. |
| `firefly.callbacks.circuit-breaker.wait-duration-in-open` | `30s` | How long it stays open. |
| `firefly.callbacks.signature.header` | `X-Firefly-Signature` | Signature header name. |
| `firefly.callbacks.signature.timestamp-header` | `X-Firefly-Timestamp` | Timestamp header name. |
