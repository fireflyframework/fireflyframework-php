<span class="eyebrow">Part III — Coordinating and Securing the Application · Chapter 8A</span>

# Webhooks and Callbacks: Receiving and Sending Signed Events {.chtitle}

By the end of this chapter you will know how a LaraFly application receives a webhook and how it sends one, and why both directions end on the event bus Chapter 8 built. On the way in, `firefly/webhooks` gives every configured provider an endpoint, `POST /webhooks/{provider}`, that refuses an oversized body, verifies the signature (`hmac`, `stripe`, `github`, `twilio` or `none`), holds a signed timestamp to a replay window, answers a redelivery as a `duplicate`, and publishes what it accepts as `webhook.{provider}.{type}` — so Lumen's card top-up is an ordinary `#[EventListener]`. On the way out, `firefly/callbacks` POSTs every bus event a partner subscribed to, signed with `X-Firefly-Timestamp` and `X-Firefly-Signature`, retried with back-off behind a circuit breaker per endpoint, and recorded in an execution log you can list and replay with `firefly:callbacks:list` and `firefly:callbacks:replay`.

!!! note "New term: webhook"
    A **webhook** is an HTTP request one system makes to another to say that something happened — a card payment succeeded, a pull request merged. The receiver did not ask at that moment; it registered a URL once, and the sender calls it. Two things follow from that, and this chapter is mostly about them: the receiver must prove the request really came from the sender (a signature over the exact bytes), and it must expect the same event more than once (senders retry until they see a 2xx).

---

## Two packages, one bus

`firefly/webhooks` is the inbound half and `firefly/callbacks` the outbound one, and neither knows about the other. What they share is the bus: an accepted webhook becomes an `EventEnvelope` published through the `EventPublisher` port, and a callback is triggered by an envelope arriving on it. Everything Chapter 8 said about that bus — `eventType` matching, retries, dead letters, the outbox, brokers — therefore applies to both halves without a line of extra code. Both are off until their master key is set, `firefly.webhooks.enabled` and `firefly.callbacks.enabled`, and both ship in the root library.

---

## Receiving: a signed endpoint per provider

The reference configuration shows the inbound block with every optional key commented out at its default:

<!-- source: skeleton/config/firefly.php -->
```php
'webhooks' => [
    'enabled' => env('FIREFLY_WEBHOOKS_ENABLED', false),

    // 'path' => 'webhooks',
    // 'max-body-bytes' => 1048576,
    // 'destination' => 'webhooks.{provider}',
    // 'event-type' => 'webhook.{provider}.{type}',
    // 'replay' => ['tolerance' => '5m'],
    // 'idempotency' => [
    //     'store' => env('FIREFLY_WEBHOOKS_IDEMPOTENCY_STORE', 'cache'),
    //     'ttl' => '24h',
    //     'cache-store' => '',
    //     'connection' => null,
    // ],
    // 'providers' => [
    //     'stripe' => ['validator' => 'stripe', 'secret' => env('STRIPE_WEBHOOK_SECRET')],
    //     'github' => ['validator' => 'github', 'secret' => env('GITHUB_WEBHOOK_SECRET')],
    //     'twilio' => ['validator' => 'twilio', 'secret' => env('TWILIO_AUTH_TOKEN'), 'public-url' => 'https://api.example.com/webhooks/twilio'],
    //     'partner' => ['secret' => env('PARTNER_WEBHOOK_SECRET'), 'timestamp-header' => 'X-Firefly-Timestamp', 'signature-header' => 'X-Firefly-Signature', 'idempotency-header' => 'X-Firefly-Delivery'],
    // ],
],
```

The array key under `providers` is the provider id and the last segment of its URL: `stripe` is answered at `/webhooks/stripe`. A signing provider without a secret refuses the boot and names the key. Lumen has one provider, the card processor that tops wallets up, and it uses the default `hmac` validator, so the whole configuration is a secret:

