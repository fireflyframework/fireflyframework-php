# firefly/messaging

LaraFly's lower-level raw-bytes messaging: a `MessageBrokerPort` (topic + bytes value + key + consumer group)
with an in-memory default adapter and a Laravel-queue async adapter, a broker-agnostic `#[MessageListener]`
attribute (carrying `retries`/`retryDelay`/`deadLetterTopic`) compiled into a `MessageListenerManifest` and wired
onto the broker at boot, and a bytes-aware retry / dead-letter helper. Kafka and RabbitMQ listener containers —
retries with back-off, dead-letter topics and exchanges — ship in `firefly/eda-kafka` and `firefly/eda-rabbitmq`
(`firefly.messaging.provider=kafka|rabbitmq`), consumed by `php artisan firefly:messaging:consume`.

Apache-2.0 © Firefly Software Solutions Inc.
