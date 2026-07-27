# firefly/actuator

LaraFly's production-ready management surface — the Spring-Boot-Actuator analog: an `ActuatorEndpoint`
contract + `ActuatorRegistry`, a route-registration `BootPass` mounting framework endpoints on the
illuminate Router under `/actuator`, and a HAL index at `/actuator` listing every exposed endpoint. A
first-party `HealthIndicator` SPI (`Ping`/`DiskSpace`/opt-in `Db`) feeds `/actuator/health` (+ liveness/
readiness groups, 503 on DOWN), alongside `/actuator/info`, masked `/actuator/env`, `/actuator/beans`,
`/actuator/conditions`, `/actuator/mappings`, `/actuator/loggers`, and `/actuator/scheduledtasks`.
Dependency-light, always-on, and secured entirely by `firefly/security`'s `HttpSecurity` config with zero
code edge — and secure-by-default: `health`/`info` ship unexposed until configured, so an unconfigured
endpoint 404s rather than leaking data.

See [Actuator](../../docs/modules/actuator.md) for the full endpoint reference.

Apache-2.0 © Firefly Software Solutions Inc.
