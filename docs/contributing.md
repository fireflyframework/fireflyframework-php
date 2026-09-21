# Contributing

## Monorepo layout

`fireflyframework-php` is a single monorepo housing every LaraFly package as an independent Composer unit:

- **`packages/*`** — one directory per package (`kernel`, `container`, `config`, `context`, `autoconfigure`,
  `validation`, `web`, `resilience`, `scheduling`, `scheduling-postgres`, `domain`, `data`, `eda` + its three
  broker adapters, `messaging`, `cqrs`, `security`, `actuator`, `observability`, `testing`, `cli`, `firefly`,
  `installer`), each with its own `composer.json`, `src/`, and `tests/`.
- **`skeleton/`** — the `firefly/skeleton` `type: project` create-project template, at the top level, outside
  `packages/*`.
- The root `composer.json` is a `type: project` aggregator: it wires every `packages/*` directory as a local
  Composer **path repository** (`*@dev`) so the whole monorepo installs and tests together from one
  `vendor/`.

## Local setup

```bash
composer install
```

Then activate the committed pre-push safety guard for your local clone — this is required, not optional,
because the guard's local enforcement (as opposed to CI's, which always runs) depends entirely on this being
set:

```bash
git config core.hooksPath scripts/hooks
```

This points git at the repo-tracked `scripts/hooks/` directory instead of the default (untracked, per-clone)
`.git/hooks/`, so the committed `pre-push` hook — which runs `scripts/check-no-sensitive-tracked.sh` — fires
automatically on every `git push` from this clone.

## The gate

Before opening a PR, all of the following must pass:

```bash
composer check          # pint --test && phpstan analyse && pest && deptrac analyse
composer mono-validate   # symplify/monorepo-builder: validates every packages/*/composer.json
.venv-docs/bin/mkdocs build --strict   # docs/: link integrity, no orphaned pages
bash scripts/check-no-sensitive-tracked.sh   # the pre-push guard, run directly
```

`composer check` is itself the composition of four scripts (`pint-test`, `stan`, `test`, `deptrac`) — see
`composer.json`'s `scripts` block. Any one of them failing fails the gate.

## Browser tests

`tests/Browser/` drives the shipped skeleton app in a real Chromium through `pestphp/pest-plugin-browser`
(Playwright). The plugin serves the Testbench-booted app in-process, so a scenario can still assert against
the database and the application log. One file per surface:

| File | What it proves |
|---|---|
| `WelcomeAndApiTest.php` | the welcome page, a JSON controller, Swagger UI |
| `AdminDashboardTest.php` | every dashboard page, the theme toggle, dark mode, phone width |
| `AdminDataBrowserTest.php` | list → filter → record → edit → create → delete → relation |
| `AdminSettingsTest.php` | a feature-switch round trip |
| `ValidationErrorsTest.php` | a 422 from the skeleton's `POST /orders`, worded by the constraint that failed |
| `ErrorPagesDebugTest.php`, `ErrorPagesProductionTest.php` | 404/405/500 with and without the trace; `api/*` stays JSON |
| `ErrorPagesSecuredTest.php` | the 401 page for an anonymous browser; the 403 page for bob after a real sign-in |
| `LoginFlowTest.php` | the framework's form login: the redirect with the saved request, `?error`, the saved request honoured, `POST /logout`, bob's 403, the 401 problem document on an API path |
| `ObservabilityTest.php` | trace ids on the HTTP traffic page and in `/actuator/httpexchanges`, the HTTP server timer as a Prometheus histogram, the tracing switch, an ECS log document carrying the request's trace id |
| `DataSurfacesTest.php` | the `db` health indicator on by default, the datasource page's data-layer panel, a duplicate key refused with a sentence through the data browser |

The fixtures under `tests/Browser/Support/` boot the skeleton with a created app's provider set.
`SecuredBrowserTestCase` puts the framework's own form login and two memory users (`ada`/`ROLE_ADMIN`,
`bob`/`ROLE_USER`) in front of it and `SignedInBrowserTestCase` flips the entry point to the login redirect;
`tests/BrowserSignInFixtureTest.php` drives that fixture through Laravel's test client inside the default gate,
so the sign-in every browser flow stands on is proved without Node. Two facts of the in-process server shape
the fixtures: every `visit()` is a new browser context (a fresh cookie jar — one page object per flow), and
the process is long-lived (the session `Store` is forgotten after every request; the tracer initialises each
fiber's OpenTelemetry context).

The suite is its own PHPUnit testsuite (`browser`), kept out of the default `unit` suite in
`phpunit.xml.dist` — a group exclusion would not do, because the plugin starts Playwright the moment a
file under `tests/Browser/` is loaded. So `composer test` and `composer check` never need Node. To run it:

```bash
npm ci
npx playwright install chromium   # once
composer test:browser             # add -- --headed to watch
```

Screenshots land in `tests/Browser/Screenshots/` (git-ignored; CI uploads them as the
`browser-screenshots` artifact). Pass Pest options through Composer with `--`, e.g.
`composer test:browser -- --filter=AdminDashboard`. A page that fails here is fixed in the package that owns it, with a
DOM-level regression test beside the existing ones — the browser scenario is the proof, not the only test.

## Architecture rules

Package boundaries are enforced with **Deptrac** (`deptrac.yaml`): every package is its own layer, and the
`ruleset` section declares which layers each one may depend on. A dependency edge that isn't declared is a
Deptrac violation — this is how the monorepo keeps, for example, `firefly/kernel` free of any dependency on
`firefly/web`, or `firefly/installer` free of every framework runtime dependency (it depends on nothing
layered at all — `symfony/console`/`symfony/process` only).

Packages from an already-shipped milestone are treated as **FROZEN**: once a package's milestone is done and
reviewed, further edits to its `src/` are the exception, not the rule, and are called out explicitly in
commit messages and code comments when they do happen (e.g. `// It modifies NOTHING in firefly/context
(frozen 26.07.4)` in `packages/autoconfigure/src/FireflyAutoConfigureServiceProvider.php`). If your change
needs to touch a frozen package, say so explicitly in the PR description and be prepared to justify why a
non-frozen seam (a new hook point, a new package) couldn't do the job instead.

## Conventions

- **Style:** Laravel Pint (`composer pint`/`pint-test`), default preset.
- **Static analysis:** PHPStan at `max`-equivalent strictness (`composer stan`).
- **TDD:** tests are written alongside (generally before) implementation — see the shipped test suites under
  every `packages/*/tests/` for the house style; `firefly/testing` is the shared harness every package's own
  tests are built on.
- **Versioning:** CalVer (`YY.MM.Patch`) — see [Versioning](versioning.md). No package's `composer.json`
  carries a `version` field.

## Never commit

The following must never be tracked in git — enforced both by the local `core.hooksPath` pre-push hook (once
configured above) and by CI running the same guard on every push:

- `.superpowers/` and `docs/superpowers/` — internal design docs/specs/plans, git-ignored and excluded from
  the built docs site.
- `.claude` (file or directory).
- `.env` / `.env.*` (`.env.example` is explicitly allowed).
- Private key material (`id_rsa`, `id_ed25519`, `*.pem`, `*.p12`, `*.pfx`) or a literal secret marker (a PEM
  private-key header, an AWS access key ID pattern) in any tracked file's content.

`scripts/check-no-sensitive-tracked.sh` is the single source of truth for these rules — read it directly if
you need the exact patterns rather than relying on this list going stale.
