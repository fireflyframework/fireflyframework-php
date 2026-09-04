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

## Separate management port

Spring Boot's `management.server.port` is supported, with one honest caveat spelled out below.

```php
// config/firefly.php
'management' => [
    'server' => [
        'port' => env('FIREFLY_MANAGEMENT_PORT'),      // unset = same port as the application (the default)
        'address' => env('FIREFLY_MANAGEMENT_ADDRESS'), // bind address for the management listener
        'base-path' => '',                              // optional prefix: '/manage' -> /manage/actuator/health
    ],
],
'server' => [
    'port' => env('FIREFLY_SERVER_PORT'),  // optional: declare the application's own port (see "Validation")
],
```

With `firefly.management.server.port` set, the actuator answers on that port and **404s everywhere else** — the
index and every endpoint, with the same RFC-9457 problem+json body an unexposed endpoint gets, so a scan of the
public port cannot tell the two apart.

### What PHP can and cannot do

A PHP-FPM worker, an `artisan serve` process and an Octane worker are each handed one already-accepted connection
by a listener they do not own. **There is no point at which framework code could bind a second socket**, so this
package does not pretend to. It provides the enforcement half and leaves the listener to the deployment:

| Half | Who provides it |
| --- | --- |
| A second socket listening on the management port | your deployment (below), or `firefly:management:serve` in dev |
| Refusing the actuator on any other port | `ManagementPortGuard`, in every request |

The guard compares `SERVER_PORT` — written by the SAPI from the socket that accepted the connection — to the
configured port. It deliberately does **not** use the `Host` header (the client writes that; a guard built on it is
walked past with `curl -H 'Host: localhost:9001'`). `X-Forwarded-Port` is honoured only when the request comes from
a trusted proxy **and** the application trusts that header (`TrustProxies`' `$headers` must include
`Request::HEADER_X_FORWARDED_PORT`, as Laravel's default does), for the deployment where one proxy terminates both
ports onto the same upstream.

`firefly.management.server.address` is a **bind** address, consumed by `firefly:management:serve` and copied into
your pool's `listen`. It is not a request-time check: a bind address is invisible to an HTTP request, and the
kernel has already enforced it by the time PHP runs.

### Deployment shapes

**Two PHP-FPM pools** — the management pool listens on its own socket; nginx routes `/actuator` to it and nothing
else:

```ini
; /etc/php-fpm.d/app.conf
[app]
listen = 127.0.0.1:9000

; /etc/php-fpm.d/management.conf — same code, same image, its own socket
[management]
listen = 127.0.0.1:9001
```

```nginx
server {                       # public
    listen 443 ssl;
    location / { fastcgi_pass 127.0.0.1:9000; include fastcgi_params; }
}
server {                       # private network only
    listen 10.0.0.4:9001;
    location / { fastcgi_pass 127.0.0.1:9001; include fastcgi_params; }
}
```

**Two containers** — the same image twice, the management one with `FIREFLY_MANAGEMENT_PORT` matching its exposed
port and no route from the public ingress.

**One proxy, one pool** — both server blocks forward to the same upstream, and the management block sets
`proxy_set_header X-Forwarded-Port 9001;`. Requires the proxy's address in the application's trusted proxies, and
`HEADER_X_FORWARDED_PORT` in the trusted header set — the proxy must also overwrite any client-supplied
`X-Forwarded-Port`, which the `proxy_set_header` above does.

### Development

```
php artisan firefly:management:serve      # a second `artisan serve` on the configured address/port
```

Run it alongside `firefly:serve`. It reports the actuator URL and delegates; `--host`/`--port` override the config.

**It does not keep application routes off the management port.** One `artisan serve` is one Laravel application and
every route it has answers on the port it was given. The guarantee is one-directional — the actuator is unreachable
on the application port — and restricting the other direction is the listener's job (a pool only the management
server block talks to, a container the public ingress cannot reach).

### Validation

A management port equal to the application port is a **boot failure**, not a silent no-op: the guard would permit
every request, leaving a config file that reads as isolated and is not. This diverges from Spring, where the two
being equal legitimately means "serve management on the main server".

The application port is taken from `firefly.server.port` if declared, else from an explicit port in `app.url`. When
neither exists the check stands aside — PHP is not told which socket its pool listens on, and a guessed port would
abort correctly-configured boots. Declare `firefly.server.port` if you want the mistake caught.

### The seam for other management surfaces

The boundary this package enforces covers the actuator's own routes and nothing else. Any other package that mounts
a management surface over HTTP — `firefly/admin`'s dashboard at `/firefly`, for instance — is a **separate route on
the same Router and is not guarded until it opts in**: as of this release the dashboard does not consult the guard,
so on a deployment with a management port it still answers on the application port.

Opting in is two lines. `Firefly\Actuator\Server\ManagementPortGuard` is a bound singleton (bound whether or not
`firefly.management.enabled` mounted any actuator route), `permits(Request $request): bool` is the predicate, and
the caller renders its own 404 — the actuator renders problem+json, an HTML dashboard should not.
`ManagementServerSettings::mountPath()` gives the actuator's effective path.

Apache-2.0 © Firefly Software Solutions Inc.