<!-- source: samples/lumen/tests/Web/LumenWebhooksTestCase.php -->
```php
            'firefly.webhooks.enabled' => true,
            'firefly.webhooks.idempotency.store' => 'memory',
            'firefly.webhooks.providers' => [
                'card-processor' => ['secret' => self::SECRET],
            ],
```

Each validator knows where its provider puts the signature, what it signs, which field names the event and which value identifies a delivery:

| `validator` | Signature | Replay protection | Idempotency key | Event type |
|---|---|---|---|---|
| `hmac` (default) | `X-Signature: sha256=<hex HMAC of the body>`; with `timestamp-header` the signed payload is `"<timestamp>.<body>"` | the timestamp, when `timestamp-header` is set | `X-Idempotency-Key` | body field `type` |
| `stripe` | `Stripe-Signature: t=…,v1=…` (any `v1` may match while a secret is rolled) | `t`, inside `tolerance` | body field `id` | body field `type` |
| `github` | `X-Hub-Signature-256: sha256=…` | none signed — the idempotency key | `X-GitHub-Delivery` | `X-GitHub-Event` |
| `twilio` | `X-Twilio-Signature`, base64 HMAC-SHA1 over the URL and the sorted form parameters | none signed — the idempotency key | `I-Twilio-Idempotency-Token` | form field `EventType` |
| `none` | not verified — for a sender on a network you trust | — | `X-Idempotency-Key` | body field `type` |

Every column is a default a provider entry can override (`signature-header`, `signature-prefix`, `algorithm`, `encoding`, `timestamp-header`, `tolerance`, `idempotency-header`, `idempotency-field`, `event-type-header`, `event-type-field`, `destination`, `public-url`). The route is mounted without a middleware group — no session and no CSRF token a provider could never send — and when `HttpSecurity` is on you permit `webhooks/**`, because the signature is the authentication.

---

## What the endpoint answers

| Status | When |
|---|---|
| 202 `accepted` | verified and published |
| 200 `duplicate` | a delivery already accepted (same idempotency key within `idempotency.ttl`) |
| 400 `WEBHOOK_PAYLOAD_INVALID` | the body says it is JSON and is not a JSON object |
| 401 `WEBHOOK_SIGNATURE_INVALID` | a missing or wrong signature |
| 401 `WEBHOOK_TIMESTAMP_OUT_OF_TOLERANCE` | a signed timestamp outside the replay window |
| 404 `WEBHOOK_PROVIDER_NOT_FOUND` | no such provider |
| 413 `WEBHOOK_PAYLOAD_TOO_LARGE` | above `max-body-bytes`, refused before any signature work |
| 503 `WEBHOOK_PUBLISH_FAILED` | verified, but the bus refused the publish — the idempotency claim is released, so the provider's retry is processed |

Every refusal is problem+json whatever the sender's `Accept` header says, and every 4xx names the provider in a `provider` member, so a misconfigured secret shows up in your logs with the provider attached.

---

## Lumen's card top-up

An accepted delivery is published as `webhook.{provider}.{type}` on `webhooks.{provider}`, with the decoded body under the payload's `body` key. For the card processor's `top_up.succeeded` that is `webhook.card-processor.top_up.succeeded`, and the code that reacts to it is a listener like `LedgerProjector`:

<!-- source: samples/lumen/src/Application/Listener/CardTopUpListener.php -->
```php
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
```

The listener never checks a signature, a timestamp or a duplicate: by the time an envelope reaches the bus, all three have been settled. What it does is the domain's work — one `Deposit`, through the same bus, the same `#[Transactional]` handler and the same domain events as a deposit made over REST. The sample's test sends the delivery twice, the way a processor that missed the first 202 would:

