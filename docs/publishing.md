# Publishing

This page is the release/split runbook for the LaraFly monorepo: how the 25 packages under `packages/*`
(including `firefly/installer`), plus `firefly/skeleton` at the top level — 26 shippable units in total — end
up as individually-installable Packagist packages, and the exact, gated sequence for the first manual publish.

## Model

Development happens in one monorepo (`fireflyframework-php`); each package is published as a **read-only**
Packagist mirror at `fireflyframework/firefly-<pkg>` (e.g. `fireflyframework/firefly-kernel`). All mirrors
share **one CalVer tag** — a single `v26.07.17`-style tag on the monorepo cuts a release of every package at
once, at the same version, even for packages that had no code change that cycle. There is no independent
per-package versioning; see [Versioning](versioning.md) for why.

The mirrors are read-only by design: nobody commits directly to `fireflyframework/firefly-kernel` — every
change flows through the monorepo and gets split out mechanically. This keeps the 24+ mirror repos from ever
drifting out of sync with each other or with the monorepo history.

## Automated split

Once wired (tracked separately from this docs task), `.github/workflows/release.yml` fires on a pushed `v*`
tag and runs [`symplify/monorepo-split-github-action`](https://github.com/symplify/monorepo-split-github-action)
once per package unit, pushing each `packages/<pkg>` subtree to its own
`fireflyframework/firefly-<pkg>` mirror repository at that tag. The workflow needs an `ACCESS_TOKEN` — an
organization-level GitHub Personal Access Token with `repo` scope on every mirror — stored as a repository (or
organization) secret, since the default `GITHUB_TOKEN` can't push to a *different* repository.

## Interdependency

Dev uses `*@dev` path repos (untouched) — every package in `packages/*/composer.json` requires its siblings
as `firefly/xyz: "*@dev"`, resolved locally via the root `composer.json`'s `path` repository entry. That's
what makes `composer install` at the monorepo root wire the whole tree together for local development and the
test suite.

At release, `vendor/bin/monorepo-builder bump-interdependency 26.07.17` rewrites every sibling constraint from
`*@dev` to `^26.07` **on the release commit that gets tagged and split**, so the resulting mirrors are
stable-installable on their own — a consumer running `composer require fireflyframework/firefly-eda` never
sees a `*@dev` constraint, which `minimum-stability: stable` (the default posture) would refuse to resolve.
Immediately after the tag is cut and split, the monorepo tree is restored to `*@dev` so local development
continues undisturbed.

The `extra.branch-alias: { "dev-main": "26.x-dev" }` entry every package carries is unaffected by this
dance — it remains in place for anyone tracking `dev-main` directly rather than a tagged release.

## Manual first publish (controller + user, gated)

The very first publish is done by hand, one careful step at a time, with a human in the loop at every
irreversible action — not scripted end-to-end. Steps 4 and onward are **irreversible**: they push public
history, create public mirror repositories, and register public Packagist packages. Do not proceed past step
3 without the operator (a human, not an agent) explicitly confirming each subsequent step.

1. **`git config core.hooksPath scripts/hooks`** — activate the committed pre-push guard hook for this local
   clone. This is the *first* action, before anything else in this runbook, so the guard fires automatically
   on every push below without relying on anyone remembering to run it manually.
2. Confirm access to the `fireflyframework` GitHub org and repo-creation rights within it; confirm the
   `ACCESS_TOKEN` org PAT and a Packagist API token are both available to whoever is running this runbook.
3. Run the pre-push guard (it also runs automatically via the hook from step 1, but run it explicitly here as
   a checkpoint) plus a final manual sweep that must come back **empty**:
   ```bash
   bash scripts/check-no-sensitive-tracked.sh
   git ls-files | grep -iE 'superpowers|\.claude|\.env$'
   ```
4. **On a release commit:**
   ```bash
   vendor/bin/monorepo-builder bump-interdependency 26.07.17
   ```
   Verify every `packages/*/composer.json` now requires its siblings as `^26.07` (not `*@dev`), run
   `composer validate` per package, commit the result, and **re-tag** `v26.07.17` at this commit — so the tag
   that gets pushed and split in the next step is the one carrying `^26.07` constraints, not `*@dev`.
5. **`git remote add origin git@github.com:fireflyframework/fireflyframework-php.git` then
   `git push origin main --tags`** — **irreversible**: this publishes the monorepo's history and the release
   tag publicly for the first time.
6. **Create the 26 mirror repositories under the `fireflyframework` org, then run the split at the tag** —
   **irreversible**: each `fireflyframework/firefly-<pkg>` mirror now exists publicly, carrying `^26.07`
   sibling constraints.
7. **Staged Packagist registration** — register only a first wave, then verify, before committing the rest:
   register `firefly/kernel`, `firefly/container`, `firefly/config`, and `firefly/eda` on Packagist first.
   Then, in a scratch directory, under Composer's default `minimum-stability: stable`:
   ```bash
   mkdir /tmp/firefly-publish-check && cd /tmp/firefly-publish-check
   composer init --no-interaction
   composer require firefly/eda:^26.07
   ```
   **Confirm this resolves and installs cleanly** before doing anything else. Only if it succeeds, register
   every remaining package plus `firefly/firefly` (the runtime metapackage) and `firefly/installer`. If it
   fails, **stop** — the interdependency-constraint strategy needs fixing, and only four packages are affected
   (versus discovering the same problem after all 26+ are already permanently registered on Packagist).
8. After publishing, restore the monorepo dev tree to `*@dev` — revert the `bump-interdependency` commit (or
   bump the constraints back by hand) — so local development on `main` continues exactly as before this
   runbook started.
