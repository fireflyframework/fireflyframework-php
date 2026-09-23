# Contributing

## Monorepo layout

`fireflyframework-php` is a single monorepo housing every LaraFly package as an independent Composer unit:

- **`packages/*`** — one directory per package (`kernel`, `container`, `config`, `context`, `autoconfigure`,
  `validation`, `web`, `resilience`, `scheduling`, `scheduling-postgres`, `domain`, `data`, `eda` + its three
  broker adapters, `messaging`, `cqrs`, `security`, `security-oauth2-client`, `security-oauth2-server`,
  `actuator`, `observability`, `admin`, `openapi`, `testing`, `cli`, `firefly`, `installer`), each with its
  own `composer.json`, `src/`, and `tests/`. `ls packages` is the list that cannot go stale;
  `.github/workflows/release.yml`'s split matrix is the one that has to name every one of them.
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
`composer.json`'s `scripts` block. Any one of them failing fails the gate. It does **not** include
`composer test:browser`: `test` runs the `unit` testsuite, which excludes `tests/Browser`, so the default gate
never needs Node. The documentation tests *are* in it — they are ordinary Pest files under `tests/` — so a
stale code listing or an unlinked module guide fails `composer check` like any other regression.

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

## Documentation

Documentation is held to the same gate as code, by seven Pest tests and a strict site build.

**Every code listing names the file it came from.** A fenced `php` block in `README.md`, on any page under
`docs/`, or in the English manuscript under `book/src` — `DocsCodeAudit::AUDITED` is the list, and adding a
path to it is how a surface joins the gate — must carry one of two HTML comments on the line above it:

```markdown
<!-- source: packages/web/src/Filter/FilterChainRegistrar.php -->
<!-- illustrative: a controller the reader writes in their own application -->
```

A `source:` block is compared **verbatim** against that file, line for line — one constant indentation offset
is allowed (a method excerpted out of its class sits at column 0), and whole lines may be cut with a line that
is exactly `// …` (or `# …` in a file whose comments start with a hash). An `illustrative:` block is for code
that *cannot* exist in this repository — an application's own class — and still has to parse, import only
framework classes that exist and use only attributes that exist. Every block, marked or not, is additionally
checked for the `firefly.*` keys, `php artisan firefly:*` commands and `composer <script>` invocations it
names. `tests/DocsCodeIsRealTest.php` is the test; `tests/Support/DocsCodeAudit.php` is the engine, and its
docblock is the full contract. **A red run is fixed by making the document true**, never by deleting an
assertion or by marking a framework excerpt `illustrative:`.

**The English manuscript is inside that contract.** `book/src` is an entry in `DocsCodeAudit::AUDITED`, every
one of its `php` listings carries a `source:` or an `illustrative:` marker, and each `source:` one is compared
line for line against the file it names exactly as a page under `docs/` is. Three further rules apply to a
book excerpt, because a cut is where a true listing turns useless: a `// …` may not swallow the declaration it
belongs to — leaving a `{` with nothing above it saying what is being declared — it may not reduce a body
to `{`, `// …`, `}`, which prints a method that appears to do no work, and an excerpt that shows a docblock
must also show what that docblock documents: its `/**`, its closing line and at least one line of real code
after it, or the page prints a paragraph about a declaration the reader never sees.
`DocsCodeAudit::verifyExcerpt()` refuses all three, and its docblock carries the regressions that motivated
each.

