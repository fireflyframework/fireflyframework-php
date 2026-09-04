# firefly/installer

The LaraFly global installer — the `laravel/installer` analog, with a Spring-Initializr-shaped project
picker.

```bash
composer global require firefly/installer
firefly new my-app
```

`firefly new <app>` wraps `composer create-project firefly/skeleton`, shapes the result into the requested
archetype, then (unless `--no-git`) runs `git init` + an initial commit and prints the next steps. It
depends only on `symfony/console` + `symfony/process` — never the firefly runtime family — so a global
install stays light.

## Archetypes

| flag     | shape                                                                     |
| -------- | ------------------------------------------------------------------------- |
| `--web`  | **default.** HTML + JSON: the `#[Controller]` welcome page and the sample `#[RestController]` |
| `--api`  | JSON only: the sample `#[RestController]`, no view layer, no welcome page  |
| `--full` | `--web` plus every non-adapter capability pre-wired                        |

```bash
firefly new my-api  --api
firefly new my-app  --with=security,eda,scheduling
firefly new my-shop --full --with=eda-postgres
```

`--with=` takes a comma-separated capability list (repeat the flag if you prefer). Run `firefly new --help`
for the current list — it is interpolated from the catalog, so it cannot drift from what the flag accepts.
A capability's only footprint is a line in the generated `composer.json`: firefly's conditional
auto-configuration means an installed capability is a wired capability, so there is no second copy of the
package's own defaults for you to keep in sync.

Adapters (`eda-kafka`, `eda-rabbitmq`, `eda-postgres`, `scheduling-postgres`) pull their port in with them
and are deliberately **excluded from `--full`**: which broker or engine an application talks to is not
something an archetype can guess, and guessing would install a broker client — or demand a PHP extension —
the machine may not have.

With no flags on an interactive terminal, `firefly new` asks for the shape and the capabilities. Under
`--no-interaction` it asks nothing and generates `--web` with no capabilities.

An archetype that adds or removes files (today, `--api`) also **recompiles the manifests**. The skeleton's
`post-create-project-cmd` ends in `php artisan firefly:cache`, so `create-project` hands back a project
whose compiled `routes.php` and `component.php` already name the controller `--api` is about to delete —
left alone, the generated app answered `GET /` with a 500 instead of a 404. The stale artifacts are dropped
(an app with no manifests boots by scanning, which is slower but always correct) and `firefly:cache` is
re-run to restore the compiled path; if that ever fails you keep a working, scanned app and
`firefly:serve` tells you so.

## `--force`

`--force` scaffolds into a directory that is not empty. It **empties that directory first**, after printing
the path and asking for confirmation (auto-confirmed under `--no-interaction`, which is what makes `--force`
usable in scripts). It refuses outright when the target is a filesystem root or your home directory, and it
unlinks symlinks rather than following them.

This used to be a broken promise: the old `--force` skipped the installer's own "directory is not empty"
error and then handed the still-non-empty directory to `composer create-project`, which refuses it too and
has no flag that says otherwise.

## Where the capability list comes from

`Firefly\Installer\CapabilityCatalog` — a declarative map owned by this package, not a scan. A global
install has no monorepo on disk to enumerate and no firefly runtime package to introspect; the installer
runs before the framework exists. The enumeration happens in CI instead: `CapabilityCatalogTest` reads the
real `packages/*` directory and fails the build when a firefly package is neither a capability nor listed,
with a reason, in `CapabilityCatalog::corePackages()`.

Apache-2.0 © Firefly Software Solutions Inc.
