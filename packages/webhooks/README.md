# firefly/webhooks

LaraFly's inbound webhooks. Every provider configured under `firefly.webhooks.providers` gets an endpoint,
`POST /webhooks/{provider}`, whose request is size-checked, signature-verified (generic HMAC, Stripe, GitHub,
Twilio, or deliberately none), held to a replay window where the provider signs a timestamp, deduplicated by its
delivery id on an idempotency store (memory, the Laravel cache, or a portable table on any database connection),
and published to the `firefly/eda` event bus as `webhook.{provider}.{type}` on `webhooks.{provider}`. A refusal is
a problem+json document with a stable code. Off until `firefly.webhooks.enabled` is true.

Apache-2.0 © Firefly Software Solutions Inc.
