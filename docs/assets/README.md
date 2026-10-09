## Official family identity

`larafly-banner.svg` is the official family banner used by the repository README
and documentation landing page. `larafly-logo.svg` is the dark-surface wordmark
configured as the MkDocs header logo. Both come from the shared 2026-10-08 Firefly
Framework brand kit, `Framework-Brand-Kit/12-Frameworks/php/`.

`larafly-logo-light.svg` and `larafly-logo-dark.svg` retain the canonical variants
for other surfaces. Their typography is outlined and all artwork is self-contained:
no fonts, scripts, linked images or external resources are needed to render it.
The favicon uses the shared kit’s `02-Icons/favicon.svg`. The 1280×640 social
preview embeds the official dark-surface family lockup; its technical captions
and feature chips remain unchanged. Its PNG is rendered from that checked-in SVG.

The public consumers retain their established filenames. `tests/BannerAssetTest.php`
checks that the banner and header logo still exist, parse correctly, remain
self-contained and are connected to their actual README/MkDocs consumers.

## Stylesheet

`stylesheets/larafly.css` is loaded through `extra_css` and holds only what `mkdocs.yml` cannot express: the
palette behind Material's `primary: custom` / `accent: custom` hooks, the `.lf-cards` grid the landing pages
use, the frame that keeps a white-panelled diagram from glaring on the dark scheme, and the density of the
wide configuration tables.

The documentation now uses the shared Firefly identity: ink `#10110f`, warm
neutrals `#f3f1eb` / `#dedbd2` and amber `#ffb34a`. The legacy `--lf-slate-*`
token names are preserved as a stable theme interface. Body links on light
surfaces use dark amber `#8a5714`, with `#69410d` on hover; dark surfaces use
canonical amber and its pale `#ffd69a` highlight. Layout and table density are
unchanged. Semantic warning, error and third-party colors retain their meaning.

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

The self-contained white card uses ink `#10110f`, neutral strokes `#62645b`,
warm process fills `#fff0d8` / `#edf1e8`, and paper chips `#f3f1eb`. An outlined
larafly lockup occupies a small footer below the original viewBox. All technical
labels, paths and coordinates are preserved. The inventory and non-paint
structure hashes are in `book/art/diagram-branding.json`; maintenance commands
and canonical asset provenance are in `book/art/PROVENANCE.md`. Book mirrors
remain byte-identical. Source-derived diagram tests still validate the claims.

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
| `oauth2-authorization-code.svg` | The authorization-code + PKCE round trip across browser, relying party and authorization server: the `HttpSecurityFilter` denial that reaches `LoginUrlAuthenticationEntryPoint` and sends the browser to `/login` (never straight to a provider), the `/oauth2/authorization/{id}` start, the 302 the relying party answers and the browser follows out to `/oauth2/authorize` (a front-channel leg, drawn through the browser lane exactly as the return leg is), the single-use `state`, the `nonce`, the S256 `code_challenge`, the one `OAuth2AuthorizationServerFilter` that matches every server endpoint, the server's own form login and its own `ConsentPage`, the single-use code, the back-channel `POST /oauth2/token` and the JWKS the id token's signature is checked against | `packages/security-oauth2-client/src/{OAuth2ClientSettings.php,Web/OAuth2AuthorizationRequestRedirectFilter.php,Web/OAuth2AuthorizationRequest.php,Web/OAuth2LoginAuthenticationFilter.php,Web/Login/OAuth2LoginPageLinks.php,Oidc/OidcIdTokenValidator.php}`, `packages/security-oauth2-server/src/{Settings/AuthorizationServerSettings.php,Web/OAuth2AuthorizationServerFilter.php,Web/AuthorizationEndpoint.php,Web/Consent/ConsentPage.php,Web/Grant/AuthorizationCodeGrant.php,Web/TokenEndpoint.php,Web/JwkSetEndpoint.php,Pkce/ProofKey.php}`, `packages/security/src/Web/{HttpSecurityFilter.php,EntryPoint/LoginUrlAuthenticationEntryPoint.php,Login/FormLoginFilter.php,Settings/FormLoginSettings.php}`, `packages/cli/src/Command/OAuth2KeysCommand.php` |
| `method-interceptor-chain.svg` | One proxy per bean: every `AdviceSource` (`#[Component]`) contributing `scan()` rows to one `ProxyPlan` that `ProxyPlanner` merges in `Advice::order`, `ProxyPlanCompiler` `var_export`ing it into `proxy-plan.php`, `ProxyClassGenerator` emitting one `{Target}__FireflyTransactionalProxy` carrying one private interceptor property and one baked static descriptor factory per advice kind, and the runtime chain a call then walks through `MethodInvocation::proceed()` — `MethodSecurityInterceptor` at advice order 100 outside `TransactionInterceptor` at 1000, the terminal `parent::m(...$args)`, and the unwind in which the transaction commits before `#[PostAuthorize]`/`#[PostFilter]` are applied | `packages/data/src/Proxy/{Advice.php,AdviceSource.php,TransactionalAdviceSource.php,ProxyPlanner.php,ProxyPlanCompiler.php,ProxyPlan.php,ProxyClassGenerator.php,ProxyFactory.php,InterceptorRegistry.php,MethodInvocation.php,PassThroughInterceptor.php}`, `packages/data/src/Transaction/{TransactionInterceptor.php,TransactionalBeanPostProcessor.php,TransactionTemplate.php}`, `packages/security/src/Access/Method/{MethodSecurityAdviceSource.php,MethodSecurityInterceptor.php,MethodSecurityEvaluator.php}`, `packages/cli/src/Cache/ManifestCacheWriter.php` |
| `tracing-propagation.svg` | One W3C `traceparent` entering at `TracingFilter` (`#[Order(-110)]`, the outermost discovered filter) and flowing outward: the `SERVER` span whose ids are published to Laravel `Context` and `Request::$attributes` as `firefly.trace_id`/`firefly.span_id`, the `INTERNAL` span `TracerCqrsTracing` puts on every command and query, the `PRODUCER`/`CONSUMER` pair `TracerEdaTracing` puts around an envelope whose headers carry the `traceparent` — the in-memory and queue buses, and since 26.09.4 the three broker publishers as well, gated by `firefly.eda.tracing.brokers.enabled`, the `CLIENT` span `HttpClientTracingMiddleware` puts on every outbound `Http` call (injecting the header again, `url.path` and never `url.full`), and the three places the ids then land — `TraceContextLogProcessor`'s `trace_id`/`span_id` on every log record, the `traceId` `HttpExchangeFilter` reads back off `Request::$attributes` for `/actuator/httpexchanges`, and the dashboard page `firefly/admin` renders from the same registries | `packages/observability/src/Web/{TracingFilter.php,HttpClientTracingMiddleware.php,HttpExchangeFilter.php}`, `packages/observability/src/Tracing/{W3CTraceContextPropagator.php,NoOpTracer.php,NoOpSpan.php,SpanContext.php}`, `packages/observability/src/{Cqrs/TracerCqrsTracing.php,Eda/TracerEdaTracing.php,Logging/TraceContextLogProcessor.php,Logging/StructuredLogging.php,Boot/HttpClientTracingPass.php,ObservabilityAutoConfiguration.php}`, `packages/cqrs/src/Tracing/{CqrsTracing.php,NoOpCqrsTracing.php}`, `packages/eda/src/Tracing/{EdaTracing.php,NoOpEdaTracing.php,BrokerTracing.php}`, `packages/admin/src/{AdminEndpointReader.php,Web/AdminPage.php}` |

Apache-2.0 © Firefly Software Solutions Inc.