`book/build/verify_code.py book/src` and `book/build/verify_code.py book/src-es` — the two invocations
`book/README.md` documents — are the lint half, and they have **one exemption**: a listing carrying a
`source:` marker is not handed to `php -l`. A verbatim fragment of a real file (one method lifted out of its
class, an interface's signatures, a docblock) does not parse on its own, and the only way to make it parse is
to add lines the file does not have — which is precisely the untrue listing the marker exists to prevent. Such
a listing is held to the comparison instead, and the file it quotes is already analysed by this repository's
own PHPStan and Pint runs. `illustrative:` and unmarked listings are still linted, and they are the ones that
need it: no file backs them.

Both manuscripts are now converted: `book/src` and `book/src-es` are each named in
`DocsCodeAudit::AUDITED`, every `php` listing in either tree carries one of the two markers, and
`--require-provenance` exits 0 over both. So a green `verify_code.py` run over either edition says every
unmarked-for-comparison listing parses *and* that every marked one matches the file it shows.

**A new Spanish listing means two edits, not one.** A `source:` marker takes its listing OUT of the
`php -l` half, so a marked file that is not also reached from `DocsCodeAudit::AUDITED` would be checked by
neither half — not linted, not compared. `AUDITED` names the two directories rather than individual files
precisely so a new chapter cannot slip in unaudited; `DocsCodeIsRealTest` refuses a marker on a page it does
not audit.

**The two editions must stay level, listing for listing and claim for claim.** `book/src` is where a wave
lands first, and `tests/DocsProseIsRealTest.php` holds the two trees together three ways. It measures the
per-chapter size of both: while any pair diverges, `README.md` and `book/README.md` must say so beside their
"complete in both languages" sentence, and when none does, the warning has to go. It pairs the **fenced
blocks** themselves, so a `php` listing or a `source:`-marked excerpt whose bytes differ between the editions
is a failure — a Spanish fence may translate a trailing shell or tree comment, which is prose a reader reads,
and a page claiming the two match "character for character" has to say that is what it covers. And it holds
the two **chapter-opening promises** to the same set of backticked identifiers, because that paragraph is
what a wave forgets when it brings a chapter body level and stops one sentence short — five of them shipped
describing the pre-wave chapter, one of them telling a Spanish reader that method security lives only at the
CQRS bus. A change to a fact both editions state (a count, a default, a retired claim) belongs in **both**
trees in the same commit: the prose guard walks `src-es` too, and a correction that reaches one edition only
is the bilingual version of the defect this whole gate exists to remove.

**Every claim a sentence makes is derived, not typed.** `tests/DocsProseIsRealTest.php` is the other half of
the listing guard and the larger one: a wrong listing cannot ship, but a wrong *sentence* can, and several
did. It walks `README.md`, every page under `docs/` and **both manuscripts** — the same surface the listing guard
above now covers — and holds any paragraph that makes an exhaustive
claim against a value read out of the source at test time, never against a number typed into the test. A
count is a claim, and the cheapest one to get wrong, so counts are read as words as well as digits, in
English and in Spanish.
Under guard today: the expression functions `SecurityExpressionEvaluator` really dispatches, the actuator
inventory and every "404 until exposed" sentence, the exceptions `PersistenceExceptionTranslator` really
builds, the order in which `ErrorPageRenderer` really reads an `Accept` header, the stereotype hierarchy PHP
really declares, the Composer constraint tables `composer/semver` really matches, the `make:firefly-*` tables
the generators really back, every `firefly:cache` figure `ManifestCacheWriter` really produces (the console
line, the pair count, the step count and the artifact count, in both manuscripts), the capabilities `--with`
really fetches — the ones the `firefly/firefly` metapackage does *not* already require — the roster of
documentation guards this very section names, the registration default `DbHealthIndicator`'s own condition
attribute really declares (a stale **default** is as dangerous as a stale count, and that one outlived its
change in five places), the account these pages give of the book's own gate, pinned to what `verify_code.py`
really lints, the `App\Support\CachedTransactionalConfiguration` workaround no application needs any more, the
status `samples/lumen` really asserts when a wallet command arrives with no credentials, the header names the
masker's own regex really covers, the visibility and the coverage `EloquentRepository`'s read helpers really
have, the recording doubles `packages/testing` really ships (a chapter promised ten beside a directory of
eleven, and the missing one was the double six new sections were calling), the `@method` tags
`RecordRepository` really carries beside every paragraph that counts its derived methods, the route the shipped
skeleton really serves beside every `curl … localhost:8000/…` transcript — a `json` body printed under a path
`WelcomeController` renders as HTML is a command the reader runs and watches fail — the eight comparison
labels `DataFilter::operators()` really declares wherever a page enumerates the data browser's filters (the
dashboard ships no localisation, so a translated label is a label that does not exist), and how far apart the
two manuscripts really are wherever a page calls the book complete in both languages. The triggers are
deliberately narrow — a page may mention `hasRole()` or `/actuator/env` in passing without owing the full
enumeration — so **a red run here is fixed by correcting the sentence**, never by loosening the trigger that
caught it.

