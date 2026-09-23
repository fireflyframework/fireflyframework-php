## Banner

`larafly-banner.svg` is the project banner embedded at the top of `README.md` and `docs/index.md`. It is a
brand-clean, **placeholder-quality** design — a "LaraFly" wordmark, tagline, and a tasteful firefly/spark
motif built from SVG primitives (circles/ellipses/gradients), not the official Firefly logo. The official
mark (`Group 91.svg`) was not available on this machine at authoring time.

The banner carries an explicit swap point: the `<g id="glyph">` element (marked with an SVG comment
`<!-- SWAP POINT: ... -->` right above it) is where the official `Group 91.svg` mark should be dropped in
once available, in place of the hand-drawn spark glyph. The wordmark, tagline, and background can stay as-is
or be adjusted once the real brand asset is in hand. Like the diagrams below, the banner is self-contained
(no external fonts/images/scripts, generic `font-family`) and theme-neutral (its own background, not reliant
on the surrounding page).

## Logo and favicon

`larafly-logo.svg` (48×48, `theme.logo`) and `larafly-favicon.svg` (32×32, `theme.favicon`) are the banner's
firefly glyph — `<g id="glyph">`, `larafly-banner.svg:35-59` — **redrawn at their own scale, not cropped out
of it**. A crop would have dragged the banner's `viewBox`, its three gradients and its wordmark along with it;
each of these two carries only the gradients it actually uses — one spark for the logo, the spark plus the
tile for the favicon — under its own id prefix (`lfl-`, `lff-`) so that two inlined SVGs on one page cannot
collide. The glyph's geometry is the banner's, scaled: the spark disc, the two wings, the dark body, the
amber tail segment and the three-dot spark trail, in the same proportions.

Where the two differ, they differ because the surface they are drawn on differs:

- **The logo has no background.** Material draws it inside the header, on `--md-primary-fg-color` — the
  banner's own slate — so a ground of its own would show as a tile floating on the header. Its dark body still
  reads because it sits on the opaque centre of the spark disc rather than on the header itself.
