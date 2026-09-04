# Admin Dashboard

`firefly/admin` is a server-rendered browser dashboard over the actuator — the Spring Boot Admin analogue, with one
structural difference: it is not a separate monitoring application you deploy and register instances with. It is
Blade views *inside* the application it reports on, which is why it can read the endpoint registry directly, and
why its access model matters as much as it does.

It arrives with the runtime family — `firefly/firefly` requires it, so a `composer create-project
firefly/skeleton` project already has it and `firefly new --with admin` only makes the dependency explicit in
your own `composer.json`. Add it directly if you took the packages à la carte:

```bash
composer require firefly/admin
```

Then open `/firefly`. **Installed is not enabled**: `firefly.admin.enabled` defaults to `app.debug`, so the
package being present costs a production deployment nothing. That default, not the absence of the package, is
what stands between the dashboard and the internet — which is why the warning below matters more than the
install line above.

!!! warning "The access model is the whole security model"
    `firefly.admin.enabled` defaults to `app.debug`, because the dashboard bypasses the actuator's
    exposure model and its own URL is therefore the only thing in front of `beans`, `env` and
    `conditions`. It ships **no authentication of its own**. Read
    [Access: the whole security boundary](#access-the-whole-security-boundary) before enabling it
    outside debug.

## The pages

Most pages are a view over one `ActuatorEndpoint`'s payload; four read the container instead. The menu groups
them the way an operator thinks rather than the way the packages are laid out — *what is it doing right now*,
*what did it wire at boot*, *what is its data*, *how is it configured* — because a flat list of seventeen links
is a worse menu than four short ones.

| Group | Page | Path | Endpoint | Answers |
|---|---|---|---|---|
| Runtime | Overview | `/firefly` | several | Is it healthy, what is it doing, and what did it wire? |
| Runtime | Health | `/firefly/health` | `health` | Every indicator this process registered, with its own status and details |
| Runtime | Metrics | `/firefly/metrics` | `metrics` | Counters, timers and gauges, with their current measurements |
| Runtime | HTTP traffic | `/firefly/http` | `httpexchanges` | The most recent requests this application served |
| Wiring | Beans | `/firefly/beans` | `beans` | Every bean the container registered, with the stereotype that declared it |
| Wiring | **Bean graph** | `/firefly/graph` | `beans` | How your beans depend on one another — see [Bean Graph](bean-graph.md) |
| Wiring | Conditions | `/firefly/conditions` | `conditions` | Which auto-configurations applied, and which backed off because you supplied your own |
| Wiring | Routes | `/firefly/mappings` | `mappings` | The compiled route table the dispatcher serves from |
| Wiring | Scheduled | `/firefly/scheduled` | `scheduledtasks` | Methods registered by `#[Scheduled]`, with the cron or interval that drives them |
| Configuration | Environment | `/firefly/env` | `env` | Resolved `firefly.*` configuration, flattened to dotted keys, with secrets masked |
| Configuration | Config properties | `/firefly/configprops` | `configprops` | Every `#[ConfigProperties]` DTO the application bound, with the values it resolved |
| Configuration | Caches | `/firefly/caches` | `caches` | The cache stores this application has configured |
| Configuration | Loggers | `/firefly/loggers` | `loggers` | Log channels and their levels, with a control to change one |
| Configuration | **Feature switches** | `/firefly/settings` | — | Every framework switch, where its value came from, and — outside production — a control. **Off by default**; see [below](#the-feature-switch-console) |
| Data | **Datasource** | `/firefly/datasource` | — | Connections, connection reuse, and the compiled `#[Transactional]` contract |
| Data | **Browse data** | `/firefly/data` | — | The records behind your repositories — see [Data Browser](data-browser.md). **Off by default** |
| Data | **Entity map** | `/firefly/data-map` | — | The entities and the foreign keys between them, drawn |

The four pages with no endpoint read the container rather than the actuator, and each decides its own visibility:
an entry that led to "there is nothing here" is worse than no entry.

A page whose endpoint is **not registered in this process** — or is switched off — is *hidden from the menu* rather
than offered as a link that lands on an apology, and requesting it directly answers 404 with a page saying which
endpoint it needed. That matters because the actuator's endpoints are conditional: `metrics` disappears when
`firefly.observability.metrics.enabled` is false, and `configprops`, `caches` and `httpexchanges` exist only if the
package contributing them is installed. The menu has to be built from what this process actually registered, so it
is.

The Overview is the page an operator leaves open, so it answers the three questions that matter without a click: the
aggregate health status with every indicator beside it, the `/actuator/info` runtime fragment flattened to one row
per fact (with byte-ish keys formatted as sizes rather than printed as raw JSON), bean/route/condition/task counts,
the current metrics, the last eight HTTP exchanges, and whether this process booted **compiled** or **scanned** —
read from `AppScan::cachedFile(...)`, not from configuration.

Values are formatted for reading, not for scraping: `2.0 MB` rather than `2097152`, `31.2 ms` rather than `0.0312`.
That formatting lives in the dashboard, never in the endpoint, because the JSON surface has to keep returning
machine-readable numbers — Prometheus scrapes it.

## It reads endpoints in-process, not over HTTP

`AdminEndpointReader` holds the `ActuatorRegistry` and invokes each `ActuatorEndpoint` bean directly:

```php
public function read(string $id, array $subPath = [], array $query = []): ?array
{
    $endpoint = $this->registry->get($id);
    if ($endpoint === null || ! $this->has($id)) {
        return null;
    }

    try {
        $response = $endpoint->handle(new EndpointRequest('GET', $subPath, $query));
    } catch (Throwable) {
        return null;
    }

    return $response === null || is_string($response->body) ? null : $response->body;
}
```

What is **not** in that method is any mention of `ExposureModel`, and that is the single most important thing about
this package. `firefly.management.endpoints.web.exposure.include` defaults to `health,info`, so fetching
`/actuator/beans` or `/actuator/env` over HTTP 404s — [as it should](actuator.md).
The dashboard needs none of that. It renders what the process already knows, in-process, so **it shows pages the
HTTP surface deliberately does not expose**, and the JSON surface stays secure-by-default. Exposing `beans`,
`conditions` and `env` to every anonymous caller just so a browser could read them would be exactly the wrong trade.

The per-endpoint kill switch **is** honoured, and the asymmetry is the design:

| Key | Means | Dashboard |
|---|---|---|
| `firefly.management.endpoint.{id}.enabled` | "this endpoint is off" — a statement about the endpoint | honoured; the page disappears from the menu |
| `firefly.management.endpoints.web.exposure.include` | "this endpoint is unpublished" — a statement about the HTTP surface | **bypassed**; the dashboard is not the HTTP surface |

A throwing endpoint is caught and reported as `null` rather than allowed to take the page down with it — the same
fail-safe discipline `HealthEndpoint` applies to indicators, for the same reason: one broken contributor should
degrade its own panel, not the dashboard.

### Health details are read from the contributor registry

`firefly.management.endpoint.health.show-details` defaults to `never`, and that default is right: it stops an
anonymous HTTP caller learning your database host from a failed connection. Applying that *HTTP disclosure policy*
to the dashboard, though, produced a Health panel whose entire content was an apology telling the operator to go
and change a config key.

The dashboard reads `HealthContributorRegistry` directly instead, calling each indicator in isolation so one that
throws is reported `DOWN` with its exception class and message and nothing else is affected — exactly what
`HealthEndpoint`'s own fail-safe read does. The JSON `/actuator/health` response is unchanged and still withholds
components until `show-details` is `always`.

## Access: the whole security boundary

Because the dashboard bypasses exposure, **its own URL is the only thing standing in front of `beans`, `env` and
`conditions`.** That is why it must not be on by default in production, and why the enable flag is written the way
it is:

```php
enabled: $config->bool('firefly.admin.enabled', $config->bool('app.debug', false)),
```

`firefly.admin.enabled` **defaults to the value of `app.debug`**. An application already running with debug on is
already serving stack traces to whoever asks and is a development environment by definition, so a dashboard there
discloses nothing that was not already disclosed. An application with debug off has made the opposite statement
about itself and must opt in explicitly. **Setting the key always wins over the debug default, in both directions**
— you can turn the dashboard off in a debug environment, and on in a production one.

!!! warning "Turning it on outside debug is only half the job"
    `firefly.admin.enabled = true` with `app.debug = false` mounts a dashboard that renders your bean graph, your
    resolved configuration and your route table at a known URL, to anyone who can reach it. **The dashboard ships
    no authentication of its own** — it has no code dependency on `firefly/security` at all, exactly as
    `firefly/actuator` does not. An application that enables it outside debug **must put the route behind its own
    auth middleware.**

`firefly/security`'s `HttpSecurityFilter` is a global middleware pushed onto Laravel's HTTP-kernel stack, so it runs
for the dashboard's natively-registered routes exactly as it runs for your controllers. Locking it down is pure
configuration:

```php
'firefly' => [
    'admin' => [
        'enabled' => true,          // explicit: this deployment wants the dashboard with app.debug off
        'base-path' => '/firefly',
    ],
    'security' => [
        'enabled' => true,
        'http' => [
            'enabled' => true,
            'rules' => [
                ['pattern' => 'firefly', 'access' => 'hasRole:ADMIN'],
                ['pattern' => 'firefly/*', 'access' => 'hasRole:ADMIN'],
            ],
        ],
    ],
],
```

Both patterns are needed: `firefly` alone does not match `firefly/env`. Any other middleware works equally well —
a VPN-only route group, basic auth, an SSO gateway — the requirement is that *something* stands in front of the
path, not that it be `firefly/security`.

When the dashboard is disabled, `AdminRouteRegistrar` registers **nothing at all**: there is no route to guess at
and no handler to reach, and `php artisan route:list` does not list one.

### The write surfaces are CSRF-protected, and were not

The dashboard's routes were mounted on the router with **no middleware at all**, which in Laravel means no
session and no `ValidateCsrfToken`. Every `@csrf` in these views was therefore decorative: a `curl -X POST`
with no token against `/firefly/loggers` was accepted and changed the log level, and the same held for every
write the data browser and the settings console added.

A form that renders a CSRF field while the route ignores it is **worse than one that renders none**, because
it looks protected. The routes now carry `EncryptCookies`, `AddQueuedCookiesToResponse`, `StartSession`,
`ShareErrorsFromSession` and `ValidateCsrfToken`.

!!! note "The classes, not the `web` group name"
    Naming the group and guarding on `hasMiddlewareGroup('web')` looked right and attached **nothing**: this
    registrar runs inside the framework's boot pipeline, *before* the application's `RouteServiceProvider`
    defines that group, so the guard was false at registration time and silently produced an empty list — a
    fix that appeared applied and was not. Referring to the classes needs no group and no ordering
    assumption, and each is skipped if the installation does not have it.

!!! warning "Your session driver has to persist"
    An `array` session driver is discarded at the end of the request, so the token a form renders can never
    match the one the next request checks and **every** dashboard POST answers `419`. The skeleton now ships
    `SESSION_DRIVER=file` for exactly this reason; `file` needs no service, only the storage directory the
    framework already writes to.

    Laravel's CSRF middleware returns early when `runningUnitTests()` is true, so no feature test can prove
    this either way — which is how the hole survived being written. `tests/AdminCsrfTest.php` therefore
    asserts the middleware is *attached*, and the behaviour was verified over real HTTP: tokenless → `419`,
    token with its session → `302` and the write lands.

## How it is mounted

`AdminRouteRegistrar` is a `BootPass` at `BootPhase::WiringPasses`, order **60** — one step after
`ActuatorRouteRegistrar`'s 50, because it reads the registry that pass populates. It mounts two routes:

```
GET       {base}                     name: firefly.admin.index
GET|POST  {base}/{page}              name: firefly.admin.page   where page: [A-Za-z0-9\-_/]*
```

They are registered natively on the illuminate `Router`, not declared with `#[GetMapping]`, for the same reason the
actuator's and [OpenAPI's](openapi.md#the-routes-are-not-attribute-routes) are: `firefly.admin.base-path` has to be
settable per application, and an attribute route bakes its literal path into a compiled `RouteDescriptor`. Leading
and trailing slashes on the configured base path are optional, and an empty one falls back to `firefly`.

It also **backs off silently in one more case that is easy to miss.** Blade is required to render the dashboard and
is *not* a dependency of the package, so a JSON-only deployment with no `view` binding gets no routes rather than
routes that would fatal on first request; the JSON actuator remains the management surface there.

## No build step

The views are plain Blade with inline CSS and system fonts. There is no npm step at install time and no CDN at
request time — a Composer package cannot assume npm has run, and a dashboard that needs the network is useless in
exactly the isolated environments where you most want to look at one. (The one other browser surface LaraFly ships,
[`firefly/openapi`](openapi.md)'s console, reaches the same conclusion by a different route: it serves the official
Swagger UI from the application's own origin out of a composer package.)

## Three things it can only tell you about *this* process

Under PHP-FPM every request is a different process, and three pages inherit that.

- **Changing a log level affects this process only.** The control calls the same endpoint
  `POST /actuator/loggers/{name}` does, which mutates the current process's Monolog handlers. The next request is a
  different process and reverts to the configured level. Change `logging.channels` for anything that must persist —
  the page says so, in place, rather than letting anyone believe they have changed production logging.
- **Metrics are only as durable as your registry.** The default `SimpleMeterRegistry` keeps meters in process
  memory, so the dashboard sees only its own request. Set
  [`firefly.observability.metrics.store`](observability.md#configuration-fireflyobservability-kebab-case) to a cache
  store to accumulate across workers.
- **HTTP traffic has the same shape, more sharply.** The in-memory exchange ring under PHP-FPM is not merely stale
  but always empty, because the request rendering the page has not been recorded yet — the filter records on the way
  out. `firefly.observability.httpexchanges.store` is what makes that panel non-empty. While you are there, add the
  dashboard's own base path to `firefly.observability.httpexchanges.exclude`: a polling dashboard will otherwise
  evict every genuine request from a 100-row ring and show you nothing but itself. The framework does not add it for
  you, because reaching into another package's configuration key to guess at its mount point is the kind of hidden
  coupling that breaks the day somebody changes it.

## Configuration (`firefly.admin.*`)

| Key | Default | Meaning |
|---|---|---|
| `firefly.admin.enabled` | **`app.debug`** | Mount the dashboard at all. An explicit value wins in both directions. |
| `firefly.admin.base-path` | `'/firefly'` | Where it is mounted. Leading and trailing slashes optional; empty falls back to `firefly`. |
| `firefly.admin.title` | `app.name` (else `'LaraFly'`) | The name shown in the sidebar and the page title. |
| `firefly.admin.refresh-seconds` | `10` | How often a live page reloads itself. **Floored at 2**: a shorter interval reloads faster than the page renders, so the countdown would never finish and the dashboard would hammer the application it is meant to be observing. |
| `firefly.admin.theme` | `'auto'` | `auto` \| `light` \| `dark`. Anything unrecognised falls back to `auto` (follow the operating system) rather than rendering unstyled. |
| `firefly.admin.graph.max-nodes` | `220` | The ceiling past which the [bean graph](bean-graph.md) lists relations instead of drawing them. Clamped to a minimum of `0`, which suppresses the diagram entirely. |
| `firefly.admin.pages.exclude` | `''` | CSV of page slugs to refuse. This is a **refusal, not a menu preference**: an excluded page is hidden *and* its URL 404s — hiding `env` from the menu achieves nothing if the URL still answers. Use `overview` for the index page. |
| `firefly.admin.datasource.probe` | `true` | Whether the datasource page may **open** a configured connection to report that it answers. |
| `firefly.admin.datasource.wizard` | **`false`** | The connection wizard. Off by default and refused in production — see [below](#the-connection-wizard). |
| `firefly.admin.settings.enabled` | **`false`** | The feature-switch console. |
| `firefly.admin.settings.writable` | **`false`** | Whether that console has controls. Ineffective in production. |

The `firefly.admin.data.*` keys are documented separately, in [Data Browser](data-browser.md#configuration-fireflyadmindata),
because the browser is gated independently of everything above: `firefly.admin.enabled` does **not** switch it on,
and neither does `app.debug`.

## The datasource page

Four questions an operator asks at 3am that this dashboard could not answer:

- **Which database am I talking to?** Driver, host, port and database per connection, with `password` masked by the
  same masker the actuator's `env` endpoint uses — the page is behind the dashboard's gate, and a connection array
  dumped verbatim would put the database password on that URL.
- **Is it up?** *One* connection is probed per page load — the default, or the one named by `?probe=` — because
  opening a socket can hang against a firewalled host, and a page that opened every configured connection would
  take the slowest one's timeout to render, on the page you opened *because* something is wrong. What comes back
  is the server version, or the driver's own message.
- **What does pooling mean here?** PHP has no connection pool, and a "pool size" gauge would be an invented number.
  What exists is PDO's `ATTR_PERSISTENT`, reported as what it is — with the note that under php-fpm the effective
  pool size is your worker count, decided by the process manager, and that real pooling in front of Postgres is
  pgbouncer's job.
- **What did `#[Transactional]` compile to?** One row per proxied method with its propagation, isolation, timeout
  and connection. It existed only as a compiled artifact under `bootstrap/cache`.

### The connection wizard

`firefly.admin.datasource.wizard` adds a form that opens a connection you have **not** configured yet and reports
the server version or the driver's own error, plus the `config/database.php` block to paste. It collapses the
edit-`.env`, clear-cache, reload, read-a-useless-error loop into one round trip.

It is **off by default and refused outright when `app.env` is production**, and no configuration key lifts that. A
form that opens a socket to a host somebody typed is a request-forgery primitive by construction, and its failure
messages distinguish "refused" from "timed out" well enough to map a private network. It is POST-only for the same
reason — a link, an image tag or a prefetch must never be able to reach it — and it **writes nothing**: the result
is a snippet, with the password always an `env()` call and never the value that was typed.

!!! note "Why the errors are useful at all"
    The connection is opened with `getPdo()` *before* the test query. Going through `selectOne()` puts Laravel's
    reconnect wrapper in the way, which rethrows `Lost connection and no reconnector available` for a wrong
    password, a closed port and a typo in the host alike. Forcing the socket and unwrapping to the innermost
    exception is what turns the button into something worth pressing.

## The entity map

`/firefly/data-map` draws every browsable entity as a box with its columns, and every foreign key as a labelled
edge, from the same discovery the [data browser](data-browser.md#relations) walks. Boxes are links into their own
records.

A `hasMany` and the `belongsTo` facing it are **one** key seen from two ends, so each is drawn once — pointing from
the table that *holds* the key to the table it references, which is also what the arrow means. Relations the
browser cannot express as a single column comparison (a pivot, a polymorphic type column) are listed on each record
page but are not drawn, because a line with no join to name would be decoration. Entities with no relations at all
*are* drawn: a standalone table is a fact about the model, and a diagram that quietly dropped it would let a reader
conclude the application has fewer tables than it does.

It is behind the browser's own switch, not the dashboard's: a schema diagram names every table and column an
application has, which is the shape of its data even though it is not the data.

## The feature-switch console

`/firefly/settings` is the one page that **changes** the application rather than describing it, and it is gated
accordingly.

| Gate | Default | What it decides |
|---|---|---|
| `firefly.admin.settings.enabled` | `false` | Whether the page exists at all |
| `firefly.admin.settings.writable` | `false` | Whether it has controls as well as readings |
| `app.env` is `production` | — | **Not a configuration key.** Writes are refused, whatever the two above say |

The third gate is deliberately unconfigurable. That is the difference between "we made it safe" and "we made it
configurable to be safe", and only the first survives someone copying a `.env`.

**It is a feature switch, not a remote configuration endpoint.** The list of switches is fixed and framework-owned,
so a crafted POST naming `app.key` or a database host finds nothing to write — the method cannot express it. Each
row shows where its value came from: `config` (yours), `default` (the framework's), or `console` (this page).

A change is written to **one** JSON file under `bootstrap/cache`, filtered on the way in as well as out — a
hand-edited entry cannot introduce a key the console would have refused — and merged over configuration during the
provider's `register()`. Deleting that file restores your configured values exactly. Nothing is ever written to
`.env`: a config cache would disagree with it until someone cleared it, the file is routinely read-only in a
container image, and a web form that edits the file holding your database password is not a feature.

!!! note "Why `register()` and not a boot pass"
    Every settings object in this framework is built once from configuration and held for the process. Applying the
    overrides from the dashboard's own boot pass wrote the file and showed the new state on the page while
    `/openapi.json` kept answering 200 — a merge after the first read changes nothing. `register()` runs before any
    boot pass and before any bean resolves, which is the only point at which the merge is true.

## Laravel comparison

| Concern | Plain Laravel | LaraFly (`firefly/admin`) |
|---|---|---|
| A management UI | none first-party; Telescope is a *request* debugger, Horizon a *queue* dashboard — neither reports on wiring or configuration | one dashboard over the actuator's own endpoints |
| Browsing your data | none; Nova and Filament are paid or app-scale admin *frameworks* you build screens in | a Django-style browser over the repositories you already declared, off by default |
| Feature switches | a config file and a deploy | a gated console, with the production gate not configurable |
| Where it runs | Telescope/Horizon each add tables, a service provider and a middleware group | Blade views over beans that already exist; no storage of its own, nothing recorded |
| Data source | a recorder writing to the database | the live `ActuatorRegistry`, read in-process at render time |
| Enabling it safely | `TelescopeServiceProvider::gate()` — a closure you write | `firefly.admin.enabled` defaulting to `app.debug`, plus your own middleware when you override it |

## Known-latent

- **No instance registry.** Spring Boot Admin is a separate server that many applications register *with*, giving
  one console across a fleet. This is a per-instance dashboard, which is what makes the in-process read possible;
  a fleet view would need a different design and is not planned.
- **No write operations besides the log level, the data browser and the feature switches.** `/caches` is read-only
  for the same reason it is read-only on the JSON surface — `firefly/actuator` carries no code edge to
  `firefly/security` and so cannot say who asked.
- **`when-authorized` health details** degrade to `never` on the JSON surface (see
  [Actuator](actuator.md#known-latent)); the dashboard sidesteps it entirely by reading the contributor registry.

---

See also: [Actuator](actuator.md) for the endpoints themselves, [Observability](observability.md) for the metrics
and HTTP-exchange stores the dashboard renders, [Bean Graph](bean-graph.md) for the one page that is more than
a table, and [Data Browser](data-browser.md) for the Django-style view over your own repositories — which is
**off by default and does not inherit `firefly.admin.enabled`**.