<!-- source: samples/lumen/tests/Web/CardTopUpWebhookTest.php -->
```php
    $this->postWebhook('/webhooks/card-processor', $body, lumenTopUpHeaders($body, 'evt_1'))
        ->assertStatus(202)
        ->assertJson(['status' => 'accepted', 'provider' => 'card-processor', 'eventType' => 'webhook.card-processor.top_up.succeeded']);

    // The processor never saw the 202 and delivers the same event again: answered, not deposited twice.
    $this->postWebhook('/webhooks/card-processor', $body, lumenTopUpHeaders($body, 'evt_1'))
        ->assertStatus(200)
        ->assertJson(['status' => 'duplicate']);

    $this->getJson("/api/v1/wallets/{$id}/balance")->assertOk()->assertJson(['balance_minor' => 2500]);
```

`postWebhook()` is the harness's raw POST: a signature covers bytes, so a helper that re-encodes the JSON would sign something the endpoint never receives. The second test in the file changes one digit of the amount after signing and gets a 401 `WEBHOOK_SIGNATURE_INVALID` with the balance untouched.

!!! tip "A failing listener is a dead letter, not a 5xx"
    The third test sends a verified top-up for a wallet that does not exist. The `Deposit` throws, the listener throws — and the endpoint still answers 202, because on the in-memory bus every listener runs inside `RetryingEventHandler`, and a `DeadLetterStore` is always bound: the envelope is stored there, not lost. That is the right answer to give the processor (its delivery was valid; retrying it would fail the same way), and the right place to leave the problem: fix the cause, then `php artisan firefly:eda:dead-letters:replay --event-type=webhook.card-processor.top_up.succeeded` delivers it again. With `firefly.eda.dead-letter.store=database` (Chapter 8) the dead letter survives a restart.

<!-- source: samples/lumen/tests/Web/CardTopUpWebhookTest.php -->
```php
    $entries = $this->app()->make(DeadLetterStore::class)->all();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->envelope->eventType)->toBe('webhook.card-processor.top_up.succeeded')
        ->and($entries[0]->exceptionMessage)->toContain('no-such-wallet');
```

---

## Remembering a delivery: idempotency

`firefly.webhooks.idempotency.store` picks where accepted delivery ids are remembered for `idempotency.ttl` (24 hours by default). The claim is atomic, so two simultaneous deliveries of one webhook produce one 202 and one 200, never two publishes:

| Store | Backing | Notes |
|---|---|---|
| `cache` (default) | the Laravel cache store `idempotency.cache-store` names, `add()` with a TTL | atomic on redis, memcached, database and array stores |
| `database` | `firefly_webhook_receipts` on `idempotency.connection` | portable table, primary key (provider, key), case-sensitive on every driver; prune it daily |
| `memory` | the process | one process only — Lumen's tests use it because one test is one process |

<!-- illustrative: the commands an application runs once, and then on a schedule, for the database store -->
```bash
php artisan vendor:publish --tag=firefly-webhooks-migrations
php artisan migrate
php artisan firefly:webhooks:prune
```

Behind a proxy, set the provider's `public-url`: a Twilio signature covers the exact URL Twilio called, and a load balancer that rewrites the host or the scheme would otherwise make every signature look forged.

---

## Sending: callbacks to the partners who asked

The outbound half starts from the same reference file:

<!-- source: skeleton/config/firefly.php -->
```php
'callbacks' => [
    'enabled' => env('FIREFLY_CALLBACKS_ENABLED', false),

    // 'event-types' => ['order.*', 'invoice.paid'],
    // 'registry' => ['store' => env('FIREFLY_CALLBACKS_REGISTRY', 'memory')],
    // 'log' => ['store' => env('FIREFLY_CALLBACKS_LOG', 'memory')],
    // 'connection' => null,
    // 'subscriptions' => [
    //     ['id' => 'partner-orders', 'url' => 'https://partner.example.com/hooks', 'event-types' => ['order.*'], 'secret' => env('PARTNER_CALLBACK_SECRET')],
    // ],
    // …
],
```

`event-types` is what the dispatcher subscribes to on the bus — one subscription per pattern, nothing at all while the list is empty — and each subscription then narrows it with its own patterns. Lumen's partner, a reconciliation service, wants to hear about every movement of money and nothing else:

