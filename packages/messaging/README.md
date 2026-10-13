# firefly/messaging

LaraFly's lower-level raw-bytes messaging: a `MessageBrokerPort` (topic + bytes value + key + consumer group)
with an in-memory default adapter and a Laravel-queue async adapter, a broker-agnostic `#[MessageListener]`
attribute (carrying `retries`/`retryDelay`/`deadLetterTopic`) compiled into a `MessageListenerManifest` and wired
onto the broker at boot, and a bytes-aware retry / dead-letter helper. Durable dead letters
(`firefly.messaging.dead-letter.store=database`) and `firefly:messaging:dead-letters:replay` ship here.

Apache-2.0 © Firefly Software Solutions Inc.
