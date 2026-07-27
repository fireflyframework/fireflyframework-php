# firefly/firefly

LaraFly's runtime metapackage — the Composer analog of a Maven BOM. `composer require firefly/firefly`
pulls the whole runtime framework family in one line (kernel, container, context, config, autoconfigure,
web, validation, resilience, scheduling(+postgres), data, domain, eda, messaging, cqrs, security,
observability, actuator) instead of requiring each package individually. It excludes the dev-scoped
`firefly/testing` and `firefly/cli` packages and the `firefly/installer`, which stay `require-dev`/
top-level concerns rather than runtime dependencies.

```bash
composer require firefly/firefly
```

Apache-2.0 © Firefly Software Solutions Inc.
