# firefly/cli

LaraFly's developer-experience console — the Spring Boot Maven/Gradle-plugin analog, built as Artisan
commands. `firefly:cache` runs every settled package's scanner → compiler pair over
`firefly.scan.paths` and writes the compiled manifests plus `#[Transactional]` proxy classes to
`bootstrap/cache/firefly/` for a zero-reflection boot; `firefly:clear` deletes that cache. `firefly:about`/
`:routes`/`:health`/`:metrics` render M12 actuator/observability endpoint data in-process at the terminal
(actuator-over-CLI, no HTTP round-trip). The `make:firefly-*` family scaffolds every framework stereotype
(`controller`, `service`, `component`, `handler`, `listener`, `entity`, `repository`,
`config-properties`), and `firefly:serve`/`firefly:db` thinly delegate to Laravel's own `serve`/database
commands.

```bash
php artisan firefly:cache
php artisan make:firefly-handler RegisterWidget
```

See [CLI](../../docs/modules/cli.md) for the full command reference.

Apache-2.0 © Firefly Software Solutions Inc.