**Diagrams are hand-written SVG.** No Mermaid, no PlantUML, no raster: plain `<rect>`/`<line>`/`<text>` on a
white rounded panel, `font-family="sans-serif"`, exactly one `<title>` and one `<desc>`, marker ids prefixed
per file, and no `<script>`, `<image>`, `@font-face` or external reference of any kind — so the file is safe
to view straight from GitHub. `docs/assets/README.md` documents the palette and lists, per diagram, the
sources it was drawn from. Each one ships twice and byte-identically, as
`docs/assets/diagrams/<name>.svg` and `book/art/figures/<name>.svg`; `tests/DocsDiagramsTest.php` holds the
pair identical, holds each file to the rules above, and fails if a diagram is embedded nowhere.
`tests/BannerAssetTest.php` does the same for the banner, the logo, the favicon and the stylesheet, and also
checks that `mkdocs.yml` still *names* each of them, because the way a brand asset rots is that a theme key is
renamed and the file is orphaned with nothing going red.

**Build the site locally before you push docs.** CI's `docs` job installs exactly `mkdocs-material` and runs
`mkdocs build --strict`; no plugin needing a native library (the `social` plugin's cairo/pango among them) may
be added, because that job would stop working. Reproduce it with a throwaway virtualenv — `.venv-docs/` is
already git-ignored:

```bash
python3 -m venv .venv-docs
.venv-docs/bin/pip install mkdocs-material
.venv-docs/bin/mkdocs build --strict
```

**Every link resolves, and every number a page quotes is counted, not typed.** `tests/ReadmeDocLinksTest.php`
walks every relative Markdown link in `README.md` and fails if one points at a file that is not there — a
renamed document is otherwise a 404 nobody meets until a reader does. `tests/SiteNavigationTest.php` does
the same job for the site and three more besides: every tab in `mkdocs.yml` is followed the way Material
follows it (a section's tab opens its FIRST child, descending while that child is itself a section) and
required to land on a document that exists; `navigation.indexes` is required to be listed *exactly* when a
section really has an `index.md`/`README.md` for it to bind, because the feature is inert otherwise and the
first explanation of the tab row was wrong in precisely that way; the package and guide counts quoted in
prose — on `README.md`, `docs/README.md`, `docs/index.md` and `docs/modules.md` — are compared against
`glob()` over `packages/*` and `docs/modules/*.md` rather than proof-read, so a thirty-third guide fails the
build instead of turning four sentences into lies no diff touched; and the two wiring idioms
`docs/modules.md` names are held to the split the packages really have, exemplar files included.

`tests/ModuleDocumentationTest.php` closes the last loop: every `docs/modules/*.md` must appear in
`mkdocs.yml`'s navigation, in the root `README.md`'s module table, in `docs/README.md`'s, in
`docs/index.md` and on `docs/modules.md`, and every `docs/modules/<name>.md` a package README promises must
exist.

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

- **Style:** Laravel Pint (`composer pint`/`pint-test`). `pint.json` sets the `laravel` preset and three rules
  on top of it: `declare_strict_types` (every file opens with `declare(strict_types=1);`), `final_class: false`
  (a class is `final` when its author means it, not by fiat — `firefly:cache` generates proxies that `extend`
  a repository), and alpha-sorted imports. `skeleton/` is excluded.
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
