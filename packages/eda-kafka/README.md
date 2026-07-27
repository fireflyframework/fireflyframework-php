# firefly/eda-kafka

A Kafka adapter for `firefly/eda`'s `EventPublisher`/`EventConsumer` ports over the OPTIONAL
`ext-rdkafka` extension: `composer.json` only `suggest`s the extension and every `\RdKafka\*` call sits
behind an `extension_loaded('rdkafka')` guard, so the package installs and autoloads inertly on a machine
without it — a hard `RuntimeException` is thrown only at boot time if `firefly.eda.provider=kafka` is
selected without the extension present. Its own opt-in package (the `firefly/scheduling-postgres`
precedent), gated behind `#[ConditionalOnProperty('firefly.eda.provider', havingValue: 'kafka')]`, with a
dead-letter topic (`<topic>.DLT`) for exhausted retries and a `KafkaHealthIndicator` feeding
`/actuator/health`.

See [EDA Brokers](../../docs/modules/eda-brokers.md) for the full adapter reference.

Apache-2.0 © Firefly Software Solutions Inc.
