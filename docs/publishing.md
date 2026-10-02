# Publishing LaraFly

LaraFly publishes **one library, `fireflyframework/larafly`**, from this repository's root `composer.json`.
Its tagged archive contains every component's code, configuration, migrations, views, compiled manifests,
the `firefly` installer binary and the application skeleton. No component mirror repositories or
cross-repository `ACCESS_TOKEN` are needed.

## Package identity and compatibility

The root package replaces all 30 former component names, including `firefly/firefly`, with
`self.version`. Packagist's `firefly` vendor namespace belongs to another publisher, so the public
package uses the Firefly Framework organization's namespace. `firefly/lumen` is a sample, not a replacement;
`firefly/skeleton` is a bundled project template, not a second published package.

Consumers first require the provider package:

```bash
composer require fireflyframework/larafly
composer require firefly/eda-kafka
```

The second requirement is satisfied by the installed framework at a compatible version. Composer does
not automatically discover an unknown provider from a replacement name alone: an empty project must
require `fireflyframework/larafly` explicitly. `self.version` prevents a framework from satisfying component
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
4. Register `fireflyframework/larafly` on Packagist using
   `https://github.com/fireflyframework/fireflyframework-php` and enable the repository webhook. This is a
   one-time publisher action; subsequent releases use tags from this same repository.
5. Push the new release tag. **Release (single package)** validates the tagged distribution on PHP
   8.3, 8.4 and 8.5, waits for Packagist's Composer metadata (`repo.packagist.org/p2/…`) to list that exact
   commit for the tag, then installs it in a fresh stable
   consumer without custom repositories and exercises the bundled installer. Only after those checks
   pass does its final job build both books and create the GitHub release from the changelog with the
   English and Spanish PDF/EPUB files, checksums and source commit record. That job uses the repository's
   built-in `GITHUB_TOKEN` with `contents: write`; validation jobs remain read-only.
6. If indexing times out, repair the Packagist webhook or trigger an update on the package page, then
   rerun the failed job. Do not move the tag or create a GitHub release to bypass the public install gate.
   Releases up to 26.09.11 waited on the web API (`packagist.org/packages/…json`), which the CDN caches for
   twelve hours; for those tags a rerun succeeds once that cache has expired.

To repeat the public install check locally:

```bash
RELEASE_TAG=v26.09.11 RELEASE_SHA="$(git rev-parse 'v26.09.11^{commit}')" php scripts/check-package-install.php --published
```

An unmerged branch or a local consumer check is not a published release.

## Publish the documentation and books

CI builds the MkDocs site and both book editions using the shared `.github/actions/build-docs` action.
It runs the book pipeline tests, validates the PHP listings in both languages, renders the PDF/EPUB files,
rejects PDF text outside the page boundaries, and builds MkDocs with `--strict`.
The four books, `SHA256SUMS` and `build-info.json` are included under
the site's `downloads/` directory and uploaded as the `books` artifact for review on PRs.

For pushes to `main`, the **Publish documentation** job deploys that exact site artifact only after all
quality, browser, documentation and safety checks pass. PRs build and validate; they cannot deploy.
The live [book page](book.md) links to the latest successful main build. Release downloads are built
separately from the tagged source. A release rerun refreshes its generated assets from that same tag.

Repository setup requires **Settings → Pages → Build and deployment → Source: GitHub Actions**, and the
`github-pages` environment must permit deployments from `main`. The deployment job alone receives
`pages: write` and `id-token: write`. The former `gh-pages` branch is no longer the publication source.