<!-- source: samples/lumen/tests/Application/LumenCallbacksTestCase.php -->
```php
            'firefly.callbacks.enabled' => true,
            'firefly.callbacks.event-types' => ['Funds*'],
            'firefly.callbacks.retry.initial-delay' => 0,
            'firefly.callbacks.subscriptions' => [[
                'id' => 'partner-ledger',
                'name' => 'partner ledger',
                'url' => 'https://partner.example.com/hooks/wallet',
                'event-types' => ['FundsDeposited', 'FundsWithdrawn'],
                'secret' => self::SECRET,
            ]],
```

The patterns match the envelope's `eventType`, exactly as `#[EventListener]` does, and Lumen's domain events reach the bus as their short class names — the lesson `LedgerProjector` taught in Chapter 8 applies unchanged. With `registry.store=database`, subscriptions are rows of `firefly_callback_subscriptions`, managed at runtime through the `SubscriptionRegistry` port instead of configuration.

---

## What a delivery looks like

A delivery is a JSON `POST` whose body is fixed when the execution is created, so every attempt and every replay sends the same bytes:

<!-- illustrative: the shape of the body a partner receives; the values are an example FundsDeposited -->
```json
{"id": "9f2c…", "eventId": "4b1e…", "eventType": "FundsDeposited", "occurredAt": "2026-10-13T10:00:00+00:00", "data": {"walletId": "w-1", "amountMinor": 700, "currency": "EUR", "balanceMinor": 700}}
```