- **The favicon carries the slate tile** (`#0f172a → #1e293b`, the banner's background gradient, `rx="7"`),
  because a browser tab has no background of its own: the same transparent glyph would sit on whatever colour
  the browser happens to use and lose the dark body entirely on a dark tab strip.

The logo's wings keep the banner's own `opacity="0.55"` and sit at a slightly wider spread than the banner's;
that spread is what keeps them reading as *two wings* rather than one pale cap at 24px, the size Material
actually renders the header logo at. The favicon's are a shade stronger (`0.6`) because they are competing
with the slate tile behind them rather than with a page.

Both are self-contained on the same terms as the banner and the diagrams: no `<script>`, no `<image>`, no
`@font-face`, no external reference of any kind. `tests/BannerAssetTest.php` holds all three brand assets to
exactly that: each file exists, parses as XML, contains none of those three things, and carries no URL other
than the SVG namespace itself. It also checks that whatever consumes each asset still names it — `mkdocs.yml`
for the logo, the favicon and the stylesheet, `README.md` and `docs/index.md` for the banner, which
`mkdocs.yml` never refers to at all. That second half is the one that earns its keep over time, because the
way an asset like this rots is that a theme key is renamed and the file is orphaned without anything going
red.

Which is why the `mkdocs.yml` side of it is read off the **parsed** document — `theme.logo`, `theme.favicon`
and the `extra_css` list — and not out of the file's text. A whole-file substring match would be satisfied by
any line that happens to spell the path, and this is a config written in a comment-heavy voice: the note above
its `palette:` block already spells `docs/assets/stylesheets/larafly.css` while explaining where the custom
colours live. Matched as text, that comment alone keeps the check green on a site whose `extra_css` key has
been deleted outright — the stylesheet fully unwired, every page rendered unstyled, nothing red. Matched as a
key, a rename or a deletion is not something the test can miss.

## Stylesheet

`stylesheets/larafly.css` is loaded through `extra_css` and holds only what `mkdocs.yml` cannot express: the
palette behind Material's `primary: custom` / `accent: custom` hooks, the `.lf-cards` grid the landing pages
use, the frame that keeps a white-panelled diagram from glaring on the dark scheme, and the density of the
wide configuration tables.

Its palette is read out of `larafly-banner.svg`: the slate background gradient (`#0f172a` → `#1e293b`), the
spark's three ambers (`#fde68a`, `#fbbf24`, `#f59e0b`) and the greys the wordmark, tagline and credit line sit
in (`#f8fafc`, `#e2e8f0`, `#94a3b8`, `#64748b`). Three values are *chosen* rather than read, and the file
marks each one where it is defined. The `--lf-*` tokens are numbered by where each colour falls on the slate
and amber ramps it belongs to, which is why the mid-slate is `--lf-slate-500` (`#64748b`) and not a `-600`:
`#475569` is the 600 step, and the banner does not contain it.

Two of the three are the link inks. `--lf-amber-deep` `#f59e0b` — the outer stop of the banner's spark
gradient — is `hsl(38, 92%, 50%)` and carries 2.15:1 against white, which cannot be a link in a paragraph, so
link text is that same hue and saturation at the lightness where it clears WCAG AA: `#a26907` (4.6:1) resting
and `#845606` (6.3:1) on hover. The banner itself is never asked to clear that bar — its amber sits on slate,
and the one it actually paints opaque is the lighter `#fbbf24`, `hsl(43, 96%, 56%)`. On the slate scheme the
readable direction is the other one, and links are that spark colour itself (9.6:1 on Material's slate page).

The third is `#0b1220`, one step under the banner's darkest slate, for the single thing Material paints with
`--md-primary-fg-color--dark`: the repository block the navigation drawer puts directly under its title,
which is `--md-primary-fg-color` and would otherwise be the same colour.

`tests/BannerAssetTest.php` also holds the palette closed over itself: every `--lf-*` token the stylesheet
reads with `var()` must be a token the stylesheet declares. A custom property is the one CSS reference that
fails silently — `var(--lf-slate-600)` is not a parse error, the declaration is simply dropped and the element
inherits its parent's colour — so a renamed token would otherwise survive `mkdocs build --strict` and every
other check here, and show up only as a page that looks slightly wrong.

# Diagrams

The SVGs under `diagrams/` are **hand-authored, static** architecture diagrams — plain `<rect>`/`<line>`/`<text>`
markup with `viewBox` scaling, `font-family="sans-serif"` (no external font/script/CSS dependency, no
renderer such as Mermaid or PlantUML involved). Each one is self-contained and safe to view directly on
GitHub/Packagist, not only through the built MkDocs site.

Palette (theme-neutral — chosen to read on both a light and a dark surrounding page, since the SVG carries
its own white card background rather than inheriting the page's theme): strokes `#4b5563`, process fills
`#eef2ff` / `#ecfdf5`, neutral chip fills `#f3f4f6`, text `#1f2937`.

Each diagram was drawn directly from the shipped source, not invented — the class/file set it depicts is
noted in its own `<desc>` element and below:

| Diagram | Depicts | Verified against |
|---|---|---|
| `boot-pipeline.svg` | `FireflyServiceProvider::register()` buffering `BootPass`es into `PendingBootPasses`, drained at `booting()`/`booted()`, phases run in kernel-decided order | `packages/context/src/Boot/{FireflyServiceProvider.php,PendingBootPasses.php,BootPhase.php}` |
| `di-autoconfig.svg` | PSR-4 scanner → compiled manifest (`var_export`, zero-reflection load) → `ConditionPassTwoPass` (incremental `#[ConditionalOnMissingBean]`/`#[ConditionalOnProperty]` evaluation) → bean registry → eager singletons | `packages/container/src/Scanner/*`, `packages/context/src/{Scanner,Pass}/*`, `packages/autoconfigure/src/*` |
| `request-lifecycle.svg` | Ordered `WebFilter` chain → `ControllerDispatcher` → `ControllerSecurityGuard`/`#[PreAuthorize]` → `#[RestController]` → RFC-7807 rendering on a thrown exception | `packages/web/src/{Filter,Dispatch,Route}/*`, `packages/security/src/Web/MethodSecurityControllerGuard.php` |
| `outbox-flow.svg` | The genuine same-transaction outbox: `OutboxPreCommitHook` INSERTs the `firefly_eda_outbox` row on the aggregate's own connection *inside* its open transaction, atomic commit, then a separate terminal `PostgresEventConsumer` claims `PENDING` rows with `FOR UPDATE SKIP LOCKED` and marks them `PUBLISHED` | `packages/data/src/{Transaction/TransactionTemplate.php,Domain/DomainEventDispatcher.php}`, `packages/eda-postgres/src/{Outbox/OutboxPreCommitHook.php,PostgresEventPublisher.php,PostgresEventConsumer.php}` |
| `cqrs-eda-bridge.svg` | The `CommandBus`/`QueryBus` pipelines plus the domain → integration-event bridge: a guarded wildcard listener on the in-process dispatcher re-emits every committed `DomainEvent` through `EdaCommandEventPublisher` onto the `firefly/eda` `EventPublisher` port | `packages/cqrs/src/{Command/DefaultCommandBus.php,Query/DefaultQueryBus.php,Event/DomainEventBridge.php,Event/EdaCommandEventPublisher.php,Boot/DomainEventBridgeWiringPass.php}` |
| `security-filter-chain.svg` | The ordered `WebFilter` chain pushed onto Laravel's global middleware by `FilterChainRegistrar::orderedFilters()`, every filter's real `#[Order]`, and the `DelegatingAuthenticationEntryPoint` decision between a login redirect, a Basic challenge and a 401 | `packages/web/src/Filter/{FilterChainRegistrar.php,RequestContextFilter.php,CorrelationIdFilter.php}`, `packages/observability/src/Web/{TracingFilter.php,HttpExchangeFilter.php,MetricsFilter.php}`, `packages/security/src/{Web,Session,OAuth2}/*`, `packages/security-oauth2-client/src/Web/*`, `packages/security-oauth2-server/src/Web/OAuth2AuthorizationServerFilter.php` |

Apache-2.0 © Firefly Software Solutions Inc.
