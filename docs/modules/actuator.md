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
- `/actuator/metrics`, `/actuator/prometheus`, `/actuator/httpexchanges`, `/actuator/process` — supplied by
  `firefly/observability` when installed
- `/actuator/oauth2clients` — supplied by
  [`firefly/security-oauth2-server`](security-oauth2-server.md) when installed and enabled (both
  `firefly.security.enabled` and `firefly.security.oauth2.server.enabled`): every registered client with its
  grants, scopes, redirect URIs, settings and live authorization count, never a secret. The counts are
  **per-process** on the default `memory` authorizations driver — a map rebuilt in every PHP worker — which is
  why the payload states its own storage model in `authorizations.processLocal`; `authorizations.driver =
  eloquent` is what makes them describe the deployment

!!! tip "A browser view over all of this"
    `firefly/admin` renders these same endpoints as a server-side dashboard, reading them **in-process** rather
    than over HTTP — so it shows pages the exposure model below deliberately keeps unpublished. That inversion is
    the whole of its security model: see [Admin Dashboard](admin.md), and read
    [its access model](admin.md#access-the-whole-security-boundary) before enabling it outside `app.debug`.

## Health

`HealthIndicator { health(): Health }`; `Status` = UP/DOWN/OUT_OF_SERVICE/UNKNOWN (severity DOWN>OUT_OF_SERVICE>UP>UNKNOWN);
a most-severe `StatusAggregator` (empty→UP); a bean-scan `HealthContributorRegistrar`; built-in `Ping`/`DiskSpace`/`Db`
indicators. A throwing indicator degrades to DOWN — never a 500.

### Who may read the component details

`firefly.management.endpoint.health.show-details` decides whether the body carries each contributor's
`components` block, and it has **three** literals. `always` publishes the details to every caller, `never` to
none, and `when-authorized` asks a bean:

```php
interface HealthDetailsAuthorizer   // Firefly\Actuator\Health
{
    public function mayReadDetails(): bool;
}
```

`firefly/actuator` owns the QUESTION and ships the conservative answer — `DenyHealthDetailsAuthorizer`,
which refuses everybody, so `when-authorized` in an application without `firefly/security` behaves exactly as
`never` does. It cannot ship the real answer: there is no `Actuator → Security` edge in `deptrac.yaml` and
there must not be, because a diagnostics surface has to work in an application with no principal model at all.
[`firefly/security`](security.md#the-one-bean-this-package-gives-the-actuator) fills the port from the
other side whenever `firefly.security.enabled` is on — `PrincipalHealthDetailsAuthorizer` reads the
session-held principal from the same `SecurityContextHolder` every other rule in that package reads and
compares it against `firefly.management.endpoint.health.roles` through the configured `RoleHierarchy`. An
application that wants its own rule binds its own `HealthDetailsAuthorizer` bean and both of the shipped ones
back off (`#[ConditionalOnMissingBean]`). This is the `CqrsMetrics`/`EdaTracing` seam shape, not a new
mechanism.

Anything else in `show-details` — including a typo — reads as `never`, the fail-closed direction.

!!! warning "`when-authorized` with no `roles` is every authenticated principal"
    An empty `firefly.management.endpoint.health.roles` is Spring's "any authenticated principal", and
    component details name database drivers, disk paths, broker hosts and indicator error messages. On a
    surface a probe reaches, list the roles that should read them.

## Exposure & security (recommended)

Default `firefly.management.endpoints.web.exposure.include = "health,info"`; sensitive endpoints return **404** until
explicitly exposed. An endpoint body is always a JSON **object**: `/actuator/info` with no `InfoContributor`
registered answers `{}`, not `[]`, so a typed client deserialising into a map does not break on the default. Lock them
down with `firefly.security.http.rules` (no second management port — doesn't fit PHP-FPM). These are the exact rules the
framework's own end-to-end lockdown test seeds, written as the settings the test overrides; in an application the same
three entries are the nested `security.http.rules` array of `config/firefly.php`, which the shipped reference already
carries commented out:

<!-- source: packages/actuator/tests/Support/SecuredActuatorCapstoneTestCase.php -->
```php
return [
    ...parent::configOverrides(),
    'firefly.management.endpoints.web.exposure.include' => 'health,info,env',
    'firefly.security.enabled' => true,
    'firefly.security.http.enabled' => true,
    'firefly.security.http.rules' => [
        ['pattern' => 'actuator/health', 'access' => 'permitAll'],
        ['pattern' => 'actuator/info', 'access' => 'permitAll'],
        ['pattern' => 'actuator/*', 'access' => 'hasRole:ACTUATOR'],
    ],
];
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

`/actuator/env` and `/actuator/configprops` additionally mask any key matching
`password|secret|token|key|credential|passwd|authorization|headers` (case-insensitive, substring, recursive — the shared
`SensitiveValueMasker`) with `******`. The key is tested **before** the value's type, so a sensitive key holding an array
(a JWT keyring, a credentials pair) is replaced wholesale rather than recursed into, independent of whether the URL
lockdown above is configured — defense in depth for an endpoint that is reachable at all only once explicitly exposed.
`headers` is in the list because a bag of outbound headers is where a client's credential travels
(`firefly.observability.tracing.otlp.headers` documents `authorization=Bearer …` as its contents, and a vendor's
`x-honeycomb-team` leaf matches nothing on its own, so only the bag's key can decide); the accepted cost is that
`firefly.security.headers` — the response-header filter's `enabled`/`hsts`/`csp` block, nothing an operator cannot read
off any response — renders as `******` too. The singular `header` is deliberately not matched: the same rule names the
data browser's sensitive columns, and `page_header` is page furniture, not a credential.

## Configuration (`firefly.management.*`, kebab-case)

- `firefly.management.enabled` (default `true`) — master gate
- `firefly.management.endpoints.web.exposure.include` / `.exclude` (CSV or `*`; `*` is a wildcard in **both** lists and exclude wins, so `.exclude = "*"` is the kill switch)
- `firefly.management.endpoints.web.base-path` (default `/actuator`)
- `firefly.management.endpoint.{id}.enabled` (per-endpoint)
- `firefly.management.endpoint.health.show-details` (default `never`) — `never` | `when-authorized` | `always`; anything else reads as `never`. `when-authorized` asks the `HealthDetailsAuthorizer` bean, which refuses everybody until `firefly/security` fills it (see [Who may read the component details](#who-may-read-the-component-details))
- `firefly.management.endpoint.health.roles` (default `[]`) — who `when-authorized` admits, Spring's `management.endpoint.health.roles`. **Empty means any AUTHENTICATED principal**; a non-empty list means one holding at least one of these roles, a bare name read as `ROLE_<name>` (the spelling `hasRole:` uses) and the configured role hierarchy applied. A **CSV string is the same restriction as the list** — `'ADMIN,ACTUATOR'` grants what `['ADMIN', 'ACTUATOR']` grants, Spring's own spelling of the property and the one both neighbouring list keys above use. A list whose entries are ALL unusable — a blank string, a null left by a dangling key — **refuses every caller** rather than being read as the empty list, because a typo must not widen the surface it was written to narrow; a value that is neither a list nor a string counts as one unusable entry and refuses the same way, never failing the read (a probe must not get a 500 out of a config typo). The refusal is logged once per boot, naming this key. Read only by `firefly/security`'s authorizer: without that package installed and its master flag on, the key does nothing
- `firefly.management.endpoint.health.group.{name}.include`
- `firefly.management.endpoint.health.db.enabled` (default **`true`**) — the `db` health indicator, registered whenever `database.default` names a connection with a driver (Spring Boot's `DataSourceHealthIndicator` auto-configuration); a failing or missing database reports DOWN and `/actuator/health` answers 503; an application with no default database gets no `db` component at all. `false` removes the indicator. An indicator can decline registration itself by implementing `ConditionalHealthIndicator::available()`.
- `firefly.management.info.app.*`, `firefly.management.info.build.path`
- `firefly.management.info.runtime.enabled` (default `true`, `#[ConditionalOnProperty(matchIfMissing: true)]`) — the `runtime` fragment of `/actuator/info` (PHP version/SAPI/OPcache, Laravel version, LaraFly version, current+peak memory). Setting it `false` removes the contributor bean entirely.

## Laravel comparison

Framework endpoints are `#[Component]` beans mounted by a BootPass on the illuminate `Router` (reusing `route:list`,
URL generation, the HTTP-kernel middleware pipeline) — not app controllers. Health/info reuse Laravel `DB`/`Log`/config.

## Known-latent

- `/refresh`, `/threaddump`, `/shutdown` are deferred to later SP cycles. `/caches` is read-only: Spring's `DELETE` eviction is deliberately not implemented, because `firefly/actuator` carries no code edge to `firefly/security` and so cannot say who asked; a `POST` to it answers 404. The `HealthDetailsAuthorizer` port above is the shape that would change that, and is deliberately scoped to the one question it asks.
- No second management port (an Octane second-listener is an SP-7 option).
