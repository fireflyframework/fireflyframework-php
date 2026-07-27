# firefly/messaging

LaraFly's lower-level raw-bytes messaging: a `MessageBrokerPort` (topic + bytes value + key + consumer group)
with an in-memory default adapter and a Laravel-queue async adapter, a broker-agnostic `#[MessageListener]`
attribute (carrying `retries`/`retryDelay`/`deadLetterTopic`) compiled into a `MessageListenerManifest` and wired
onto the broker at boot, and a bytes-aware retry / dead-letter helper. Real brokers (Kafka/RabbitMQ) ship as their
own `firefly/messaging-<broker>` packages at SP-4.

Apache-2.0 © Firefly Software Solutions Inc.