The headers are `Content-Type: application/json`, `User-Agent: LaraFly-Callbacks`, `X-Firefly-Event`, `X-Firefly-Event-Id`, `X-Firefly-Delivery` (the execution id — the receiver's idempotency key), the subscription's own headers, and, when it has a secret, `X-Firefly-Timestamp: <unix>` and `X-Firefly-Signature: sha256=<hex HMAC-SHA256(secret, "<timestamp>.<body>")>`. The timestamp is inside the signature, so a captured delivery cannot be replayed later. Lumen's test checks the signature the way the partner would, with the webhooks package's own `hmac` validator:

<!-- source: samples/lumen/tests/Application/PartnerCallbacksTest.php -->
```php
        // The partner's own check, as a LaraFly receiver on firefly/webhooks would run it: throws on a bad signature
        // or a timestamp outside the five-minute window.
        (new HmacSignatureValidator(LumenCallbacksTestCase::SECRET, 'X-Firefly-Signature', 'sha256=', 'sha256', 'hex', 'X-Firefly-Timestamp', 300.0))
            ->verify(new WebhookRequest('lumen', 'POST', $request->url(), $headers, $request->body(), [], 'application/json', time()));
```

So a LaraFly partner receives a LaraFly callback with configuration only:

<!-- illustrative: the provider entry a receiving application writes into its own config/firefly.php -->
```php
// config/firefly.php
return [
    'webhooks' => [
        'enabled' => true,
        'providers' => [
            'lumen' => [
                'secret' => env('LUMEN_CALLBACK_SECRET'),
                'signature-header' => 'X-Firefly-Signature',
                'timestamp-header' => 'X-Firefly-Timestamp',
                'idempotency-header' => 'X-Firefly-Delivery',
                'event-type-header' => 'X-Firefly-Event',
            ],
        ],
    ],
];
```

---

## Retries, the circuit breaker and the execution log

- **What counts as delivered.** A 2xx. A 408, 429, 5xx or transport error is attempted again after `retry.initial-delay` × `retry.multiplier`^(n−1), capped at `retry.max-delay`, up to `retry.max-attempts` (a subscription's own `max-attempts` wins). Any other status is the receiver's final answer: failed, not retried. Redirects are not followed.
- **A breaker per endpoint.** Each `scheme://host:port` has a `firefly/resilience` circuit breaker: after `circuit-breaker.failure-threshold` failures it opens for `circuit-breaker.wait-duration-in-open`, and deliveries to that endpoint fail at once with `circuit open` instead of waiting on a partner that is down.
- **Where URLs may point.** https only unless `http.require-https` is false, and only to a host in `http.allowed-hosts` (or a subdomain of one) when that list is set. The URL checked and sent to is always the subscription's current one.
- **Where attempts run.** Wherever the event is consumed. On the in-memory bus that is the request that published the event — Lumen's test sees the POST before `send()` returns — so choose `queue`, a broker or the outbox (Chapter 8) to move deliveries into workers. A redelivered event maps to the same execution: one already delivered is not sent again.

`log.store=memory` keeps the deliveries of the process; `database` keeps them in `firefly_callback_executions`, and the two commands read it:

<!-- illustrative: operator commands; the id comes from the list output -->
```bash
php artisan firefly:callbacks:list --executions --status=failed --limit=50
php artisan firefly:callbacks:replay --failed
php artisan firefly:callbacks:replay 9f2c4e7a-1b2c-4d5e-8f90-a1b2c3d4e5f6
```

A replay re-sends the logged body re-signed with a fresh timestamp, to the subscription's **current** URL, through the same https and allowed-host checks; it refuses an inactive subscription unless `--include-inactive` is passed, and it exits 1 if any replay did not end delivered, so a scheduled replay reports its own failures.

!!! laravel "Laravel parity"
    Laravel has no inbound-webhook primitive: the idiom is a controller per provider, a hand-written signature check, and a `processed_webhooks` table you remember to consult — Cashier ships one such controller for Stripe alone. Outbound, the idiom is a queued job per event with `Http::post()` and your own retry and signing code. `firefly/webhooks` and `firefly/callbacks` are those idioms made into configuration, with the hard parts — constant-time signature checks over raw bytes, an atomic idempotency claim, a breaker per endpoint, a replayable log — written once.

---

## What you learned {.recap}

| Concept | What it does |
|---|---|
| `POST /webhooks/{provider}` | One endpoint per configured provider: size limit, signature, replay window, idempotency, then a publish to the bus |
| Validators | `hmac` (default), `stripe`, `github`, `twilio`, `none` — each knows its provider's signature, idempotency key and event type |
| `webhook.{provider}.{type}` | The event type an accepted delivery is published as; the reacting code is an ordinary `#[EventListener]` |
| Idempotency store | `cache` (default), `database` (`firefly_webhook_receipts`) or `memory`; an atomic claim, so a redelivery is a 200 `duplicate` |
| A failing listener | Dead-lettered by the bus, the delivery still 202; `firefly:eda:dead-letters:replay` delivers it again |
| `firefly.callbacks.subscriptions` / `SubscriptionRegistry` | Who receives which event types, from configuration (`memory`) or rows (`database`) |
| `X-Firefly-Timestamp` / `X-Firefly-Signature` | HMAC-SHA256 over `"<timestamp>.<body>"` — exactly what the `hmac` validator checks |
| Retries, breaker, log | Back-off on 408/429/5xx, a circuit breaker per endpoint, an execution log read by `firefly:callbacks:list` and `firefly:callbacks:replay` |

---

## Try it yourself {.exercises}

1. **Forge a delivery.** In `CardTopUpWebhookTest`, sign the body with a different secret and confirm the 401 names the provider in its `provider` member.
2. **Add a second provider.** Give `LumenWebhooksTestCase` a `stripe` provider (`'validator' => 'stripe'`) and write a test that sends a `Stripe-Signature: t=…,v1=…` header built with `hash_hmac('sha256', "{$t}.{$body}", $secret)`. Then move `t` ten minutes into the past and confirm the 401 is `WEBHOOK_TIMESTAMP_OUT_OF_TOLERANCE`.
3. **Watch the breaker open.** In `PartnerCallbacksTest`, fake the partner with `Http::response('', 503)`, set `firefly.callbacks.circuit-breaker.failure-threshold` to `1`, make two deposits, and read the second execution's error from `ExecutionLog::recent()`.
