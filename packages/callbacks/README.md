# firefly/callbacks

LaraFly's outbound callbacks. Subscriptions — a URL, the event types it wants, a secret — live in memory or in a
portable table on any database connection. Every `firefly/eda` event whose type matches `firefly.callbacks.event-types`
is delivered to each matching subscription as a JSON POST signed with `X-Firefly-Timestamp` and
`X-Firefly-Signature: sha256=…` (HMAC-SHA256 over "<timestamp>.<body>"), retried with exponential back-off on
408, 429, 5xx and transport errors, through a circuit breaker per endpoint built on `firefly/resilience`, and
recorded in an execution log (memory or database). `php artisan firefly:callbacks:list` shows subscriptions and
deliveries; `php artisan firefly:callbacks:replay` sends logged deliveries again. Off until
`firefly.callbacks.enabled` is true.

See [Callbacks](../../docs/modules/callbacks.md) for the full reference.

Apache-2.0 © Firefly Software Solutions Inc.
