# Publishing LaraFly

LaraFly publishes **one library, `firefly/firefly`**, from this repository's root `composer.json`.
Its tagged archive contains every component's code, configuration, migrations, views, compiled manifests,
the `firefly` installer binary and the application skeleton. No component mirror repositories or
cross-repository `ACCESS_TOKEN` are needed.

## Package identity and compatibility

The root package replaces the 28 other component names with `self.version`. Together with its own
`firefly/firefly` name, that covers the 29 former packages. `firefly/lumen` is a sample, not a replacement;
`firefly/skeleton` is a bundled project template, not a second published package.

Consumers first require the provider package:

```bash
composer require firefly/firefly
composer require firefly/eda-kafka
```

The second requirement is satisfied by the installed framework at a compatible version. Composer does
not automatically discover an unknown provider from a replacement name alone: an empty project must
require `firefly/firefly` explicitly. `self.version` prevents a framework from satisfying component
constraints from another release line. See [Composer's replace documentation](https://getcomposer.org/doc/04-schema.md#replace).

The component manifests under `packages/*` remain internal dependency and namespace descriptors.
`composer mono-validate` still checks their version consistency. Do not run monorepo-builder `merge`,
`bump-interdependency` or `release`: there are no component publications to coordinate. The root manifest
owns runtime requirements, autoloading, Laravel discovery and the installer binary. Root `autoload-dev`
loads component test support and Lumen only when developing this repository.

All adapter code is included. Configuration still chooses the active transport; PostgreSQL needs
`ext-pdo_pgsql`, Kafka needs `ext-rdkafka`, and the RabbitMQ client is included. Testbench and
`illuminate/testing` are development dependencies of an application using the testing kit, not production
dependencies of the library:

```bash
composer require --dev orchestra/testbench:^11.1 illuminate/testing:^13.0
```

## Validate a change

```bash
composer install
composer validate --strict
composer mono-validate
composer check
composer test:package
composer test:browser
```

`test:package` exports the current library, installs it without development dependencies in a separate
application, requires every component name, boots and compiles a real route, runs the bundled installer,
installs the Lumen consumer through a copied root path repository, and rejects an incompatible component
version. Its package repositories exclude `firefly/*` from Packagist so a mirror cannot mask a missing
replacement. The consumer directories are retained under the system temporary directory for inspection.
CI runs these package checks on PHP 8.3, 8.4 and 8.5 for PRs to `main` and pushes to `main`.

## First publication and later releases

1. Merge a reviewed PR only after all quality, browser, documentation and package checks pass.
2. Update the framework version, README version badge and changelog together, then run the gates again.
3. Tag that release commit with its new `vYY.MM.Patch` version. Do not move an existing tag. Tags through
   `v26.09.8` describe the old development aggregator and cannot serve as this new library.
4. Register `firefly/firefly` on Packagist using
   `https://github.com/fireflyframework/fireflyframework-php` and enable the repository webhook. This is a
   one-time publisher action; subsequent releases use tags from this same repository.
5. Push the new release tag. **Release (single package)** validates the tagged distribution with read-only
   repository permissions. A green workflow proves the archive checks passed; it does not prove Packagist
   has indexed the tag.
6. Confirm the exact new version is visible on Packagist and install it in a fresh project with default
   stable resolution, no custom repositories, and matching `firefly/firefly` and `firefly/eda-kafka`
   constraints. Publication is complete only when that install succeeds.

An unmerged branch or a local consumer check is not a published release.
