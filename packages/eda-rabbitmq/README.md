# firefly/eda-rabbitmq

A RabbitMQ adapter for `firefly/eda`'s `EventPublisher`/`EventConsumer` ports over `php-amqplib`
(AMQP 0-9-1): a `RabbitMqEventPublisher` targeting an `"exchange/routingKey"` destination (or a bare
routing key against the default topic exchange) and a long-running `RabbitMqEventConsumer`. Its own
opt-in package, auto-configured behind `firefly.eda.provider=rabbitmq`, with a broker-native DLX
(dead-letter exchange) for exhausted retries and a `RabbitMqHealthIndicator` feeding
`/actuator/health`.

See [EDA Brokers](../../docs/modules/eda-brokers.md) for the full adapter reference.

Apache-2.0 © Firefly Software Solutions Inc.
