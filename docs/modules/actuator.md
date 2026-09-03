# Actuator

`firefly/actuator` is LaraFly's production-ready management surface — the Spring-Boot-Actuator analogue. It ships a
`HealthIndicator` SPI with liveness/readiness probe groups, an `ActuatorEndpoint` contract + registry, and a
route-registration BootPass that mounts framework endpoints on the illuminate Router under `/actuator`. Dependency-light,
always-on, and secured entirely by M11 config with zero code edge to `firefly/security`.

## Endpoints

- `/actuator` — HAL index of exposed endpoints
- `/actuator/health` (+ `/actuator/health/{group}`, liveness/readiness) — aggregated health, 503 on DOWN
- `/actuator/info` — merged `InfoContributor` fragments (`runtime`, `app`, `build`)
- `/actuator/env` — the `firefly.*` config tree, sensitive values masked
- `/actuator/configprops` — every `#[ConfigProperties]` DTO, with the values it actually resolved off the bound instance, masked
- `/actuator/caches` (+ `/actuator/caches/{name}`) — the configured `cache.stores` (name/driver/default only); read-only, no eviction
- `/actuator/beans`, `/actuator/conditions`, `/actuator/mappings`, `/actuator/loggers` (GET/POST), `/actuator/scheduledtasks`
- `/actuator/metrics`, `/actuator/prometheus` — supplied by `firefly/observability` when installed

## Health

`HealthIndicator { health(): Health }`; `Status` = UP/DOWN/OUT_OF_SERVICE/UNKNOWN (severity DOWN>OUT_OF_SERVICE>UP>UNKNOWN);
a most-severe `StatusAggregator` (empty→UP); a bean-scan `HealthContributorRegistrar`; built-in `Ping`/`DiskSpace`/`Db`
indicators. A throwing indicator degrades to DOWN — never a 500.

## Exposure & security (recommended)

Default `firefly.management.endpoints.web.exposure.include = "health,info"`; sensitive endpoints return **404** until
explicitly exposed. An endpoint body is always a JSON **object**: `/actuator/info` with no `InfoContributor`
registered answers `{}`, not `[]`, so a typed client deserialising into a map does not break on the default. Lock them down with `firefly.security.http.rules` (no second management port — doesn't fit PHP-FPM):

```php
'firefly' => [
    'security' => [
        'enabled' => true,
        'http' => [
            'enabled' => true,
            'rules' => [
                ['pattern' => 'actuator/health', 'access' => 'permitAll'],
                ['pattern' => 'actuator/info', 'access' => 'permitAll'],
                ['pattern' => 'actuator/*', 'access' => 'hasRole:ACTUATOR'],
            ],
        ],
    ],
],
```

`firefly/security`'s `HttpSecurityFilter` is a **global** middleware — `FilterChainRegistrar` pushes it onto Laravel's
HTTP-kernel middleware stack (`$kernel->pushMiddleware(...)`), so it runs for **every** request the kernel handles,
including the actuator's directly-`$router->match()`-registered `/actuator/**` routes. Securing actuator is therefore
**pure configuration**: `firefly/actuator`'s `composer.json` has no dependency on `firefly/security`, and `deptrac.yaml`
carries no `Actuator → Security` edge. An integration test
(`packages/actuator/tests/SecurityLockdownIntegrationTest.php`, booted via
`packages/actuator/tests/Support/SecuredActuatorCapstoneTestCase.php`) boots both stacks together and proves it
end-to-end: with the lockdown rules above and `env` exposed, an anonymous `GET /actuator/env` is denied **401**
(`AuthenticationException`, no matching rule's expression is satisfied) while `GET /actuator/health` stays **200**
(`permitAll`).

`/actuator/env` and `/actuator/configprops` additionally mask any key matching `password|secret|token|key|credential|passwd`
(case-insensitive, recursive — the shared `SensitiveValueMasker`) with `******`. The key is tested **before** the value's
type, so a sensitive key holding an array (a JWT keyring, a credentials pair) is replaced wholesale rather than recursed
into, independent of whether the URL lockdown above is configured — defense in depth for an
endpoint that is reachable at all only once explicitly exposed.

## Configuration (`firefly.management.*`, kebab-case)

- `firefly.management.enabled` (default `true`) — master gate
- `firefly.management.endpoints.web.exposure.include` / `.exclude` (CSV or `*`; `*` is a wildcard in **both** lists and exclude wins, so `.exclude = "*"` is the kill switch)
- `firefly.management.endpoints.web.base-path` (default `/actuator`)
- `firefly.management.endpoint.{id}.enabled` (per-endpoint)
- `firefly.management.endpoint.health.show-details` (default `never`; only the literal `always` shows component details — see Known-latent for `when-authorized`)
- `firefly.management.endpoint.health.group.{name}.include`
- `firefly.management.endpoint.health.db.enabled` (default `false`) — opt-in `Db` health indicator
- `firefly.management.info.app.*`, `firefly.management.info.build.path`
- `firefly.management.info.runtime.enabled` (default `true`, `#[ConditionalOnProperty(matchIfMissing: true)]`) — the `runtime` fragment of `/actuator/info` (PHP version/SAPI/OPcache, Laravel version, LaraFly version, current+peak memory). Setting it `false` removes the contributor bean entirely.

## Laravel comparison

Framework endpoints are `#[Component]` beans mounted by a BootPass on the illuminate `Router` (reusing `route:list`,
URL generation, the HTTP-kernel middleware pipeline) — not app controllers. Health/info reuse Laravel `DB`/`Log`/config.

## Known-latent

- `when-authorized` show-details degrades to `never` (no Security code edge; gate details via the lockdown).
- `/refresh`, `/threaddump`, `/shutdown` are deferred to later SP cycles. `/caches` is read-only: Spring's `DELETE` eviction is deliberately not implemented, because `firefly/actuator` carries no code edge to `firefly/security` and so cannot say who asked; a `POST` to it answers 404.
- No second management port (an Octane second-listener is an SP-7 option).
