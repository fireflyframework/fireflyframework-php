# Versioning

## Version format

LaraFly uses **CalVer** (`YY.MM.Patch`) across every package in the monorepo — the same scheme the rest of
the Firefly Framework family (Java, Rust, PyFly) uses. `26.07.16`, for example, is the 16th patch released
in July 2026. A new patch bumps the trailing number; the first release of a new month resets it to `1`
(`26.07.16` → `26.08.1`); January of a new year advances the year (`26.12.4` → `27.01.1`).

## No `version` field

No `composer.json` in this monorepo — not the aggregator, not any `packages/*/composer.json` — carries a
`version` key. Packagist derives a package's version from the **git tag** at publish time (and from the
`extra.branch-alias` entry for dev-branch resolution — see [Constraints](#constraints) below); hand-writing a
`version` field would just be a second, driftable source of truth.

The single place the current version *is* asserted in code is:

<!-- source: packages/kernel/src/Version.php -->

```php
final class Version
{
    public const string VERSION = '26.09.2';
}
```

`Firefly\Kernel\Version::VERSION` has exactly two readers in the shipped packages, and `grep -rn
'Version::VERSION' packages` is the whole list: `AboutCommand`, which prints `LaraFly <version>` as the first
line of `php artisan firefly:about`, and `RuntimeInfoContributor`, which puts it at
`runtime.firefly.version` in `/actuator/info`.

Consistency across the three human-visible surfaces that *should* always agree with it — the
`Version::VERSION` constant, the CHANGELOG's latest `## [x.y.z]` heading, and the README version badge — is
enforced by `tests/VersionConsistencyTest.php`, which fails the build the moment any of the three drifts from
the others. A release always updates all three together. Work merged between releases therefore accumulates
under a `## [Unreleased]` heading in the CHANGELOG — the test reads the first *versioned* heading, so an
unreleased section is invisible to it and the constant stays the single source of truth until the release is
actually cut.

The listing above is itself held to the constant: it carries a `<!-- source: -->` marker, so
`tests/DocsCodeIsRealTest.php` compares it line for line against `packages/kernel/src/Version.php` and this
page cannot quote a version the framework does not ship. See [Contributing](contributing.md#documentation).

## Reading the version at runtime

<!-- illustrative: the two lines an application writes in its own code to print the framework version -->

```php
use Firefly\Kernel\Version;

echo Version::VERSION;
```

This is the only version string LaraFly itself exposes; there is no runtime version-detection mechanism
beyond this constant (e.g. no reading it back out of an installed `composer.lock` at runtime).

## Constraints

Application `composer.json` files depend on Firefly packages with a constraint against a release line, e.g.:

```json
{
    "require": {
        "firefly/firefly": "^26.09"
    }
}
```

`^26.09` is the constraint the release runbook writes into every package's sibling requirements
(`monorepo-builder bump-interdependency`, see [Publishing](publishing.md)) — and it is the **widest** of the
three shapes below, not the narrowest. Composer normalises `26.09` to `26.09.0.0` and expands a caret to "up
to the next major", so `^26.09` accepts `26.10.x`, `26.12.x` and every other line released in the `26` year.
That matters more under CalVer than it would under semver, because a CalVer number carries no promise that a
bump in anything but the last segment is backward compatible — a month bump is exactly where an incompatible
change is allowed to land. Pick the row that matches how much you actually mean to accept:

| Constraint | `26.09.3` | `26.10.1` | `27.01.0` |
|------------|-----------|-----------|-----------|
| `^26.09` | accepted | accepted | rejected |
| `~26.09.0` | accepted | rejected | rejected |
| `26.09.*` | accepted | rejected | rejected |

"Pin to the release line, accept patches" — the posture CalVer projects generally recommend — is the second
or third row, not the first. Use `^26.09` when you want every release of the `26` year and intend to read
the CHANGELOG at each month bump; use `~26.09.0` (or `26.09.*`) when you want `26.09` patches and nothing
else, and to bump the month deliberately.

For anyone tracking the unreleased development branch directly (a path-repo dev dependency, or a
`dev-main` Packagist requirement) rather than a tagged release, every package's `composer.json` carries:

```json
{
    "extra": {
        "branch-alias": { "dev-main": "26.x-dev" }
    }
}
```

so `dev-main` resolves as `26.x-dev` for Composer's constraint solver, rather than as an untyped dev branch
that could satisfy any constraint.

## Why CalVer

CalVer over SemVer for the same reason the rest of the Firefly family adopted it: a monorepo of two-dozen
interdependent packages releasing together makes a meaningful independent SemVer per package impractical —
every package moves in lockstep on one shared release cadence, so a date-based version communicates *when* a
release happened (and lets you reason about how stale a dependency is) more usefully than a per-package
major/minor/patch number would.
