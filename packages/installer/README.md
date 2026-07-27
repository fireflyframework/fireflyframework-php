# firefly/installer

The LaraFly global installer — the `laravel/installer` analog.

```bash
composer global require firefly/installer
firefly new my-app
```

`firefly new <app>` wraps `composer create-project firefly/skeleton`, then (unless `--no-git`) runs
`git init` + an initial commit, and prints the next steps. It depends only on `symfony/console` +
`symfony/process` — never the firefly runtime family — so a global install stays light.

Apache-2.0 © Firefly Software Solutions Inc.
