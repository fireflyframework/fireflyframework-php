# firefly/eda

LaraFly's event-driven-architecture transport: an `EventPublisher` broker-bus port with an in-memory
default adapter and a Laravel-queue async adapter, an `EventEnvelope` + JSON `Serializer` seam, a
`#[EventListener]` attribute compiled into an `EventListenerManifest` and wired onto the bus at boot,
and a linear-backoff retry / dead-letter helper. Durable dead letters (`firefly.eda.dead-letter.store=database`)
and `firefly:eda:dead-letters:replay` ship here; the same-transaction outbox is `firefly/eda-database` (and
`firefly/eda-postgres`), the brokers `firefly/eda-rabbitmq` and `firefly/eda-kafka`.

Apache-2.0 © Firefly Software Solutions Inc.
