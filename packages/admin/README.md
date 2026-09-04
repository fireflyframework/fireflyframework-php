# firefly/admin

The LaraFly admin dashboard — a server-rendered browser view over the actuator, in the spirit of
[Spring Boot Admin](https://docs.spring-boot-admin.com/).

```bash
composer require firefly/admin
```

Then open `/firefly`.

## What it shows

| Page | Reads | Answers |
| --- | --- | --- |
| Overview | `health`, `info`, `beans`, `conditions`, `mappings` | Is it up, what booted, and was it compiled or scanned? |
| Beans | `beans` | Every bean the container registered, with stereotype and scope. |
| Conditions | `conditions` | Which auto-configurations applied, and which backed off because you supplied your own. |
| Mappings | `mappings` | The compiled route table the dispatcher serves from. |
| Scheduled | `scheduledtasks` | Methods registered by `#[Scheduled]`, with their cron or fixed rate. |
| Metrics | `metrics` | Counters, timers and gauges, with their current measurements. |
| Loggers | `loggers` | Log channels and their levels, with a control to change one. |
| Environment | `env` | Resolved `firefly.*` configuration, with secrets masked. |

A page whose endpoint is not registered — or is switched off — is hidden from the menu rather than
offered as a link that lands on an apology.

## How it reads them

In-process. The dashboard holds the `ActuatorRegistry` and invokes each `ActuatorEndpoint` bean directly
rather than fetching `/actuator/...` over HTTP.

That is deliberate. `firefly.management.endpoints.web.exposure.include` defaults to `health,info`, so
fetching beans or env over HTTP would 404 unless you first published them to every caller. The dashboard
needs none of that: it renders what the process already knows, and the JSON surface stays
secure-by-default.

A per-endpoint kill switch (`firefly.management.endpoint.{id}.enabled`) *is* honoured, because that key
means "this endpoint is off", not "this endpoint is unpublished".

## Access

Because the dashboard bypasses exposure, its own URL is the only boundary — so it must not be on by
default in production.

`firefly.admin.enabled` defaults to the value of `app.debug`. An application already running with debug on
is already serving stack traces and is a development environment by definition. An application with debug
off must opt in explicitly, and **should put the route behind its own auth middleware when it does**.
Setting the key always wins over the debug default, in both directions.

## Configuration

| Key | Default | Meaning |
| --- | --- | --- |
| `firefly.admin.enabled` | `app.debug` | Mount the dashboard at all. |
| `firefly.admin.base-path` | `/firefly` | Where it is mounted. Leading and trailing slashes are optional. |
| `firefly.admin.title` | `app.name` | The name shown in the sidebar and the page title. |

## No build step

The views are plain Blade with inline CSS and system fonts. There is no npm step at install time and no
CDN at request time — a composer package cannot assume npm has run, and a dashboard that needs the network
is useless in exactly the isolated environments where you most want to look at one.

Blade itself *is* required. On a JSON-only deployment with no view factory bound, the dashboard mounts
nothing and leaves the JSON actuator as the management surface, rather than mounting routes that would
fatal on first request.

## Caveats

* **Changing a log level affects this process only.** It calls the same endpoint
  `POST /actuator/loggers/{name}` does, which mutates the current process's Monolog handlers. Under PHP-FPM
  the next request is a different process. Change `logging.channels` for anything that must persist.
* **Metrics are only as durable as your registry.** The default `SimpleMeterRegistry` keeps meters in
  process memory, so under PHP-FPM the dashboard sees only its own request. Set
  `firefly.observability.metrics.store` to a cache store to accumulate across workers.
* **Health details are hidden unless you ask for them.** Set
  `firefly.management.endpoint.health.show-details` to `always` to see each indicator on the overview.

Apache-2.0 © Firefly Software Solutions Inc.
