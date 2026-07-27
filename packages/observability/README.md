# firefly/observability

LaraFly's metrics core — the Micrometer/Prometheus-client analog. A first-party, pure-PHP `MeterRegistry`
(no `ext-prometheus`, no OpenTelemetry library) backs `Counter`/`Gauge`/`Timer` meters, exposed as
locale-independent Prometheus 0.0.4 text (`/actuator/prometheus`) and Micrometer-JSON
(`/actuator/metrics`) endpoints mounted on `firefly/actuator`. An HTTP `MetricsFilter` auto-instruments
every request with bounded-cardinality tags; `MeterRegistryCqrsMetrics` backs the CQRS metrics seam via
`#[Order(500)]`; a resilience circuit-breaker gauge and process metrics ship alongside a `Tracer` port
(`NoOpTracer` today; an OpenTelemetry adapter lands later). Property-gated, not
`#[ConditionalOnBean]`, and reflection-free.

See [Observability](../../docs/modules/observability.md) for the full metrics reference.

Apache-2.0 © Firefly Software Solutions Inc.
