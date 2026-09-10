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

```php
// packages/kernel/src/Version.php
final class Version
{
    public const string VERSION = '26.09.2';
}
```

`Firefly\Kernel\Version::VERSION` is read by `firefly:about` (actuator-over-CLI) and the `/actuator/info`
endpoint. Consistency across the three human-visible surfaces that *should* always agree with it — the
`Version::VERSION` constant, the CHANGELOG's latest `## [x.y.z]` heading, and the README version badge — is
enforced by `tests/VersionConsistencyTest.php`, which fails the build the moment any of the three drifts from
the others. A release always updates all three together. Work merged between releases therefore accumulates
under a `## [Unreleased]` heading in the CHANGELOG — the test reads the first *versioned* heading, so an
unreleased section is invisible to it and the constant stays the single source of truth until the release is
actually cut.

## Reading the version at runtime

```php
use Firefly\Kernel\Version;

echo Version::VERSION; // "26.09.2"
```

This is the only version string LaraFly itself exposes; there is no runtime version-detection mechanism
beyond this constant (e.g. no reading it back out of an installed `composer.lock` at runtime).

## Constraints

Application `composer.json` files should depend on Firefly packages with a caret constraint against the
current month, e.g.:

```json
{
    "require": {
        "firefly/firefly": "^26.07"
    }
}
```

`^26.07` allows any patch release within `26.07.x` but not a `26.08.x` release — the same "pin to the
release line, accept patches" posture CalVer projects generally recommend, since CalVer numbers don't carry
semver's guarantee that a bump in the last segment is always backward compatible.

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
