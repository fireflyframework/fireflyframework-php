# firefly/eda

LaraFly's event-driven-architecture transport: an `EventPublisher` broker-bus port with an in-memory
default adapter and a Laravel-queue async adapter, an `EventEnvelope` + JSON `Serializer` seam, a
`#[EventListener]` attribute compiled into an `EventListenerManifest` and wired onto the bus at boot,
and a linear-backoff retry / dead-letter helper. Real brokers (Kafka/RabbitMQ/Postgres) and the durable
transactional outbox ship as their own packages at SP-4.

© Firefly Software Solutions Inc. Licensed under Apache-2.0.
