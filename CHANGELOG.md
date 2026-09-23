# Changelog

All notable changes to LaraFly are documented here. This project uses CalVer (`YY.MM.Patch`).

## [Unreleased]

Eight defects found by building a second real application on `26.09.2`, every one of them a place where the
application had to work AROUND the framework rather than with it — a refusal type outside the taxonomy caught
at a hundred and forty-four sites, three exception renderables registered ahead of the framework's, a
RouteMatched listener validating ids, a replacement JWKS provider, thirty-five hand-written role checks, a
whole Kafka consumer command, and a shell script whose body was one line. Each fix was reproduced by a
failing test first, and each one deletes a workaround downstream.

Alongside them, `firefly/data` reaches Spring Data parity: a driver failure now leaves a repository or a
`#[Transactional]` method as a typed member of the kernel's `DataAccessException` family with a fixed sentence
and never the statement, query by example is a `Specification`, `#[Modifying]`/`#[Projection]`/`#[Lock]`/
`#[EntityGraph]` and `Slice` are compiled into the manifest by the one scanner the package already had,
`#[Transactional(timeout:)]` is enforced rather than carried, `#[TransactionalEventListener]` runs in four
phases, and the actuator's `db` health indicator is on whenever a database is configured — every behaviour
behind a documented `firefly.data.*` key and tested through the real Testbench pipeline.

### BREAKING

- **`packages/actuator` + `packages/security` — `when-authorized` health details are real, and no longer a
  synonym for `never`.** `firefly.management.endpoint.health.show-details: when-authorized` used to degrade to
  `never` because `firefly/actuator` has no code edge to `firefly/security` and could not say who asked. It now
  asks a deny-by-default **`Firefly\Actuator\Health\HealthDetailsAuthorizer`** port, which `firefly/security`
  fills from the session-held principal whenever `firefly.security.enabled` is on (`src/Actuator`, a new
  `Security → Actuator` deptrac edge; `firefly/actuator` still names no principal). **An application already
  running `when-authorized` with security on therefore starts disclosing what it used to withhold**: with the
  new `firefly.management.endpoint.health.roles` at its default `[]` — Spring's "any AUTHENTICATED principal"
  — every authenticated caller now reads the component details, which name database drivers, disk paths,
  broker hosts and indicator error messages. An application with `firefly/security` absent or its master flag
  off is unaffected: the deny default stands and the body is byte for byte what it was.
  **Migration:** decide, do not inherit. Either set `show-details: never`, or list the roles that may read them
  in `firefly.management.endpoint.health.roles` (a list or Spring's CSV string; a bare name is read as
  `ROLE_<name>` and the role hierarchy applies; a value whose entries are all unusable refuses everybody rather
  than admitting them, and never fails the scrape), or bind your own
  `HealthDetailsAuthorizer` bean — actuator's and security's are both `#[ConditionalOnMissingBean]` and back off.
  In the same change the `final` `Firefly\Actuator\Health\HealthEndpoint` gained a required fourth constructor
  parameter (`HealthDetailsAuthorizer $authorizer`), so an application that constructs it directly rather than
  resolving the bean must pass one — `new DenyHealthDetailsAuthorizer` reproduces the old behaviour exactly.

- **`packages/actuator` — the `db` health indicator is on by default.** Like Spring Boot's
  `DataSourceHealthIndicator` auto-configuration, `DbHealthIndicator` now registers whenever `database.default`
  names a connection with a driver (`#[ConditionalOnProperty(... matchIfMissing: true)]` plus the new
  `ConditionalHealthIndicator::available()` hook, which `HealthContributorRegistrar` honours). A failing or
  missing database is reported DOWN and `/actuator/health` answers **503**; an application with no default
  database gets no `db` component at all. **Migration:** `FIREFLY_HEALTH_DB_ENABLED=false` (or
  `firefly.management.endpoint.health.db.enabled => false`) removes the indicator. An application whose
  `config/firefly.php` was generated before this release still has the old `false` written out and keeps the
  old behaviour until it edits that line.

- **`packages/web` — problem+json no longer discloses an unhandled throwable's message when `app.debug` is
  on.** The JSON renderer shared the HTML page's `firefly.web.error-page.trace` gate, which follows
  `app.debug` — the wrong gate for a machine surface. Every local and compose environment sets `APP_DEBUG`,
  so a console fed by problem+json rendered a duplicate-key insert as the DSN, the tenant id, the acting user
  and the full statement in a red banner, while the HTML page beside it withheld everything. The problem
  document now has its own gate, **`firefly.web.problem.disclose`**, default `false`, inheriting from
  nothing. **Migration:** a developer who wants driver messages inside JSON `detail` sets that key; nothing
  else changes, and the message is still on the exception for the log.

- **`packages/web` — the router's own 404 and 405 sentences are replaced with ones written for a person.**
  `The route api/x could not be found.` becomes `There is nothing at this address.` and `The GET method is
  not supported for route api/x. Supported methods: POST.` becomes `This address only accepts POST.`, with
  the verbs in an `allowed` extension member and the `Allow` header copied through (it used to be dropped).
  "Route" is the framework's word and the path is already in `instance`. An author's `abort(404, '…')`
  message is kept verbatim. **Migration:** assert on `code` (`RESOURCE_NOT_FOUND`, `METHOD_NOT_ALLOWED`),
  not on the router's sentence.

- **`packages/web` — problem+json's `traceId` is the W3C trace id, and the correlation id is its own member.**
  A member named after a trace carried a uuid no trace backend had ever heard of, so the one action a problem
  document invites — quote this id — resolved nothing in a trace search. `Firefly\Web\Trace\TraceContext`
  now decides the id three surfaces publish at once: the document's **`traceId`**, the HTML error page's
  **`Reference`** row, and a new **`X-Trace-Id`** response header written by `CorrelationIdFilter` on every
  response (and by the problem renderer on the ones it builds). A request with no valid span — every request
  in a deployment with tracing off — puts the correlation id back in the document and on the page, byte for
  byte what both carried before, and gets no `X-Trace-Id` at all: turning tracing on is the only thing that
  changes any of the three. The correlation id is NOT absorbed: `X-Correlation-Id` echoes it untouched, the
  document gains a **`correlationId`** member holding it, and the page carries a **`Correlation`** fact row
  beside `Reference` when the two differ. **Migration:** a client that reads `traceId` and matches it against
  `X-Correlation-Id` matches `correlationId` instead; `firefly.web.trace-id.enabled => false`
  (`FIREFLY_WEB_TRACE_ID_ENABLED=false`) restores the previous value in all three surfaces and writes no new
  header, and `firefly.web.trace-id.header => ''` drops only the header. It is deliberately not W3C
  `traceresponse`, which LaraFly does not implement.

- **`packages/security` — a method-security refusal no longer names the PHP class on the wire.** `Access is
  denied for [App\Ctrl::admin].` becomes `You do not have permission to do this.` with the authorities the
  rule asked for in a `requiredAuthorities` extension member; the class, method, principal and authorities go
  to the log at warning. One real application kept every `#[PreAuthorize]` at `isAuthenticated()` and judged
  roles by hand at thirty-five sites because a consultant had read a class name on a panel. **Migration:**
  assert on `ACCESS_DENIED` and `requiredAuthorities`, not on the sentence.

- **`packages/security` — a JWKS outage is a `503 JWKS_UNAVAILABLE`, not a `401 INVALID_TOKEN`.**
  `OAuth2ResourceServerFilter` wrapped every throwable from `JWT::decode($token, $this->jwks->keys())` — the
  key fetch included — as an invalid token, so an unreachable issuer told every caller their token was bad and
  a well-behaved client rotated a good one. The keys are resolved before the try that maps decoding failures.
  `RemoteJwksProvider` throws `JwksUnavailableException` (a `ServiceUnavailableException`) for every fetch
  failure — 5xx, refused, timed out, not JSON — where it used to let the HTTP client's `RequestException`
  escape. **Migration:** a test asserting `RequestException` asserts `JwksUnavailableException`.

- **`packages/security` — a method-security refusal for an ANONYMOUS caller at the CQRS bus is a `401`, not a
  `403`.** `MethodSecurityMessageEnforcer` used to answer `ACCESS_DENIED` whether or not anyone was signed in; it now
  shares `MethodSecurityEvaluator` with the dispatcher guard and the proxy interceptor, and all three say
  `AUTHENTICATION_FAILED` for nobody and `ACCESS_DENIED` for somebody. **Migration:** a test asserting a 403 for an
  anonymous command asserts a 401.

- **`packages/security` — a `final` bean carrying method-security rules that no dispatch seam enforces refuses
  `firefly:cache` (and the uncached boot).** A `#[Service]`/`#[Component]`/`#[Repository]` with `#[PreAuthorize]` and
  the like is now proxied so the rule is actually enforced; the proxy must extend the class. Before this wave those
  rules were silently unenforced. **Migration:** remove `final`, or move the rule onto the controller or handler.
  The same scan refuses a `#[PreFilter]` on a `#[RestController]`/`#[Controller]` action (the dispatcher cannot
  rewrite the arguments it resolved) and a `#[PostAuthorize]`/`#[PreFilter]`/`#[PostFilter]` on a class with no
  `#[Component]`-family stereotype (nothing would enforce it). **Migration:** move the filter onto the service the
  action calls; add a stereotype to the class.

- **`packages/web` — `ControllerSecurityGuard` gains `afterInvocation()`.** An implementation outside this repo adds
  the method (return `$result` to keep the old behaviour). `ArgumentResolver`'s constructor takes an optional third
  `HandlerMethodArgumentResolvers`. `RouteScanner` records two optional binding keys (`attributes`, `nullable`) and
  compiles an interface-typed parameter as a `service` binding rather than a query parameter.

- **`packages/data` — the proxy engine is a `MethodInterceptor` chain.** `TransactionalBeanPostProcessor` is constructed
  from a `ProxyPlan` and an `InterceptorRegistry`; `ProxyMethod` takes a list of `BoundAdvice` instead of a
  `TransactionalDescriptor`; `ProxyMaterializer::materialize()` takes a planner and a plan; `TransactionInterceptor`
  implements `MethodInterceptor`. Every proxy member name, `ProxyFactory::wrap()`'s first four parameters and the
  compiled `transactional.php` are unchanged; `firefly:cache` additionally writes `proxy-plan.php`. **Migration:** run
  `php artisan firefly:cache` once.

- **`packages/eda` — `ReceivedEnvelope::$envelope` is nullable, and `KafkaConsumerClient` gains
  `deadLetterRaw()`.** A record the serializer cannot decode is now a *poison* record (`ReceivedEnvelope::
  poison()`: raw bytes, failure, destination) rather than an exception thrown out of `poll()`, outside every
  catch in the process, that killed the worker onto the same offset for ever. The adapters catch `Throwable`
  around the decode, not `SerializationException` alone, and `JsonSerializer::deserialize()` now refuses a
  member of the wrong type (a string `payload`, an int `eventType`, a non-string header) and an unparseable
  `timestamp` as a `SerializationException` instead of leaking a `TypeError` or a
  `DateMalformedStringException` out of `EventEnvelope::fromArray`. `ConsumerLoop` nacks a poison record
  without requeue and keeps polling; `KafkaEventConsumer` produces the raw bytes to `<topic>.DLT` and commits;
  `RabbitMqEventConsumer` lets the queue's DLX take it. `RdKafkaConsumerClient` and `RabbitMqEventConsumer`
  take the `Serializer` port rather than `JsonSerializer` (a widening; every caller still passes
  `JsonSerializer`). **Migration:** an `EventConsumer` implementation outside this repo reads
  `$received->envelope` as nullable (`?->`), a `KafkaConsumerClient` implements `deadLetterRaw(string $raw,
  string $dltTopic)`, and a test that expected a `TypeError` from `JsonSerializer::deserialize()` on a
  well-keyed body expects `SerializationException`.

- **`packages/security` — a URL rule written `/api/*` stops being a dead rule, so every `/`-prefixed rule you
  have changes what it does on upgrade.** `HttpSecurityFilter` matches `Str::is($rule->pattern,
  $request->path())`, and Laravel's `path()` never carries a leading slash, so `['pattern' =>
  '/actuator/health', 'access' => 'permitAll']` matched **nothing**: the request fell through to
  deny-by-default and 401'd on the one path the operator had explicitly opened, with no error anywhere to say
  why (a dead rule is indistinguishable from a rule that did not apply). `HttpSecurity::requestMatcher()` —
  the single door `anyRequest()` and `fromConfig()` both come through — now normalises every pattern to the
  `$request->path()` spelling, so `/api/*` and `api/*` are one rule; `'/'` keeps its slash, because that is
  what `path()` answers for the root (and `'/'` is the one `/`-prefixed pattern that already matched). This
  entry is **not** under *Fixed*: what changes is the effective access control of a running deployment, and it
  moves in **both** directions.
  - **Fail-OPEN — the direction to audit first.** `rules: [['pattern' => '/admin/*', 'access' => 'permitAll'],
    ['pattern' => '*', 'access' => 'authenticated']]` served `/admin/secret` only to an authenticated caller:
    the first rule was dead (`Str::is('/admin/*', 'admin/secret')` is `false`) and the second matched. After
    this change `fromConfig()` stores that pattern as `admin/*`, the first rule matches, and **the path is
    served anonymously**.
  - **Fail-closed.** A previously-skipped `/`-prefixed `hasRole:`/`denyAll` rule sitting ahead of a broader
    `permitAll` now matches first and starts refusing callers the broader rule used to let through.

  **Migration:** before upgrading, grep `firefly.security.http.rules` — and any `HttpSecurity::create()` chain
  — for `/`-prefixed patterns, and read the list in order as if every one of them were live, because now they
  are. A `/`-prefixed `permitAll` ahead of a broader rule is the shape that opens a path; narrow it, move it
  after the broader rule, or delete it. Patterns with no leading slash are untouched, and a rule set with none
  behaves exactly as before. The normalisation is also what lets `firefly/openapi` publish a truthful
  `security` member: it reads these same rules, and a rule meaning one thing to the document and nothing to
  the filter would be a published claim the server does not honour.

  **The leading slash is the only thing normalised.** A pattern is matched against a request path, so one
  carrying a route placeholder — `['pattern' => '/api/orders/{id}']` — is still a dead rule afterwards
  (`Str::is('api/orders/{id}', 'api/orders/7')` is `false`), and nothing rewrites it into `api/orders/*` on
  your behalf: a placeholder stands for a segment only the route knows the shape of, and a matcher that
  guessed would open paths nobody wrote. Copy the route's shape rather than its text.

### Added

- **`packages/openapi` — the document publishes the security the server actually has.** `components.securitySchemes`
  and each operation's `security` are generated from `firefly.security.*`, read through the **`Config` port**, so
  no class of `firefly/security` is imported and `deptrac.yaml` gains no edge — which is what the module doc had
  for two releases given as the reason none of it could be emitted. `http_basic.enabled` publishes `httpBasic`,
  `jwt.enabled` publishes `bearerAuth`, and `oauth2.resource_server.enabled` publishes `oauth2ResourceServer`
  (deliberately `type: http`, not `type: oauth2`: a resource server issues no tokens and has no flow URLs, and an
  `oauth2` scheme with empty `flows` renders as an un-fillable form). Each operation then carries the requirement
  the **same `firefly.security.http.rules`** give its path: a `permitAll` path carries no `security` member at all,
  and every other path — including one no rule matches, because the rules are deny-by-default — names **every**
  configured scheme, since the OR-list is what the running filters really accept. A `hasScope:` rule puts its scope
  on the bearer entry. `securitySchemes` and `security` are absent rather than empty when there is nothing to say,
  because an empty `security` array is OpenAPI's positive claim that no authentication is required. Gated by
  **`firefly.openapi.security.enabled`** (default `true`) and inert while `firefly.security.enabled` is off. Two
  ports — `SecuritySchemeContributor` and `SecurityRequirementContributor` — let another package add what
  configuration cannot state; they are collected from the container's tagged-interface list, so an implementation
  is registered as a **`#[Component]`** (a `#[Bean]` under the concrete type is never tagged and would be dropped
  in silence). A contributed scheme may carry **default scopes**, which `SecurityModel` puts on a requirement that
  names that scheme and states none of its own — the split the ports create, since the package holding the scope
  vocabulary is not the one asked about each route; a requirement that states its own keeps them. The config-driven
  contributor is the only implementation that ships. Method rules (`#[PreAuthorize]`, `#[Secured]`) are **not** read
  into requirements. A rule pattern carrying a **route placeholder** (`'/api/orders/{id}'`) is a dead rule for the
  filter, which never sees a template, and is a dead rule here too — every `{...}` is blanked before the patterns
  are tried, so `api/orders/*` covers the operation and `api/orders/{id}` leaves it published as protected rather
  than as a path the server does not actually open. Scope lists serialise as JSON **arrays**, empty ones included:
  a Security Requirement Object's value is typed `[string]` by the 3.1 meta-schema, and `{"bearerAuth": {}}` is a
  document Swagger UI cannot read.

- **`packages/resilience` — the six patterns as attributes, on the proxy chain.** `#[Retry]`,
  `#[CircuitBreaker]`, `#[RateLimiter]`, `#[Bulkhead]` and `#[TimeLimiter]` name an instance configured under
  `firefly.resilience.*`, and `#[Fallback]` names a recovery method on the same class; each is applied to a
  `#[Service]`/`#[Component]`/`#[Repository]` method through the same proxy chain `#[Transactional]` already
  uses. The five registry-backed attributes take a method or a class (class-level applies to every public
  method, method-level replaces it) — with one exception: **the class-level fan-out stops at a method a
  `#[Fallback]` names**, Resilience4j's own treatment of `fallbackMethod`. The recovery is invoked on the
  bean, which is the proxy, so a guard fanned onto it would be applied by the very link that is unwinding and
  the class breaker that just opened on the failure would refuse the recovery — "degrade" turned into "fail
  twice". A pattern written on the recovery by hand is still honoured. Every attribute **wraps the
  programmatic component this package already
  ships** — there is one `Retry`, one `CircuitBreaker`, one of each, and the attribute is a second door to
  it, so the two call styles cannot diverge. Within the link the composition is Resilience4j's and is fixed:
  `Fallback ( Retry ( CircuitBreaker ( RateLimiter ( TimeLimiter ( Bulkhead ( method ) ) ) ) ) )`, pinned on
  a recorded transcript rather than argued. The link runs at advice **order 200** — inside method security
  (100), so a call a `#[PreAuthorize]` refuses never spends a retry budget or trips a breaker, and outside
  the transaction (1000), so **each retry attempt opens a transaction of its own** and an attempt that wrote
  rows before it threw is rolled back before the next one begins. A `#[Fallback]` naming a method the class
  does not have, or one whose signature cannot receive the guarded call, refuses to compile at
  `firefly:cache`. Gated by **`firefly.resilience.method.enabled`** (default `true`); off makes every
  resilience attribute inert — a pass-through proxy link — and never half-applied, and it does not change the
  compiled plan, so a cached and a dev application agree on the shape of every proxy. Documented in
  `docs/modules/resilience.md` and the config reference.

- **`packages/data` — `MethodInvocation::invocableClone()`, the sanctioned re-entry for a repeating advice
  link.** `proceed()` is single-use by design (Spring's `ReflectiveMethodInvocation`): it advances a cursor,
  so a second call from the same link reaches the NEXT link rather than the remainder it just ran. A link
  that must run the remainder AGAIN — a retry, and nothing else in this framework — takes an
  `invocableClone()` per repetition and proceeds on that, exactly as Spring Retry does. Each clone carries
  its own cursor and its own argument list, so every repetition re-enters the whole chain below the link
  (the transaction most of all) and an inner `setArguments()` stays scoped to its own repetition.

- **`packages/security-oauth2-client` — a new package: Spring Security's `oauth2Login()` and `oauth2Client()` (wave B).**
  Client registrations in Spring Boot's shape (`firefly.security.oauth2.client.registration.{id}` / `provider.{id}`)
  with **presets** for `google`, `github`, `okta`, `keycloak` and `microsoft`/`entra` and **OIDC discovery** for any
  `issuer_uri` (bounded, validated against the issuer, cached, lazy — `discovery.eager` resolves at boot; a dead
  issuer is a `503 OIDC_DISCOVERY_UNAVAILABLE`), every static refusal at boot naming the key; **authorization-code
  login** (`OAuth2AuthorizationRequestRedirectFilter` `-89`, `OAuth2LoginAuthenticationFilter` `-88`) with
  single-use constant-time `state`, a nonce for `openid`, **PKCE S256** on by default and mandatory for a public
  client, an exact `redirect_uri` check, the code exchanged over Laravel's Http client with `client_secret_basic`/
  `client_secret_post`/`none`, the **id token verified** against the provider's JWKS through `RemoteJwksProvider`
  and validated per OIDC Core §3.1.3.7 (`iss`, `aud`, `azp`, `exp`, `iat`, `sub`, `nonce`), userinfo loaded and
  merged, and an **`OidcUser`/`OAuth2User` principal** (claims, `OIDC_USER`/`OAUTH2_USER` + `SCOPE_x`, a
  **`GrantedAuthoritiesMapper`** bean seam) signed into the session-persisted `SecurityContext` with the session id
  regenerated and the event family published (`InteractiveAuthenticationSuccessEvent::OAUTH2_LOGIN`); the
  framework's **login page lists every provider** as "Sign in with …"; **RP-initiated logout**
  (`logout.oidc_initiated`) on the new `LogoutSuccessHandler` port; **`OAuth2AuthorizedClientManager`** for client
  credentials and refresh over an encrypted session repository and an encrypted cache service, and
  **`Http::oauth2Client('{id}')`**. Tokens are stored encrypted with the application key, the principal in the session
  carries claims and never a token, and no exception or log line carries a secret, a code, a verifier or a token.
  Every key lives under `firefly.security.oauth2.client.*` and defaults to off; the package is required by
  `firefly/firefly`, documented in `docs/modules/security-oauth2-client.md` and the config reference, and gated by a
  Testbench capstone suite plus a Chromium round trip (`tests/Browser/OAuth2LoginTest.php`).

- **`packages/security` — four seams for a second login mechanism.** `LoginPageLinks`/`LoginPageLink` (the login
  page draws "Sign in with …" buttons after the form, or alone when `form` is off; `LoginPageModel` gains `form` and
  `links`), `LogoutSuccessHandler` (asked by `LogoutFilter` before the session is invalidated; null means the
  default redirect), `hasScope()`/`hasAnyScope()` in `SecurityExpressionRoot`, the evaluator's whitelist and
  `authorities()`, and `hasScope:<scope>` in the URL vocabulary, and `FormLoginSettings::$pageEnabled` —
  `firefly.security.oauth2.client.login.enabled` implies the login page, the session middleware and logout exactly
  as `form_login.enabled` does (the entry point's `login` mode accepts it too). The page's `?error` notice names the
  provider when there is no password form. `InteractiveAuthenticationSuccessEvent::OAUTH2_LOGIN`.

- **`packages/testing` — `FakeAuthorizationServer`, `actingAsOidcUser()`, `actingAsAuthentication()`.**
  `Firefly\Testing\Security\OAuth2\FakeAuthorizationServer` is an OpenID Connect provider in one class: real
  front-channel routes (`/authorize` with an optional consent page, `/end-session`) mounted on the application and
  a faked back channel (`/.well-known/openid-configuration`, `/token`, `/jwks`, `/userinfo`) answering the real
  calls the framework makes — RS256 tokens from a per-process key pair, single-use PKCE-checked codes, Basic/post/
  none client authentication, rotating refresh tokens, every hop recorded, and knobs for every failure mode.
  `actingAsOidcUser()` signs a real `OidcUser` in without a provider; `actingAsPrincipal()` takes the token's
  `attributes` and delegates to the new `actingAsAuthentication()` seam.

- **`packages/kernel` — the `DataAccessException` family, and a transaction timeout.**
  `DataIntegrityViolationException` (409), `DuplicateKeyException` (409, under it), `CannotAcquireLockException`
  (409), `DeadlockLoserDataAccessException` (409, under it), `QueryTimeoutException` (504),
  `TransientDataAccessResourceException` (503), `DataAccessResourceFailureException` (503),
  `BadSqlGrammarException` (500), `EmptyResultDataAccessException` (404), `IncorrectResultSizeDataAccessException`
  (500) and `OptimisticLockingFailureException` (409), every one under `DataAccessException`. Beside the family,
  not in it, `TransactionTimedOutException` (504) extends `Infrastructure\TimeoutException` like every other
  timeout — Spring's `TransactionException` side — so `catch (DataAccessException $e)` does not see it and
  `catch (TimeoutException $e)` does. `DataAccessException`'s constructor gains trailing `httpStatus`/`severity`
  parameters; `Firefly\Data\Repository\Locking\OptimisticLockException` is now an
  `OptimisticLockingFailureException` and keeps its name and code.

- **`packages/data` — Spring Data parity.** `PersistenceExceptionTranslator` behind
  `firefly.data.exception-translation.enabled` (default on), applied in every `EloquentRepository` method, in
  `TransactionTemplate` and therefore in every `#[Transactional]` method, with the driver/SQLSTATE tables in one
  file (`DriverErrorTable`, one test per row) and fixed-sentence messages; query by example (`Example`,
  `ExampleMatcher`, `StringMatcher`, `GenericPropertyMatcher`, `findByExample`/`findOneByExample`/
  `countByExample`/`existsByExample`/`findByExamplePaged`); `#[Modifying]` (statements, affected-row count,
  transaction required unless said otherwise, refused on a SELECT at scan time); `#[Projection(Dto::class)]`
  (constructor hydration with lossless typed coercion, reflection-free at runtime); `#[Lock(LockMode::PESSIMISTIC_WRITE|
  PESSIMISTIC_READ)]` and `findByIdForUpdate()` (transaction required); `#[EntityGraph]` with named graphs on
  every read; `Slice` and `PagingAndSortingRepository::findSlice()`, derived queries paging by a trailing
  `Pageable`; `getById()`; `#[Transactional(timeout:)]` enforced with a per-driver statement timeout
  (`StatementTimeoutApplier` — pgsql `statement_timeout`, mysql `max_execution_time`, mariadb
  `max_statement_time`, sqlite's busy timeout) and a wall-clock deadline that rolls back and throws the kernel's
  `TransactionTimedOutException` (a `TimeoutException`, 504), `firefly.data.transaction.default-timeout` and
  `.statement-timeout`; `#[TransactionalEventListener]` in `BEFORE_COMMIT`/`AFTER_COMMIT`/`AFTER_ROLLBACK`/
  `AFTER_COMPLETION` over a `TransactionSynchronizationRegistry` that follows savepoints, registered by the new
  `DataWiringProvider` (`firefly.data.transactional-event-listeners.enabled`). The compiled `transactional.php`
  gains `repositories` and `listeners` maps; older files still load.

- **`packages/admin` — the datasource page shows the data layer** (exception translation, default transaction
  timeout, statement timeout, transactional listeners) and the data browser's write failures read as sentences
  for a duplicate key, a broken constraint, a lock, a timeout or an unreachable database — never the SQL.

- **`packages/security` — Spring Security 6 parity for everything that is not OAuth2 client/server (wave A).**
  A session-persisted `SecurityContext` (`SecurityContextPersistenceFilter`, `SessionSecurityBootstrap` pushing
  Laravel's cookie/session middleware globally ahead of the filter chain, fixation protection), **form login** with the
  framework's own sign-in page (`firefly.security.form_login.*`, CSRF-checked against the session token, redirect to
  the saved request), **HTTP Basic** (`http_basic.*`, RFC 7617 challenge), **logout** (`logout.*`, POST only),
  **remember-me** (`remember_me.*`, Spring's signed-token cookie, key held to the JWT secret rule), a negotiating
  **`AuthenticationEntryPoint`** (`http.entry_point`: `auto`|`login`|`challenge`|`problem`), **`#[PostAuthorize]`**,
  **`#[PreFilter]`/`#[PostFilter]`**, **method security on any stereotyped bean** (`method.enabled`) through the shared
  proxy chain with security ahead of transactions, **principal injection** (`Authentication`, `?UserDetails`,
  `#[AuthenticationPrincipal]`, `#[CurrentSecurityContext]`), an **Eloquent `UserDetailsService`**
  (`users.driver = eloquent`), and the **event family** (`AuthenticationSuccessEvent`,
  `InteractiveAuthenticationSuccessEvent`, `AuthenticationFailureBadCredentials/Locked/DisabledEvent`,
  `LogoutSuccessEvent`, `AuthorizationDeniedEvent`) published through the context port. Every key defaults to off.

- **`packages/security-oauth2-server` — an OAuth 2.1 / OpenID Connect 1.0 authorization server inside the
  application (wave C), Spring Authorization Server's shape on the security core.** Registered clients from a
  validated config map or Eloquent (`RegisteredClientRepository`), the authorization-code grant with PKCE (S256
  only), the framework's own consent page (or `consent.view`), single-use codes, client credentials, refresh
  tokens with rotation and reuse detection (a replayed token revokes the family), RS256/ES256 JWT or opaque
  reference access tokens with an `OAuth2TokenCustomizer` port, id tokens (`nonce`, `auth_time`, `sid`,
  `at_hash`), signing keys with rotation (`jwt.previous_keys`, `php artisan firefly:oauth2:keys`), JWKS published
  through the security core's `JwksDocumentSource` so `jwks_source: local` makes the application its own resource
  server, RFC 8414 / OIDC discovery, RFC 7662 introspection, RFC 7009 revocation, OIDC userinfo
  (`OidcUserInfoMapper`), RP-initiated logout through the same `LogoutHandler` the logout filter uses, RFC 7591
  registration on a single-use initial access token, an `OAuth2AuthorizationService` with memory
  and Eloquent drivers (tokens stored by SHA-256 hash only), a scheduled purge on the framework's own schedule, a
  per-client rate limit over firefly/resilience, and every protocol error as the RFC 6749 document or the
  redirect-with-error. One filter at `-82` answers every endpoint ahead of `CsrfFilter` and
  `HttpSecurityFilter`, so deny-by-default rules need no entry, and the boot refuses every pairing that would be
  a dead end (no master flag, no session security, `jwt.enabled`, `http_basic.enabled`, no signing key, a client
  block that could not authenticate or redirect, dynamic registration over a store that forgets, a rate limit
  with no store). Every key under `firefly.security.oauth2.server.*` defaults to off.

- **`packages/security-oauth2-server` — `/actuator/oauth2clients`, and `packages/admin` — the OAuth2 clients
  page** (`/firefly/oauth2`, group Wiring): every registered client with its grants, scopes and live
  authorization count, read in-process, never a secret — with PKCE reported both as registered and as enforced,
  and the counts qualified by `authorizations.processLocal` so a per-process zero is not read as "nobody holds a
  token".

- **`packages/cli` — `firefly:oauth2:keys`.** Generates the server's private key (RSA 2048 or `--algorithm=ES256`)
  into `storage/oauth2/private.pem` with owner-only permissions, or prints it with `--print`.

- **`packages/testing` — `OAuth2ServerTestClient`.** `authorize()`, `approveConsent()`, `obtainCode()`,
  `exchangeCode()`, `clientCredentials()`, `refresh()`, `introspect()`, `revoke()`, `userInfo()`, `tokens()`
  against the application's own server; the browser suite drives the whole authorization-code flow in
  Chromium (`tests/Browser/OAuth2AuthorizationCodeTest.php`).

- **`packages/data` — `MethodInterceptor`, `MethodInvocation`, `Advice`, `AdviceSource`, `ProxyPlan`,
  `ProxyPlanner`, `InterceptorRegistry`.** The transactional proxy generalised into an ordered interceptor chain any
  package can contribute to; `firefly:cache` compiles `proxy-plan.php`.

- **`packages/web` — `HandlerMethodArgumentResolver`/`HandlerMethodArgumentResolvers`.** The extension point a package
  registers a controller-argument resolver into, consulted before the built-in binding kinds.

- **`packages/testing` — `actingAsPrincipal()`, `withoutSecurity()`, `#[WithMockUser]`,
  `RecordingAuthenticationEvents`.**

- **`packages/kernel` — RFC 9457 extension members and a per-exception title on `FireflyException`.**
  `withExtensions([...])`/`extensions()` and `withTitle('…')`/`title()` (also constructor arguments), spread
  by `ErrorResponse::toArray()` after the standard members — so an extension can never override `status`,
  `code` or `title`. `Business\PaymentRequiredException` (402 `PAYMENT_REQUIRED`) joins the taxonomy: a sales
  event, not a permission problem. `ErrorResponse::titleFor()` is public and knows 402, 405 and the other
  common statuses. The OpenAPI problem schema declares `additionalProperties: true` so a generated client
  keeps the members an application put there.

- **`packages/web` — every problem document carries `traceId`, `correlationId` and both id headers.**
  `traceId` is the id a person quotes — the request's **W3C trace id** when tracing gave it a valid span, the
  correlation id when it did not — and it is echoed on `X-Trace-Id`; `correlationId` is always the correlation
  id (`CorrelationIdFilter::of()`: Context, then the header, then minted), echoed on `X-Correlation-Id` and
  untouched by any of this. An opaque 5xx names the first of the two: `An unexpected error occurred. It has
  been logged; quote reference <id> if you report it.` A 503 carries `Retry-After`; PHP's own `Maximum
  execution time of N seconds exceeded` is answered as `503 EXECUTION_TIME_EXCEEDED` rather than a 500
  quoting the engine.

- **`packages/web` — `#[PathVariable(pattern:, notFoundCode:, notFoundMessage:)]`.** The segment's shape is
  checked by `ArgumentResolver` before the controller runs, and a miss is the entity's own 404 (default
  `RESOURCE_NOT_FOUND`, sentence derived from the parameter name) — a 404 and not a 400, so under row-level
  security the wire cannot tell "no such row" from "not even an id". `PathVariable::UUID` is the RFC 4122
  shape. `RouteScanner` refuses an invalid pattern at cache time; `firefly/openapi` publishes it as the
  parameter's JSON Schema `pattern`. A malformed uuid used to reach `?::uuid` and answer 500.

- **`packages/security` — `#[PreAuthorize(expression, code:, message:)]`.** A rule can carry its own product
  code and sentence (`RUN_ROLE_REQUIRED`, "Only a manager may start a run.") so the role rule and the words
  for breaking it live beside the method they guard. Compiled into `SecurityMethodDescriptor`; a manifest
  compiled before the keys existed still loads. `SecurityExpressionEvaluator::authorities()` lists the
  authorities an expression names, roles normalised to `ROLE_`.

- **`packages/security` — bounded, typed, in-process JWKS.** `RemoteJwksProvider` connects and reads with
  five-second timeouts (`jwks_connect_timeout`/`jwks_timeout`; Laravel's default of thirty equals PHP's
  execution limit and turned a slow issuer into a fatal error). `JwksDocumentSource` is the port a server
  that signs its own tokens implements; `LocalJwksProvider` answers from it with no socket, and
  `firefly.security.oauth2.resource_server.jwks_source` (`auto`|`local`|`remote`) chooses — `auto` when the
  source is bound and `JwksUri::isOwn()` says `jwks_uri` names this application. A server fetching its own
  keys from itself over HTTP was one nested request per authenticated call (844 of 3,000 measured) and a
  deadlock on a single-process dev server.

- **`packages/eda` — `EnvelopeSink`, the port `firefly:eda:consume` delivers to.** Bind one and the command
  delivers every envelope to it instead of the `#[EventListener]` registry (`SubscriberRegistrySink`, the
  default). An application whose events go to a command bus used to write its own consumer command, loop,
  signal handling, offset commits and dead-letter path because the only seam was a callable the command built
  and never let anyone replace. `ConsumerLoop` takes an optional PSR logger and reports each poison record.

- **`packages/scheduling` — sub-minute `fixedRate`/`fixedDelay`.** Everything at or under sixty seconds
  used to bucket onto `everyMinute()`, so `fixedRate: '10s'` ran six times less often than it said.
  `Cadence` maps a rate onto Laravel's repeat-seconds cadences (1, 2, 5, 10, 15, 20, 30 s), rounding *up*
  (`'7s'` → 10 s, `'45s'` → a minute), and is the one table both the wiring pass and `firefly:schedule` use.

- **`packages/cli` — `php artisan firefly:schedule {--once}`.** The companion to `firefly:serve`: lists every
  `#[Scheduled]` task with the cadence it will really run at, its lock and its zone, then delegates to
  `schedule:work` (or one `schedule:run` with `--once`). A `#[Scheduled]` method fires only under a scheduler
  and nothing started one; one real application lost a verification pass to a product whose clock was stopped.

- **Browser end-to-end suite (`tests/Browser`, PHPUnit testsuite `browser`).** The shipped skeleton app is served
  to a real Chromium through `pestphp/pest-plugin-browser` (the whole suite moved from Pest 3 to Pest 4 for
  it): the welcome page, every admin dashboard page, the data browser's list → filter → edit → create →
  delete → relation round trip, a feature-switch toggle, and the 401/403/404/405/500 pages in both themes,
  at phone width, with and without the trace. Excluded from the default gate; `composer test:browser` and a
  dedicated CI job run it, with screenshots uploaded as an artifact.

- **`packages/web` — the HTML error page publishes the request reference.** The production 500 now reads
  "quote reference `<id>` if you report it" and every page carries a `Reference` fact — the same value
  problem+json publishes as `traceId` and the response echoes on `X-Trace-Id`: the W3C trace id when the
  request had a valid span, the correlation id when it did not. A `Correlation` fact row holding the
  `X-Correlation-Id` value sits beside it, and is omitted when the two ids are the same string. Found by the
  browser suite.

- **Cross-wave browser scenarios (`tests/Browser/LoginFlowTest.php`, `ObservabilityTest.php`,
  `DataSurfacesTest.php`).** The framework's real form login replaces the harness's `?as=user` stand-in
  (`tests/Browser/Support/FixturePrincipalFilter.php` is deleted): a browser refused at `/orders` is sent to the
  framework's login page, a wrong password is answered on it, the right one comes back to the saved request,
  `POST /logout` lands on the signed-out notice and leaves the browser anonymous, bob gets the 403 page at his
  saved request, and an API path keeps its 401 problem document. The observability wave is proved in Chromium —
  trace ids on the admin HTTP traffic page and in `/actuator/httpexchanges`, the HTTP server timer as a
  Prometheus histogram, the tracing switch on the settings page — plus the ECS log document a traced request
  writes, read back in-process and matched to the exchange row's trace id. The data wave: the `db` indicator UP
  with no key set, the datasource page's data-layer panel, and a duplicate key refused with the data browser's
  sentence rather than a 500. `tests/BrowserSignInFixtureTest.php` drives the sign-in fixture through Laravel's
  test client inside the default gate. No new configuration keys.

- **`packages/observability` — distributed tracing (wave F).** The `Tracer` port grew into a Spring/OTel-shaped
  API (`startSpan(name, kind, attributes, parent): Span`, `currentSpan()`, `Span::{setAttribute, addEvent,
  setStatus, recordException, updateName, deactivate, end}`, `SpanContext`, `SpanKind`, `SpanStatus`) with
  `NoOpTracer` still the default and `trace()` kept. `OpenTelemetryAutoConfiguration` binds an OpenTelemetry
  tracer when `open-telemetry/sdk` is installed and `firefly.observability.tracing.enabled` is on (default
  off): `none`, `console` or `otlp` exporters (http/protobuf, http/json, grpc), `always_on`/`always_off`/`ratio`
  samplers, `service.name` and resource attributes, a bound `SpanExporterInterface` bean winning over config. A
  first-party `W3CTraceContextPropagator` carries `traceparent`/`tracestate`: `TracingFilter` starts a SERVER
  span per request (named by the route template, ids in Laravel `Context` and on the
  `/actuator/httpexchanges` row as `traceId`), `HttpClientTracingMiddleware` gives every Laravel `Http` call a
  CLIENT span and the header, and two new seams shaped like `CqrsMetrics` — `Firefly\Cqrs\Tracing\CqrsTracing`
  (INTERNAL spans per command/query) and `Firefly\Eda\Tracing\EdaTracing` (PRODUCER/CONSUMER spans,
  `traceparent` in the envelope headers, on the in-memory bus, the queue bus and every broker's consumer
  sink) — are filled by observability and no-ops otherwise. `firefly/testing`'s `RecordingTracer` implements
  the whole port in memory (`recorded()`, `find()`, `ofKind()`). New docs: `docs/modules/tracing.md`.

- **`packages/observability` — log correlation and structured logging.** `TraceContextLogProcessor` stamps
  `trace_id`, `span_id`, `correlation_id` and `request_id` on every record of the configured channels;
  `firefly.logging.structured.format` (`json` | `ecs` | `logstash`, default `''`) applies Monolog's
  `JsonFormatter`, a first-party ECS 8 `EcsFormatter`, or Monolog's `LogstashFormatter` to those channels'
  existing handlers — never replacing one — with `service.name`/environment on every line. An unknown format,
  or a listed channel `logging.channels` does not define, refuses the boot from `LogChannelWiringPass`. New
  docs: `docs/modules/logging.md`.

- **`packages/observability` — histogram buckets.** `firefly.observability.metrics.distribution.buckets` and
  `distribution.per-meter.<name>` give timers cumulative `_bucket{le}` lines (`# TYPE … histogram`, plus the
  same `_count`/`_sum`) in `SimpleMeterRegistry` and `CacheMeterRegistry` alike; without buckets a timer stays
  the `summary` it was. Default off.

- **`packages/admin` — the HTTP traffic page shows the trace id**, and `firefly.observability.tracing.enabled`
  is a feature switch.

- **`packages/validation` — `#[Valid]` cascades into list elements.** `#[Valid] array $lines` is validated
  element by element with the element class's compiled constraints, keyed the way the client wrote them
  (`lines[0].sku`, `lines[2].quantity`). The element class comes from `#[Valid(each: X::class)]` (new), a
  `@var list<X>` docblock on the member, or the constructor's `@param list<X> $lines` — read by ONE resolver
  (`ContainerElementType`) that `RouteScanner` and the OpenAPI generator's fallback now share, so a list the
  validator checks is a list the hydrator builds. A `#[Valid]` list whose element class cannot be told (no
  docblock, `list<string>`, `list<list<X>>`) is a `ConfigurationException` at `firefly:cache` time, not a
  silent skip. The skeleton's `OrderRequest` uses it; a bad SKU on the second line is a 422 naming
  `lines[1].sku`, where it was a 400 `UNBINDABLE_BODY`.

- **`packages/validation` — Bean Validation's `message` element, and `constraint` on every field error.**
  Every constraint attribute takes `message:` (`#[Size(min: 1, max: 50, message: 'between {min} and {max}
  lines')]`, placeholders filled; `#[Rules('min:3', message: '…')]` as a named argument) and implements
  `HasMessage`. `FieldError` gains `constraint` — the attribute that failed (`NotBlank`, `Size`, `Pattern`),
  Spring's `FieldError` code — serialised after `code`, documented in the OpenAPI problem schema. The
  compiled manifest carries a `ConstraintDescriptor` table under a reserved `@constraints` key;
  `IlluminateValidator` is a `SmartValidator` (Spring's name) that receives it; a custom `Validator` an
  application bound stays on the plain path. **`firefly.validation.messages`** (`constraint` | `laravel`,
  default `constraint`) is read into a `ValidationSettings` bean.

- **`packages/eda-rabbitmq`, `packages/eda-kafka`, `packages/eda-postgres` — the three broker publishers stamp
  `traceparent`.** Their `publish()` methods build the envelope inside `EdaTracing::tracePublish()` instead of
  beside it, so the message that reaches the exchange, the topic or the `firefly_eda_outbox` row carries the
  PRODUCER span's `traceparent` and the consumer on the far side continues the trace. The consume side was
  already traced through `SubscriberRegistrySink`, so until now one of our own traces stopped at the broker
  while a foreign producer's was continued. On Postgres it covers **both** writers of a row — the
  `EventPublisher` bean and the in-transaction `OutboxPreCommitHook`, which under `provider=postgres` is the
  only path a `DomainEvent` takes — so the traced row is the one that commits with the aggregate; and
  `firefly:outbox:relay` wraps its forward in `traceConsume()` of the claimed row, so the downstream producer
  span is a child of the trace the row carries rather than a new root that overwrites it. New key
  **`firefly.eda.tracing.brokers.enabled`** (default `true`) keeps in-process spans while putting no trace
  identifier on a wire a third party reads; it is read in one place, `Firefly\Eda\Tracing\BrokerTracing`, by the
  three publisher beans AND by `RelayDownstream`, because a gate that lives only in a bean fails open wherever
  the container autowires a publisher instead. The relay applies it whether its downstream names a shipped
  adapter by the alias (`rabbitmq`) or by that adapter's own class-string — two spellings of one downstream,
  which now take the same configured path and carry `firefly.eda.rabbitmq.exchange` / the Kafka broker list
  across it alike. A publisher the framework ships no adapter for stays yours to construct and yours to gate.

- **Browser suite — `tests/Browser/ValidationErrorsTest.php`.** The skeleton's `POST /orders` driven from a
  page: a fixture route's button `fetch()`es the API through the in-process server and renders the problem
  document's `errors` on the DOM; the scenario asserts `lines[1].sku — must match "^[A-Z0-9][A-Z0-9-]{2,31}$"
  [Pattern]`.

### Changed

- **`larastan/larastan` is pinned to `~3.11.0` at the root.** Larastan 3.12 made the Eloquent `Builder`
  template invariant and started requiring `view-string` for every `Factory::make()` argument; both are
  analysis-rule changes, not framework defects, and the root lock is not committed, so a floating `^3.9`
  would have turned CI red on the day the tool released. Adopting 3.12's rules (typing `query()` as
  `Builder<TModel>` through the specification and entity-graph seams, and `ModelAndView::$view` as a
  `view-string`) is a follow-up of its own.

- **`packages/validation` — a field error's `message` is the constraint's sentence, not Laravel's humanised
  attribute.** `{"field":"shipTo.street","message":"The ship to.street field is required."}` is now
  `{"field":"shipTo.street","message":"must not be blank","constraint":"NotBlank","rejectedValue":""}`, the
  way Spring's `FieldError` reads: `must not be blank`, `size must be between 1 and 50`, `must be a
  well-formed email address`, `must match "^[A-Z0-9]…"`, `must be greater than 0` (the full table is in
  `docs/modules/validation.md`). One violation is reported per constraint. The `validate($data, $rules)`
  primitive is unchanged. **Migration:** a client or test that asserts on the old sentences sets
  `firefly.validation.messages: laravel` (`FIREFLY_VALIDATION_MESSAGES=laravel`) and keeps them — the field
  path and the new `constraint` member are the same in both styles.

### Fixed

- **`packages/openapi` — a success response documents what the action actually returns.** The generator read a
  declared return type only when it was a single named class it could reflect, and published that class's
  PUBLIC PROPERTIES whatever it was — so the most useful part of the document was wrong exactly where real
  applications live. An Eloquent model (what every repository returns) was documented as `incrementing`,
  `exists`, `timestamps`, `wasRecentlyCreated`… all required and not one column; a `JsonResponse` as `original`,
  `exception` and a `ResponseHeaderBag`; a Laravel paginator as `onEachSide`; `@return Page<Order>` as one bare
  `Page` whose `items` were anything; `Parcel|Label` and `int|string` as any value, and `?Parcel` without its
  null. The document now follows `ResponseFactory` and `JsonMessageConverter` in their own order: a returned
  Response is documented by its class (JSON, a binary download, a `302` with `Location`, or `*/*`), markup as
  `text/html`, an `Arrayable` from `toArray()` — an Eloquent model from its `@property` tags or, untagged, from
  its key, `$fillable`, casts, timestamps, `$appends` and relations, minus `$hidden` — before a
  `JsonSerializable`, and Laravel's three paginators as the envelopes they write. Generic instantiations are
  bound to the class's `@template` parameters and become components named springdoc's way (`PageOrder`,
  `LengthAwarePaginatorOrder`), `@extends` included; union, nullable and intersection types are documented on
  returns, response members and request members alike (a union-typed request member is also `required` again).
  A Laravel API resource is its `toArray()` shape inside the envelope its `$wrap` names, and a resource
  collection the list of what it collects (`#[Collects]`, `$collects` or the naming convention).
  An `#[ApiResponse]` without a `type` no longer erases the body: on the success status it keeps the derived
  schema and only replaces the description, and on an error status it documents the problem+json body the
  server sends. `X|null` is spelled exactly as `?X`, and a nullable enum lists `null` among its values. The
  built-in viewer (`viewer.style: builtin`) draws all of it: components by name, unions and nullable nested
  objects arm by arm, maps and lists of components — where it used to show `any`, `object[]` or nothing.

- **`packages/web` — a returned paginator was rendered as pagination links instead of written as data.**
  `ResponseFactory` checked `Htmlable` before handing a value to the JSON converter, and `AbstractPaginator` is
  `Htmlable`, so a `#[RestController]` returning `->paginate()` answered with link markup (or a `TypeError` with
  no view factory bound). A value that is also `Arrayable` or `JsonSerializable` now reaches the converter
  first, as Laravel's own `Response::shouldBeJson()` decides; a View, Renderable or Htmlable that is neither
  still renders as `text/html`.

- **`packages/observability` — a traced request handled inside a fiber is no longer a 500.** The OpenTelemetry
  API keeps one context stack per fiber and raises `E_USER_WARNING` (`must attach initial fiber context
  manually`) when a fiber reads its context before anything was attached in it; Laravel's handler turned that
  into an `ErrorException` on the SERVER span's first read, so the first traced request under any fiber-based
  server — the browser suite's in-process AMP server, an Amp or ReactPHP application server — failed.
  `OpenTelemetryTracer` now attaches the root context to the current fiber once, before its first read there,
  and only when the fiber has no context yet (an application's own scope, or the FFI fiber observer, is nested
  under rather than shadowed); it memoises only the fibers whose floor it laid itself, so a fiber whose foreign
  scope is later detached gets its floor on the next read instead of the warning. Pinned by four unit cases
  inside a real `Fiber` under a warnings-are-fatal handler and by a capstone that handles `/demo/{id}` inside a
  fiber through the real kernel. Found by the browser suite's observability scenarios.

- **`packages/admin` — a write's outcome sentence reaches the page again.** `AdminAction::redirect()` resolved
  the `session` binding — the `SessionManager`, never a `Store` — so its "is the session started" guard was
  false on every request and `Created.`, `Updated N field(s).` and every refusal were dropped: a refused create
  looked exactly like a page reload. It now resolves the request's `session.store` and hands it to the redirect
  before flashing. Found by the browser suite, which watched a create come back to an empty form in silence.

- **`packages/admin` — a blank in a column the person did not have to fill is left to the schema on create.**
  The form marked every NOT NULL column `required` and a blank in one was refused as "not a valid float", so
  the skeleton's own order — `total decimal NOT NULL DEFAULT 0` — could not be created from the dashboard
  without typing the total the domain computes; and a blank in a nullable timestamp was written as an explicit
  null, which is "dirty" to Eloquent and silenced its own `created_at`/`updated_at`. `DataColumn` now carries
  `hasDefault` (read from the table) and `isRequired()` (NOT NULL and no default); the new-record form marks
  the rest `optional`, and `DataBrowser::create()` omits a blank in any non-required column from the insert so
  the `DEFAULT`, the `NULL` or the model's clock fills it. A blank in a genuinely required column is still
  refused; an update treats a blank as an edit, as before.

- **`packages/actuator` — `/actuator/env`, `/actuator/configprops` and the admin's environment page mask a
  `headers` or `authorization` key.** `SensitiveValueMasker`'s rule grows from
  `password|secret|token|key|credential|passwd` to include `authorization` and the plural `headers`: the
  framework had just introduced `firefly.observability.tracing.otlp.headers`, documented as the place for a
  vendor's auth header, and rendered it in clear because `headers` matched none of the six words. The bag's
  key decides, not its leaves (a map's `x-honeycomb-team` matches nothing on its own). The accepted cost is
  `firefly.security.headers` — the response-header filter's `enabled`/`hsts`/`csp` block — showing as
  `******`, and a `headers` column counting as sensitive in the data browser; the singular `header`
  (`page_header`, `header_image`) is deliberately not matched.

- **`packages/admin` — the HTTP traffic page rendered an empty path and `—` for every row.** `AdminAction`
  read `path` and a numeric `timestamp` off the `/actuator/httpexchanges` row, which carries `uri` and an
  ISO-8601 timestamp. Found while adding the trace column; covered by `AdminHttpTrafficTest` over the real
  observability stack.

- **`packages/data` — a `#[Transactional]` proxy now carries the state its bean inherits.** `ProxyFactory`
  copied the bean's state through one closure bound to the declared class, which cannot see a `private`
  declared on a parent and, on PHP 8.3, cannot initialise a parent's `protected readonly` either — so a
  `#[Repository]` that was also `#[Transactional]` lost `EloquentRepository`'s translator (every driver failure
  surfaced as a bare `Error: … must not be accessed before initialization` with the `QueryException` gone) and
  on the 8.3 floor could not be wrapped at all. Every slot is now written from the class that declares it;
  the Known-latent note in the transactions module is retired. `TransactionTemplate` also no longer drops the
  exception a `noRollbackFor` rule kept when the commit-and-rethrow's commit itself fails: both escape as
  `Firefly\Data\Transaction\Exception\TransactionSystemException` (`TRANSACTION_SYSTEM_ERROR`), the commit
  failure as `previous` and the method's exception as `$applicationException`, after the open transaction is
  rolled back.

## [26.09.2] - 2026-09-09

A correctness release found by building a real application on `26.09.1`. Five defects, every one of them
reproduced by a failing test first, and every one of them a case where the framework's behaviour contradicted
what its own documentation and error messages said it did. Two are BREAKING in the sense that an application
can observe the change; both changes are the behaviour that was always intended.

### BREAKING

- **`packages/cqrs` — `CommandProcessingException` / `QueryProcessingException` now carry the CAUSE's error
  code.** Both wrappers copied a `FireflyException` cause's `httpStatus`, `category` and `severity` — and
  then overwrote its `errorCode` with their own `COMMAND_PROCESSING_ERROR` / `QUERY_PROCESSING_ERROR`. Three
  quarters of a fault's identity survived the bus and the quarter a client actually branches on did not: a
  duplicate came back as `409 COMMAND_PROCESSING_ERROR`, a missing row as `404 COMMAND_PROCESSING_ERROR`, an
  authorization denial as `403 COMMAND_PROCESSING_ERROR`. Applications worked around it by catching the
  wrapper and re-throwing `getPrevious()` in every controller that dispatched a command. The cause's code is
  now copied alongside the other three. **Migration:** if you assert on `COMMAND_PROCESSING_ERROR` for a
  fault that has its own code, assert on that code instead — it is the one the cause always declared. A
  cause that is not a `FireflyException` still yields the generic code, so genuine internal failures do not
  start leaking codes. The Lumen capstone's own security assertion moved from `COMMAND_PROCESSING_ERROR` to
  `ACCESS_DENIED` in this release for exactly this reason.

- **`packages/container` — a component's class key is no longer rebound when the application has already
  bound it.** The scan runs in `boot()`, after every provider's `register()`, and `ContainerRegistrar` bound
  each `#[Component]` class to an autowiring closure unconditionally — so an application that had
  deliberately bound a component, which is the normal way to hand one a value the container cannot autowire
  (a string from config, a client built from credentials), silently lost that binding. The loss surfaced
  nowhere near its cause: boot succeeded, and the first consumer died with `Unresolvable dependency
  resolving [Parameter #0 [ <required> string $x ]]`, which reads like a defect in the component. Explicit
  bindings now win, which is the precedence rule the bean sweep already applied where a `#[Bean]` name and a
  component name collide. **Migration:** none for the common case. If you relied on the scan replacing a
  binding you made yourself, remove the binding.

### Fixed

- **`packages/security` + `packages/context` — `php artisan firefly:cache` can now run on an application
  that has no manifests yet.** With `firefly.security.method.strict` enabled and no compiled
  `security-methods.php`, `SecurityWiringProvider` refused to boot — including for `firefly:cache`, the only
  command that writes that file. Its own error message said "Run `php artisan firefly:cache`", and that
  command hit the same error: a fresh clone, a cleared cache directory and the first layer of an image build
  were all unrecoverable without turning strict mode off by hand. `AppScan::regenerating()` now reports when
  `firefly:cache` is the running command, and the strict gate stands down for that boot in favour of the
  in-process scan — the same code path that produces the manifest it is about to write.

- **`packages/context` — a stale compiled manifest no longer bricks the command that would replace it.**
  Every artefact under `bootstrap/cache/firefly` is treated as absent while `firefly:cache` is running, so a
  `component.php` naming a class that has since stopped being autowirable is ignored rather than eagerly
  resolved by `EagerSingletonsPass` before the writer is reached. `EagerSingletonsPass` already tolerated an
  entry whose class no longer *exists*; this closes the neighbouring case, where the class exists and the
  manifest is simply out of date. The recovery for both is now `firefly:cache` rather than
  `rm -rf bootstrap/cache/firefly`.

- **`skeleton` — `composer create-project firefly/skeleton` ships its test scaffold again.** The skeleton's
  `.gitattributes` carried `/tests export-ignore`, which is right for a library and wrong for a project
  template: Composer honours it when exporting the package into the new project, so every scaffolded
  application arrived with a `phpunit.xml` pointing at `tests`, an `autoload-dev` mapping `Tests\` to
  `tests/`, and no `tests/` directory at all. The first `vendor/bin/phpunit` fatalled with
  `Trait "Tests\CreatesApplication" not found` before running a single assertion. Nothing in the template is
  export-ignored now, and `tests/SkeletonScaffoldTest.php` asserts that every `autoload-dev` path the
  skeleton declares is a directory it actually ships.

## [26.09.1] - 2026-09-03

A correctness release that also grew two surfaces. Several headline features were found not to work at all
outside the compiled boot, and two of the failures were **fail-open** in the security sense — the application
kept serving, unguarded, with nothing logged; every fix below was reproduced by a failing test first. Alongside
them, LaraFly gained the two things a framework this shape is expected to have and did not: a browser dashboard
over the actuator (`firefly/admin`) and an OpenAPI 3.1 document generated from the manifests it already holds
(`firefly/openapi`). Both now ship with the `firefly/firefly` metapackage — they were outside it, which meant
a `composer create-project firefly/skeleton` resolved 260 packages and neither of them was among them — and
neither needs npm or a CDN.

### BREAKING

- **`packages/container` — two non-`#[Primary]` `#[Bean]` methods returning the same type now THROW at
  registration.** They previously booted, and one of the two beans silently did not exist: a bean name was
  only ever recorded as `alias($returns, $name)`, and an alias is a pointer to a key rather than a binding of
  its own, so both names pointed at the single type key, that key held whichever factory registered last, and
  `getByName('memoryCache')` and `getByName('redisCache')` handed back the identical object. `#[Primary]` could
  not break the tie because `BeanDescriptor::$primary` was read nowhere in the bean path. **Migration:** give
  each competing `#[Bean]` method a distinct name and mark exactly one `#[Primary]` — the type key then
  aliases the primary and every candidate stays individually resolvable. Rejected at registration (where the
  stack trace still points at the manifest): competing beans that are anonymous, that share a name, that are
  named after the contested type itself, or that declare more than one `#[Primary]`. A contested type with no
  `#[Primary]` stays *bound* — to a guard that throws naming every candidate — so `#[ConditionalOnMissingBean]`
  still sees that a bean of that type exists. See [Dependency Injection](docs/modules/dependency-injection.md).

### Added
- **`firefly/openapi` — an OpenAPI 3.1 document that cannot drift from the server.** Generated from the
  artifacts the framework already holds in memory: `RouteManifest` for paths, verbs, declared statuses, route
  names and the per-parameter binding plan; `ConstraintManifest` for request-body schemas and their `required`
  lists; `firefly/kernel`'s `ErrorResponse` for the RFC 9457 problem component. There is no annotation dialect
  and no second description of the API, so there is nothing to keep in sync. `#[NotBlank]`, `#[Size]`,
  `#[Min]`/`#[Max]`, `#[Email]`, `#[Pattern]`, `#[Percentage]`, `#[Money]` and the rest become JSON Schema
  keywords; anything JSON Schema cannot state (`after:now`, a Luhn checksum, a PCRE flag ECMA-262 has no syntax
  for) is recorded under the `x-firefly-constraints` specification extension rather than dropped silently.
  Nested `#[Valid]` DTOs get their own component, so a self-referential DTO terminates as a `$ref` cycle. Paths,
  verbs and components are sorted, so a regenerated document diffs cleanly and stays worth committing.
  `php artisan firefly:openapi` writes it to `--output=` (with a summary line) or **raw** to stdout via
  Symfony's `OUTPUT_RAW`, so `firefly:openapi | <client-generator>` gets exactly the document's bytes. Three
  routes — spec, console, console assets — are mounted natively from a `BootPass` at configurable paths, which
  an attribute route could not be, and which also keeps the package from documenting itself. See
  [OpenAPI](docs/modules/openapi.md).
- **`firefly.openapi.viewer.style` — `swagger` (default) | `builtin` | `cdn`.** The default console is the
  **official Swagger UI, served from the application's own origin** out of the `swagger-api/swagger-ui` composer
  package (a hard dependency, so the files are already on disk): byte-for-byte the distribution Swagger
  publishes — full feature set, deep linking, try-it-out, OAuth2 — with **no CDN request and no npm step**, so
  it still renders in the air-gapped and strict-CSP deployments where an internal API console is most wanted.
  Asset serving is a whitelist of seven basenames, each `realpath()`-checked inside the dist directory, behind a
  route whose `{file}` segment cannot express a traversal; the files are immutable for a pinned version and are
  sent with a one-year `immutable` cache header and an auto ETag. `builtin` is the hand-written, dependency-free
  reference (no third-party JavaScript at all) and is also the automatic fallback when the dist is absent, so a
  missing package never renders a console whose assets 404. `cdn` fetches Swagger UI from `cdn.jsdelivr.net` and
  is the only style that makes a third-party request at page view. The older boolean `firefly.openapi.viewer.cdn`
  (default `false`) still forces the CDN page and wins over `style`, so an application that set it keeps the
  behaviour it configured.
- **`firefly/admin` — a browser dashboard over the actuator**, the Spring Boot Admin analogue, mounted at
  `firefly.admin.base-path` (default `/firefly`). Thirteen pages in three operator-shaped groups: overview,
  health, metrics and HTTP traffic; beans, **bean graph**, conditions, routes and scheduled tasks; environment,
  config properties, caches and loggers. It reads each `ActuatorEndpoint` **in-process** from `ActuatorRegistry`,
  deliberately bypassing `ExposureModel` — so it renders pages the JSON surface keeps unexposed while that
  surface stays secure-by-default — and honours the per-endpoint kill switch
  (`firefly.management.endpoint.{id}.enabled`), because that key means "off", not "unpublished". A page whose
  endpoint is unregistered or switched off is hidden from the menu rather than linked; a throwing endpoint
  degrades its own panel; health details are read from `HealthContributorRegistry` directly rather than through
  the endpoint's `show-details` disclosure policy. Plain Blade with inline CSS — no npm step, no CDN — and it
  mounts nothing at all when no view factory is bound. See [Admin Dashboard](docs/modules/admin.md).
  - **SECURITY — `firefly.admin.enabled` defaults to the value of `app.debug`.** Because the dashboard bypasses
    exposure, its own URL is the entire boundary in front of `beans`, `env` and `conditions`. An app already
    serving stack traces is a development environment by definition; an app with debug off must opt in
    explicitly, and an explicit value wins in both directions. The dashboard ships **no authentication of its
    own** and has no code edge to `firefly/security`: an application that enables it outside debug **must put
    the route behind its own auth middleware** (`firefly.security.http.rules` covers `firefly` and `firefly/*`
    with no code change).
- **The bean graph (`/firefly/graph`)** — a drawn, layered dependency diagram, not another table.
  `ComponentScanner` now records each component's constructor class/interface types at **scan** time
  (`ComponentDescriptor::$dependencies`, declared last with a default so an older compiled manifest still
  rehydrates), and `BeansCatalog` publishes them, so answering "what depends on what" costs no request-time
  reflection. `BeanGraph` resolves every dependency through an interface index first — a constructor asks for
  `EventPublisher`, the bean that satisfies it is `PostgresEventPublisher` — and marks the edge `via` so the
  indirection is visible rather than silently substituted; layering is a longest-path assignment so arrows read
  downward; a cycle terminates the walk and is **reported** rather than hanging the page, which turns "the app
  died at boot with no message" into a named pair of classes. Past 220 nodes the diagram is suppressed in favour
  of the filterable relations table, and constructor types satisfied by a Laravel binding rather than a bean are
  listed as "provided outside the container" rather than dropped. See [Bean Graph](docs/modules/bean-graph.md).
- **`Firefly\Context\Scan\AppScan`** — the seam every capability package uses to resolve its own manifest:
  compiled artifact, else an in-process scan of `firefly.scan.paths`, else empty. Routes, `#[ControllerAdvice]`
  handlers, CQRS handlers, event/message listeners, scheduled tasks, validation constraints, method-security
  rules, `#[ConfigProperties]` DTOs and the `#[Transactional]` manifest all resolve through it, so an uncached
  application behaves exactly like a cached one. `firefly/cli` joins the `firefly/firefly` metapackage.
- **`firefly.security.method.strict`** (default `false`) — refuses to boot when no compiled method-security
  manifest exists, instead of falling back to the scan. The only defence against a build that ships without
  the compile step.
- **`firefly.observability.metrics.store` / `.ttl`** — names a cache store, swapping `SimpleMeterRegistry` for
  the new `CacheMeterRegistry` so counters and timers survive the request that recorded them. `increment()`
  and `record()` use the store's atomic increment (durations accumulate as integer microseconds, because
  `increment()` is integer-only and a float read-modify-write drops samples); `setGauge()` is last-writer-wins;
  `meters()` rehydrates from one index rather than a key scan. Opt-in: on the `array` driver it would be no
  better than memory.
- **`#[Controller]`** — the HTML stereotype (Spring's `@Controller` to `#[RestController]`'s
  `@RestController`), extending `#[RestController]` so `RouteScanner`'s `IS_INSTANCEOF` filter finds it
  unchanged. `ResponseFactory` now renders `View`/`Renderable`/`Htmlable` and the new `ModelAndView` as
  `text/html`; arrays and scalars still negotiate to JSON. A bare `string` is deliberately **not** a view name.
- **`#[ControllerAdvice]`/`#[ExceptionHandler]` are wired for the first time** — `RouteScanner`'s
  `scanExceptionHandlers()` always existed, but nothing compiled the result, so `ExceptionHandlerRegistry` was
  empty in every real boot while the docs taught it as working. `firefly:cache` now emits
  `exception-handlers.php`, and compiles 13 manifests in total.
- **`packages/config`** — relaxed binding (exact → `snake_case` → `kebab-case` → `SCREAMING_SNAKE_CASE`,
  acronym-aware) and `#[Profile]` gating for `#[ConfigProperties]` DTOs.
- **`packages/resilience`** — `circuit-breaker.minimum-number-of-calls` and `.half-open-probe-timeout`,
  `bulkhead.permit-ttl`, and `firefly.resilience.store.lock-block-timeout` (default `0.5`s) for the mutex wait
  budget.
- **`skeleton/config/firefly.php` is now a full configuration reference** — every `firefly.*` key the framework
  reads, grouped by capability, with its real default and what it does; advanced keys stay commented out at
  their defaults. This release adds the `firefly.openapi.*` block (including `viewer.style` and the legacy
  `viewer.cdn`), the `firefly.observability.httpexchanges.*` block (`enabled`, `capacity`, `store`, `ttl`,
  `include-headers`, `exclude`) and `firefly.management.info.runtime.enabled`, and the file was re-derived
  mechanically against the keys the source actually reads, in both directions. `skeleton/.env.example` carries the ones that usually vary per environment. The skeleton
  also gains a `#[Controller]` welcome page (nothing on it hard-coded — real bean/condition counts, the real
  route table, the real actuator registry) and its first test suite.

- **The OpenAPI document now says what an endpoint RETURNS.** Every success response was `{"type": "object"}`
  — an object with no members, which a viewer renders as a blank panel and `openapi-generator` turns into
  `any`. The shape was never unavailable: it is written in the `@return` one line above the method, where
  PHPStan at level max already checks it against the code on every build, which is what makes reading it safe.
  `DocType` compiles a PHPDoc type expression into a JSON Schema fragment — array shapes with optional keys,
  `list<T>`, `array<K, V>` told apart as array-vs-object, tuples, literal unions, nullable references and the
  PHPStan pseudo-types (`non-empty-string` → `minLength`, `positive-int` → `minimum`) — and returns *nothing*
  rather than guessing when it cannot read one. `ResponseSchemaFactory` builds a returned class from its WIRE
  shape: `jsonSerialize()`'s declared `@return` when there is one, public properties otherwise, because those
  differ — the skeleton's `Order` publishes a derived `total` that is a method, so reflection alone documented
  five of the six members the API sends. `#[ApiResponse(type:)]` takes a full expression (`'list<Shipment>'`),
  resolved through the controller's own imports. Verified by validating live responses member-by-member
  against the schema the generator wrote for them. See [OpenAPI](docs/modules/openapi.md).
- **An HTML error page, in the framework's own design.** Any `FireflyException` rendered as `problem+json`
  regardless of who asked, so a person clicking a stale link in a browser was shown a raw JSON blob; a URL
  matching no route missed that branch entirely and fell through to Laravel's stock page, so one application
  produced two unrelated-looking 404s. The page shows the status, the reason, the stable `code` the problem
  document carries, and — when permitted — the exception, its `previous` chain, the source around the throwing
  line and a stack trace with *your* frames separated from your dependencies'. `firefly.web.error-page.trace`
  follows `app.debug` and is enforced where the data is GATHERED: with it off nothing walks the stack, opens a
  source file or copies the message, so a template mistake cannot leak what was never collected.
  `firefly.web.error-page.views` hands a status to your own Blade view, bound by the same gate, falling back to
  the built-in page if it throws. `json-paths` (default `api/*`) forces `problem+json` on your API space
  whatever the caller's Accept header says. The page itself is built as a string with no container lookups and
  no view factory, because the failure being explained may *be* the view layer. See
  [Error Handling](docs/modules/error-handling.md).
- **The dashboard gained a datasource page, an entity map, and a feature-switch console.** `/firefly/datasource`
  answers what a config dump cannot: which database (secrets masked), whether it is *up* (one connection probed
  per load, because a page that opened every configured connection would take the slowest one's timeout to
  render), what connection reuse actually means in PHP (`ATTR_PERSISTENT`, reported for what it is rather than
  dressed up as a pool gauge), and what `#[Transactional]` compiled to. `/firefly/data-map` draws the entities
  and the foreign keys between them. `/firefly/settings` is the only page that CHANGES the application, and has
  three gates — off by default, writable by a second key, and refused outright in production by a check that is
  deliberately **not** a configuration key. An optional connection wizard tests an unconfigured connection and
  hands back a config block; it writes nothing, never inlines a password, is POST-only, and is unavailable in
  production for the same reason. See [Admin Dashboard](docs/modules/admin.md).
- **The data browser gained filtering, real pagination, create, and relations you can walk.** Eight
  comparisons over the columns a resource publishes, always bound — including the `LIKE` ones, where the
  wildcards go around an escaped value — with an unknown column or operator DROPPED before reaching the driver,
  so a hand-edited URL cannot probe for column names. Conditions AND with each other and with the search box.
  Relations are discovered by calling only the methods whose *declared return type* is an Eloquent `Relation`,
  so a record links to what it references in both directions. `create()` is now offered for an Eloquent-backed
  resource under the same two switches — the constructor-invariants argument that kept it out was right for a
  hand-written aggregate and was never true for a model Eloquent builds empty and fills by attribute, which is
  exactly what `update()` had always done. A `float` column type joins the vocabulary: every non-integer number
  used to be typed `string`, so a money column read as a string, was offered to a `LIKE` search, and let the
  editor save `"abc"` into it. See [Data Browser](docs/modules/data-browser.md).

### Changed
- **`#[Qualifier]` on a parameter is honoured.** It declared `TARGET_PARAMETER` from day one and nothing read
  it, so `#[Qualifier('redisCache')] Cache $cache` silently received whatever `Cache::class` resolved to. It
  now rides `ContextualAttribute` — the seam `#[Value]` already used — adding no reflection that was not
  already happening and leaving the compiled manifest shape untouched.
- **`#[Bean]` discovery no longer compares stereotype short names.** The gate was `$shortAttr ===
  'configuration'`, the one place in the scanner that abandoned `IS_INSTANCEOF`, so `#[Bean]` methods on a
  user-defined stereotype extending `#[Configuration]` — or on a plain `#[Component]`, Spring's "lite mode" —
  vanished from the manifest while the class itself was still bound.
- **`make:firefly-*` output.** `-handler` writes two files (the handler *and* the concrete command/query class
  its `handle()` takes); `-listener` puts `#[Component]` on the generated class; `-repository` generates a
  concrete `#[Repository]` extending `EloquentRepository` instead of an unresolvable interface.
- **`firefly.management.endpoints.web.exposure.exclude` honours `*`**, matching `include` and Spring — the
  documented kill switch used to expose everything `include` named. An endpoint body renders as `{}` rather
  than `[]` when empty.
- The skeleton drops `app/Support/CachedTransactionalConfiguration.php`, the hand-written workaround every
  application needed while `DataAutoConfiguration` bound an empty `TransactionalManifest`.
- **Docs, book and README cover the two new packages.** New module guides
  [OpenAPI](docs/modules/openapi.md), [Admin Dashboard](docs/modules/admin.md) and
  [Bean Graph](docs/modules/bean-graph.md), wired into `docs/README.md` and `docs/index.md`; the actuator guide
  gains `/actuator/httpexchanges` + `/actuator/process` and a pointer to the dashboard's access model; the CLI
  reference gains a table of commands contributed by other packages (`firefly:openapi`, `firefly:eda:consume`,
  `firefly:outbox:relay`). *LaraFly by Example* is updated in **both** languages: Chapter 11 gains the
  thirteen-page dashboard table and a full bean-graph section (interface resolution, longest-path layering,
  cycle reporting, the 220-node ceiling), and Chapter 4A's "CDN flag" section is replaced by the three viewer
  styles, the whitelisted asset route and the honest cost of `cdn`. Every fenced PHP listing still passes
  `php -l` (219 per language). Package counts corrected from 25/26 to **27 packages / 28 shippable units** in
  the README and the publishing runbook.
- Docs corrected against source throughout: the CLI's cached-vs-uncached boot, the resilience circuit-breaker
  and bulkhead tables and their state prose, configuration's relaxed binding and profile gating, the web
  layer's HTML rendering, security's fail-open note and full config table, observability's cross-process
  registry, and the "compilation lands in M15 — until then bind the manifest yourself" caveat that five module
  guides still carried.
- **Documentation for the rebuilt bean graph, the data browser and the OpenAPI schema pipeline.** A new
  [Data Browser](docs/modules/data-browser.md) guide covers `firefly/admin`'s Django-admin-style view over the
  data layer: what it discovers (every bean whose scan-time interface list contains `CrudRepository`, read from
  the compiled `BeansCatalog` rather than a fresh scan, so it can never offer a resource the container never
  registered), why `firefly.admin.data.enabled` defaults to **`false`** and deliberately does *not* follow
  `app.debug` or `firefly.admin.enabled` (beans and config are facts about the application; these are facts
  about its **users**), why writes need `firefly.admin.data.writable` **on top of that** (visibility and custody
  are different decisions), and why **there is no `create()`** and never will be — an aggregate's invariants live
  in its constructor, and a form built from a column list can only satisfy them by writing columns the domain
  model considers impossible. Also documented: the four listing paths and the honest cost of the unpaged one,
  search bound-never-interpolated, columns derived from the resource rather than from a row, the closed
  five-value display-type vocabulary and why `decimal` maps to `string`, the identifier/secret write refusals
  enforced twice, and why no rendered error text is ever an exception message.
  [Bean Graph](docs/modules/bean-graph.md) is rewritten for the three node kinds — components, `#[Bean]`
  **products** and `#[ConfigProperties]` DTOs — plus the `injects`/`produces` edge distinction, the identity
  rule for a contested `#[Bean]` type, and why cycles are reported rather than fatal; the stale "`#[Bean]`
  factory-method parameters are not drawn" limitation is gone, because they are.
  [OpenAPI](docs/modules/openapi.md) gains a full "How a request DTO becomes a schema" section: the three
  sources and why the compiled manifest beats the `#[Constraint]` attributes, why no `additionalProperties:
  false` is emitted, the complete **attribute → compiled rule → JSON Schema keyword** table mapped from
  `ConstraintSchemaMapper` (correcting `#[Negative]`, which produces `exclusiveMaximum`, not
  `exclusiveMinimum`), first-writer-wins, the 3.1 nullable spelling, the one-`pattern`-slot `allOf` fallback,
  `list<X>` element types read from the constructor docblock via the same `dtos` table `ArgumentResolver`
  hydrates from, and the narrowed `{}`-vs-`[]` rewrite now that a constructor default genuinely does emit an
  empty list. The `firefly.openapi.*` config table also gains the five optional Info Object keys that were
  shipping undocumented — `summary`, `terms-of-service`, `contact.*` and `license.*`, with the rule that
  `license.name` gates the whole object and `license.identifier` wins over `license.url`, since 3.1 makes the
  two mutually exclusive. *LaraFly by Example* is extended in **both** languages: Chapter 11 gains a "three
  kinds of node" section for the graph and a data-browser section placed deliberately beside the access-model
  argument it contradicts. Every fenced PHP listing still passes `php -l` (220 per language).

### Fixed
- **`packages/security` — method security failed OPEN.** Both enforcement sites treat "no rule for this
  method" as ALLOW, so the unconditional empty `SecurityMethodManifest` silently disabled every
  `#[PreAuthorize]`, `#[Secured]` and `#[RolesAllowed]` in the application. Only `firefly/cli` — then a
  `require-dev` package absent from the metapackage — ever bound the compiled rules.
- **`packages/security` — the expression evaluator failed OPEN.** `SecurityExpressionEvaluator` is a singleton
  whose parse state lives on the instance, and `hasPermission()` calls application code (a user-supplied
  `PermissionEvaluator`) that may evaluate an expression of its own on that same singleton. The inner call
  overwrote the outer parse state, so `hasPermission(#id, 'read') and hasRole('ADMIN')` returned **true** for a
  principal holding no authorities at all. State is now saved and restored in a `finally`.
- **Boot — the framework only worked in its compiled state.** `firefly:clear` on a freshly created skeleton
  made the app 404 every route it owned, and no quality gate could see it. Fixed by `AppScan` above.
- **`packages/data` — `#[Transactional]` was a silent no-op.** Nothing ever loaded the compiled
  `transactional.php`, so `hasProxyFor()` was always false. `ProxyMaterializer` now makes proxies loadable on
  both paths (classmap when compiled, generated per-process when not) *before* the manifest is handed out.
- **`packages/eda-postgres` — with `provider=postgres` no `#[EventListener]` was ever subscribed and outbox
  rows were ACKed without being delivered**: silent data loss in the headline feature. `firefly:outbox:relay`
  could not work either, because `downstream_provider` selected no publisher; it now resolves a shipped alias,
  an `EventPublisher` class-string or a bound container id, validates at command time (not boot), refuses a
  `PostgresEventPublisher` downstream, and fails loudly instead of exiting successfully when unconfigured.
- **`packages/eda` — `#[EventListener(order:)]` was discarded at dispatch.** It round-tripped through the
  manifest and the wiring pass then iterated `all()`; it now iterates `ordered()`.
- **`packages/resilience` — the CircuitBreaker wedged permanently in HALF_OPEN** when a probe threw a
  non-recorded exception or its worker died, rejecting 100% of traffic to a healthy dependency until an
  operator flushed the cache. Probe permits are now expiring leases, an ignored exception explicitly returns
  its permit, and bulkhead permits (which leaked the same way, and could be driven negative by an unmatched
  `release()`) are leases too. `state()` reported a stale `open` for a breaker whose wait window had elapsed,
  so the actuator gauge called a recovering breaker hard-down; it now reports the state `admit()` would decide.
  The store's mutex WAIT budget is separated from its HOLD TTL, so a `timeout: 0` rate limiter no longer blocks
  five seconds and then surfaces an unmapped `LockTimeoutException` as a bare HTTP 500 — it raises a 503
  `RESILIENCE_STORE_LOCK_TIMEOUT`.
- **`packages/validation`** — `#[Size]` silently flipped from length to numeric semantics beside any constraint
  emitting `numeric`; a present-but-null value failed every constraint instead of only `@NotNull` (Jakarta
  semantics); `#[Rules]` lost a custom `ValidationRule`'s constructor arguments on the compiled path, booting
  `new StartsWith()` where the developer wrote `new StartsWith('ACME')`. Rules now declare their arguments via
  `Compilable`, or have them recovered from promoted properties at COMPILE time, or are rejected then with an
  actionable message — never silently stripped at runtime.
- **`packages/config`** — `ProfileResolver` read raw `getenv()`, which returns `false` under both testbench and
  `config:cache`, so profiles collapsed to `['default']` exactly where they mattered; `#[Profile]` was
  declared, exported and documented with zero production readers.
- **`packages/observability` — `/actuator/metrics` and `/actuator/prometheus` were effectively empty in
  production.** Under PHP-FPM every request is a fresh process, so a scrape saw only what that scrape's own
  request recorded — worse than empty, because it reads as data. See `CacheMeterRegistry` above.
- **`packages/cli`** — `make:firefly-handler` generated code that made the next `firefly:cache` throw and abort
  the whole compile; `make:firefly-repository` generated an interface nothing could resolve;
  `make:firefly-listener` generated a class the scanner could not discover. Stub tests now generate from each
  stub and assert the output is valid PHP *and* discoverable by the relevant scanner.

- **SECURITY — every dashboard write was forgeable from another site.** The admin routes were mounted with no
  middleware at all, which in Laravel means no session and no `ValidateCsrfToken`, so the `@csrf` field in
  every dashboard form was decorative: a tokenless `curl -X POST` against `/firefly/loggers` was accepted and
  changed the log level, and the same held for the data browser's edit and delete and the settings console.
  A form that renders a CSRF field while the route ignores it is worse than one that renders none. Fixed by
  attaching the middleware CLASSES rather than the `web` group name — naming the group and guarding on
  `hasMiddlewareGroup('web')` attached nothing, because the registrar runs before the application defines
  that group. `skeleton/.env.example` moves to `SESSION_DRIVER=file`: an array session is discarded at the end
  of the request, so the token could never match and every POST would answer 419. Found by an adversarial
  review of this branch; Laravel's CSRF middleware skips itself under tests, which is how it survived being
  written, so the regression test asserts the middleware is attached and the behaviour was proven over real
  HTTP.
- **SECURITY — a filter on a masked column was an extraction oracle.** Filtering shipped over every column,
  which quietly re-opened the channel masking exists to close: a masked column renders as `******`, but a
  filter over it answers a yes/no question about the real value, and a yes/no question you can ask repeatedly
  recovers it. Proven against the fixture — twenty-one filtered requests returned `correct horse battery` from
  a column the listing showed only as asterisks, and `>`/`<` do it faster by binary search. Sensitive columns
  are now excluded from filtering exactly as they already were from search, in the model and in the control.
- **Escaping a `LIKE` without an `ESCAPE` clause silently matched nothing.** `contains`/`starts with`
  backslash-escaped the user's `%` and `_` and then emitted a plain `LIKE ?`, which leaves the driver with no
  escape character declared — so the backslash was matched literally and a search for `ada_love` returned zero
  rows against a table holding `ada_lovelace@example.test`. Suppressing the wildcards worked; finding an
  underscore stopped working, which is the worse half. The predicate now emits an explicit `ESCAPE`, and the
  search box — which had no escaping at all, so a bare `%` matched every row — goes through the same helper.
- **`composer create-project firefly/skeleton` shipped neither the dashboard nor the API documentation.**
  `firefly/admin` and `firefly/openapi` were built, tested, documented and offered by `firefly new --with` while
  *nothing* required them. The welcome page checks `class_exists()` before linking, so it did not render a
  broken link — it silently rendered two cards fewer, which is the worse failure because nothing looked wrong.
  Fixed in the BOM rather than the skeleton, because the asymmetry was the actual bug: for eleven of thirteen
  capabilities `--with` promotes an already-installed package to an explicit dependency, and for these two it
  decided whether the code existed at all. `tests/MetapackageCoverageTest.php` holds both ends.
- **The skeleton's sample REST resource did not persist, and its docblock said it did.** `OrderRepository` kept
  orders in an array on a singleton and claimed the state survived between requests. PHP shares nothing between
  requests, so `POST /orders` returned 201 with an id and the very next `GET /orders` reported an empty store —
  the first thing a new user does. The skeleton's own suite passed throughout, because Laravel reuses ONE
  application across the requests of a single test. It is now an `EloquentRepository` over two tables — an
  address is a value and stays an embedded json column, a line is an entity and gets a table, a foreign key and
  a repository — which also earns the sample its first `#[Transactional]`, gives the data browser something to
  browse, and gives the entity map an edge to draw. `migrate` joins `post-create-project-cmd`.
- **`skeleton/config/firefly.php` had drifted from the code it documents.** Three keys the framework reads were
  undocumented, including `firefly.management.server.address` — half of the management-port feature.
  `tests/ConfigReferenceTest.php` now checks all 84 keys read through the Config port and fails the build when
  one is added without a word written about it.
- **`packages/admin` — the data grid's columns did not line up with their headers.** The listing table carried
  `class="grid"`, colliding with the layout's own `.grid{display:grid}` utility, so the table became a grid
  CONTAINER, `thead` and `tbody` computed to `display:block`, and the two row groups sized their columns
  independently. Invisible in the markup and not findable by reading the CSS — it came out of asking the
  browser what `display` the element had ended up with.
- **Tertiary text across the dashboard and the welcome page was below WCAG AA.** `#8d95a1` is 2.8:1 on the
  dashboard's own background, and it painted table cells, every panel's explanatory note, the uppercase stat
  labels and the namespace half of every class name — content, not decoration. Now 4.95:1 and 4.76:1 in light,
  5.6:1 and 5.3:1 in dark. The brand orange was 3.01:1 as a foreground and is no longer used as text: shapes
  and text take different oranges.

## [26.07.18] - 2026-07-28

### Added
- **`samples/lumen`** — a runnable DDD wallet-and-ledger sample exercising the whole stack: a `Wallet` Eloquent aggregate (`HasDomainEvents`/`RecordsDomainEvents`) with a `balance >= 0` invariant, a `Money` value object, four domain events, a hexagonal `WalletRepository` port + Eloquent adapter, CQRS command/query handlers (`#[CommandHandler]`/`#[QueryHandler]`, `#[Transactional]`), a `LedgerProjector` (`#[EventListener]`), a genuine same-transaction outbox, `#[PreAuthorize]` method security, and a REST layer with RFC-7807 problem-details. A `firefly/lumen` path-package (never in the metapackage/split matrix/Deptrac paths). ~40 tests, all dispatching through the real `CommandBus`/`QueryBus`/router.
- **Book — *LaraFly by Example*** — a complete bilingual (EN + ES) book under `book/`: a quick start, 13 chapters across four parts (Foundations, Modelling & Persisting the Domain, Coordinating & Securing the App, Observability/Testing/Delivery), plus a Laravel→LaraFly cheat-sheet and a glossary. A dedicated Python/WeasyPrint pipeline renders PDF + EPUB in both languages; every fenced PHP listing is `php -l`-verified against the sample.
- **Tutorial** — an end-to-end, ~12-step guided tutorial (`docs/tutorial.md`) in English + Spanish (`docs/tutorial.es.md`).
- **Docs table of contents** (`docs/README.md`) grouping every guide by topic; grown `docs/index.md`.

### Changed
- **README** — rewritten to a professional, fully-explained front page (15 sections, banner + five SVG diagrams, nine source-accurate "Featured Patterns" showcases, real links to the tutorial/book/docs TOC), guarded by a relative-link test.

## [26.07.17] - 2026-07-28

### Added
- `firefly/installer` — the global `firefly new <app>` installer (laravel/installer analog): a thin `bin/firefly` Symfony Console binary with a single `NewCommand` wrapping `composer create-project firefly/skeleton`, git-init, and next-steps; `symfony/console`+`symfony/process` only (no firefly runtime deps). New Deptrac `Installer` layer (depends on nothing).
- Publish-readiness packaging: Apache-2.0 `LICENSE` on every package + skeleton; the 8 missing per-package READMEs; full Packagist metadata (`authors`/`keywords`/`homepage`/`support`) + `extra.branch-alias` (`dev-main` → `26.x-dev`) on all 26 units — still NO `version` field.
- Pre-push safety guard: `scripts/check-no-sensitive-tracked.sh` + a CI `guard` job failing on any tracked/staged `.superpowers/`, `docs/superpowers/`, `.claude`, `CLAUDE.md`, `.env`, or secret marker; hardened `.gitignore`.
- Docs overhaul (pyfly parity): top-level `installation.md`, `getting-started.md`, `cli.md`, `laravel-comparison.md`, `versioning.md`, `contributing.md`, `publishing.md`, `modules/installer.md`; five source-accurate SVG diagrams (boot pipeline, DI/auto-config, request lifecycle, same-tx outbox, CQRS/EDA bridge); a brand-clean LaraFly banner.
- Dormant `.github/workflows/release.yml` — on a pushed `v*` tag, subtree-splits all 26 units to `fireflyframework/firefly-<pkg>` read-only mirrors (`symplify/monorepo-split-github-action`).

### Changed
- Promoted `docs/modules/getting-started.md` → `docs/getting-started.md` and `docs/modules/cli.md` → `docs/cli.md` (top-level, pyfly-parity nav).

## [26.07.16] - 2026-07-27

### Added
- `firefly/eda-rabbitmq`, `firefly/eda-postgres`, `firefly/eda-kafka` — real message-broker adapters behind the M9 `EventPublisher` port.
- Genuine same-transaction outbox (`firefly_eda_outbox`) in `firefly/eda-postgres` — the outbox row commits atomically with the aggregate.
- `firefly/eda` consumer-loop SPI (`EventConsumer`/`ConsumerLoop`) + `firefly:eda:consume`; `firefly:outbox:relay`.
- Per-broker `HealthIndicator`s; `@group('integration')` round-trip tests per broker.

### Changed
- `firefly/data`: added an optional `PreCommitEventHook` seam to `Domain\DomainEventDispatcher` (non-frozen) enabling the same-tx outbox; null by default — no behaviour change for existing apps.

## [26.07.15] - 2026-07-27
### Fixed
- **`firefly/context`** — boot-order robustness: a real app (created via `composer create-project
  firefly/skeleton`) now boots WITHOUT an app-side `beforeBootstrapping` kernel-binding hook.
  `Firefly\Context\Boot\FireflyServiceProvider` now buffers each provider's contributed `BootPass`
  instances (new `PendingBootPasses`) at `register()` time and drains them into the kernel lazily,
  from the `booting()`/`booted()` callbacks, once the kernel is actually bound — so Laravel's
  alphabetical package-provider discovery order no longer breaks boot. The skeleton's now-unneeded
  `beforeBootstrapping` hook is removed.
- **`firefly/cli`** — the offline create-project test now cleans up its temp directory via
  try/finally, so a failed assertion no longer leaks it.

## [26.07.14] - 2026-07-27
### Added
- `firefly/cli` — the developer-experience console (new Deptrac `Cli` layer, depends-all/depended-by-none): `firefly:cache` (compile all app manifests via every settled package's scanner→compiler + emit `#[Transactional]` proxy classes into `bootstrap/cache/firefly/` for a zero-reflection boot; a `FireflyCacheServiceProvider` binds the compiled wiring manifests + registers the proxy autoloader at boot), `firefly:clear`, `firefly:about` + actuator-over-CLI `firefly:routes`/`firefly:health`/`firefly:metrics`, the `make:firefly-*` generator family (controller/service/component/handler/listener/entity/repository/config-properties), and thin `firefly:serve`/`firefly:db` passthroughs.
- `firefly/firefly` — a `type: metapackage` runtime aggregator (the Composer BOM analog): `composer require firefly/firefly` pulls the whole runtime family.
- `firefly/skeleton` — a `type: project` Laravel-13 create-project template pre-wired with the Firefly family + a sample `#[RestController]`/`#[Service]` + `firefly:cache` in `post-create-project-cmd`; `composer create-project firefly/skeleton my-app` yields a booting, cached app.
- Docs: `docs/modules/getting-started.md` + `docs/modules/cli.md`; a full foundation-flow e2e capstone (+ a `@group integration` Postgres pass via testcontainers).

## [26.07.13] - 2026-07-27
### Added
- `firefly/testing` — the first-party test-support kit: `FireflyTestCase` + `bootFireflyApp()`/`fireflyApplication()` boot harness, `FireflyDatabaseTestCase`/`UsesSqliteMemory`, web/data slice builders + `#[FireflyTest]`/`#[WebSlice]`/`#[DataSlice]` attribute analogs, recording doubles for Firefly's ports (`RecordingEventPublisher`, `RecordingApplicationEventPublisher`, `RecordingCommandBus`, `StubQueryBus`, `RecordingCqrsMetrics`, `RecordingCommandEventPublisher`, `RecordingMessageBroker`, `RecordingDistributedLock`, `FakeHealthIndicator`, `RecordingTracer`), Firefly Pest expectations (`toHavePublished`/`toHaveHandledCommand`/`toBeUp`/`toHaveRecordedMetric`/`toBeProblemDetails`) + procedural assertions, a fixture layer (`FixtureRegistry`/`AggregateSeeder`/`ListenerSpy`), and a testcontainers hook (`RequiresDocker`/`fireflyConfigFor()`). New Deptrac `Testing` layer (depends on all, depended on by none).

### Changed
- Dogfood: every package's hand-rolled test base + bare-boot test now runs on the `firefly/testing` harness; duplicated doubles (`FakeEventPublisher`, `SpyEventPublisher`, cqrs `RecordingCommandEventPublisher`, eda/messaging `Spy`) deleted in favor of the shipped kit.

## [26.07.12] - 2026-07-26
### Added
- **`firefly/actuator`** — the Spring-Boot-Actuator analogue: a `HealthIndicator` SPI (`Status` UP/DOWN/OUT_OF_SERVICE/
  UNKNOWN, a most-severe `StatusAggregator`, liveness/readiness probe groups, `/health/{group}`, 503-on-DOWN,
  `show-details`) with built-in `Ping`/`DiskSpace`/`Db` indicators discovered by a bean-scan `HealthContributorRegistrar`;
  an `ActuatorEndpoint` contract + `ActuatorRegistry` + a HAL `/actuator` index; a route-registration BootPass mounting
  framework endpoints on the illuminate `Router` under `/actuator`; `/info` (`InfoContributor` port + `App`/`BuildInfo`),
  `/env` (masked), `/beans`, `/conditions`, `/mappings`, `/loggers` (GET/POST), `/scheduledtasks`. Exposure model
  (secure-default `health,info`; unexposed → 404); fail-safe errors via `ProblemDetailsRenderer`. Secured entirely by
  M11 config (recommended `firefly.security.http.rules` lockdown) with zero code edge to `firefly/security`. New Deptrac
  `Actuator` layer (top-of-stack).
- **`firefly/observability`** — the Micrometer/Prometheus analogue: a first-party pure-PHP `MeterRegistry`
  (`SimpleMeterRegistry`, counter/gauge/timer, idempotent tag sets) + a `MetricsRecorder` port (+ NoOp); a pure-PHP
  Prometheus 0.0.4 text exposition (no ext, no OTel) behind `/actuator/prometheus` and a Micrometer-JSON
  `/actuator/metrics`, both implementing the actuator contract and gated on a `MeterRegistry` bean; an HTTP `MetricsFilter`
  auto-instrumenting `http_server_requests_seconds`; the real `MeterRegistryCqrsMetrics` (wins the M10 seam via
  `#[Order(500)]` + `#[ConditionalOnMissingBean]`); a resilience circuit-breaker state gauge + process metrics; a
  correlation-id log processor; and a `Tracer` port (`NoOpTracer`; OpenTelemetry deferred to SP-7). New Deptrac
  `Observability` layer (top-of-stack, gated on a `MeterRegistry` bean).

## [26.07.11] - 2026-07-24
### Added
- **`firefly/security`** — the first-party security core: an immutable `Authentication`/`SecurityContext`/`GrantedAuthority`
  principal model in a `Context`-backed `SecurityContextHolder` (cleared per request); authentication ports with
  `InMemoryUserDetailsService`, constant-time `PasswordEncoder`s (bcrypt/argon2id/delegating), a `ProviderManager`/
  `DaoAuthenticationProvider`, a `JwtService` (mandatory `exp`, weak-secret boot refusal), a local-JWT Bearer filter,
  and an OAuth2 resource-server JWKS filter (`JwksProvider` port, no live network in tests, `iss`/`aud`
  confused-deputy validation); deny-by-default URL authorization (`HttpSecurity` DSL + `HttpSecurityFilter`, 401/403
  via kernel exceptions); method security (`#[PreAuthorize]`/`#[Secured]`/`#[RolesAllowed]`) compiled by a single
  `MethodSecurityScanner` into a `var_export` manifest and enforced at the CQRS bus (real `Command`/`QueryAuthorizer`),
  the controller dispatcher (a new no-op `ControllerSecurityGuard` port in `firefly/web` + a real impl), and
  imperatively (`AuthorizationChecker`) — all via a hand-rolled **no-`eval`** whitelist expression evaluator,
  `RoleHierarchy`, and a deny-all `PermissionEvaluator`; the real `AuditorAware` for the M8 auditing seam; and CSRF
  (double-submit) + security-headers hardening filters. A boot-time mutual-exclusivity guard refuses local-JWT +
  OAuth2 resource-server enabled together. Opt-in, secure-by-default, fail-closed, zero boot reflection. New Deptrac
  `Security` layer (top-of-stack).
### Changed
- **`firefly/web`** — added the `ControllerSecurityGuard` enforcement port (no-op `AllowAllControllerSecurityGuard`
  default) invoked by `ControllerDispatcher` after argument resolution; `firefly/security` binds the real guard.
### Fixed
- **`firefly/security`** — `MethodSecurityScanner` now rejects, at scan (cache) time, any `#[Secured]`/`#[RolesAllowed]`
  role/authority value containing a single quote, closing an expression-injection gap where a crafted value could
  compile into grammar-valid text (e.g. splicing in `or permitAll()`) and silently widen access; mirrors the
  `HttpSecurity::assertSafeValue()` guard already shipped for the URL-rule DSL/config path.

## [26.07.10] - 2026-07-24
### Added
- **`firefly/cqrs`** — the CQRS dispatch layer: a synchronous in-process `CommandBus` (`send`) / `QueryBus` (`ask`)
  mediator with `#[CommandHandler]`/`#[QueryHandler]` class stereotypes (message type inferred from the `handle()`
  parameter) discovered by a single `HandlerScanner` into a `HandlerManifest` and populated into a `HandlerRegistry`
  by a `CqrsHandlerWiringPass`; a bounded pipeline of injectable, no-op-by-default seams (validation over the shipped
  `Validator` via `Validatable`, authorization, correlation, metrics, query cache); category-preserving
  `CommandProcessingException`/`QueryProcessingException`; and the domain→integration-event bridge — a guarded
  wildcard listener re-emitting every committed `DomainEvent` onto the `firefly/eda` `EventPublisher`
  (`CommandEventPublisher` port + `NoOpEventPublisher`/`EdaCommandEventPublisher`, `#[PublishDomainEvent]` routing,
  LOG/RAISE failure strategy). `#[Transactional]` handlers get transaction interception for free from firefly/data.
  Always-on auto-configuration. No `Cqrs → Data` Deptrac edge.

## [26.07.9] - 2026-07-23
### Added
- **`firefly/eda`** — the async event-transport bus: an `EventPublisher` broker-bus port with an in-memory default
  adapter and a Laravel-queue async adapter (`QueueEventBus` enqueues a `DispatchEventJob` carrying the
  `EventEnvelope`; the worker reconstructs the subscriber registry from the compiled manifest and match+invokes), a
  `#[EventListener]` pattern attribute → `EventListenerScanner` → `EventListenerManifest` → `EventListenerWiringPass`,
  a `Serializer` seam (`JsonSerializer`), and a linear-backoff retry / `DeadLetterStore` helper
  (`x-original-topic`/`x-exception`). Always-on auto-configuration.
- **`firefly/messaging`** — the raw-bytes broker layer: a `MessageBrokerPort` (`Message` = topic + bytes + key +
  headers) with in-memory (broadcast + consumer-group round-robin) and Laravel-queue adapters, a broker-agnostic
  `#[MessageListener]` (topic/group/retries/retryDelay/deadLetterTopic) → `MessageListenerScanner` →
  `MessageListenerManifest` → `MessageListenerWiringPass`, and a bytes-aware per-listener retry/DLQ helper.
  Always-on auto-configuration. Independent sibling of `firefly/eda` (no dependency between them).

## [26.07.8] - 2026-07-22
### Added
- **`firefly/domain`** — the DDD base: `Entity` (identity equality), `ValueObject` + `ValueObjectEquality`,
  `AggregateRoot` (pending domain-event buffer: `raiseEvent`/`pendingEvents`/`pullEvents`/`clearEvents`), and a
  reflection-free `DomainEvent` (uuid `eventId` / `occurredAt` / `eventType`).
- **`firefly/data`** — repositories (`CrudRepository`/`PagingAndSortingRepository` ports + `EloquentRepository`),
  extended-Spring derived queries (`DerivedQueryParser` + `__call` dispatch) with a `#[Query]` escape hatch,
  `Specification` composition + `Page`/`Pageable`/`Sort` pagination, and soft-delete/auditing/optimistic-locking
  support. The declarative `#[Transactional]` interception core — `TransactionalScanner`→`TransactionalManifest`,
  a reflection-free `ProxyClassGenerator` + state-preserving `ProxyFactory`, `TransactionInterceptor`/
  `TransactionTemplate` over MANUAL `DB` begin/commit/rollBack (all 7 propagation modes, `rollbackFor`/
  `noRollbackFor`), and a `TransactionalBeanPostProcessor` wired via the M4 seam at phase 700. Auto after-commit
  domain-event dispatch (`AggregateTracker` + `DomainEventDispatcher` via `DB::afterCommit`). Always-on auto-configuration.

## [26.07.7] - 2026-07-17
### Added
- **`firefly/resilience`** — programmatic, cache-backed resilience: `ResilienceRegistry` (config-driven named
  instances) + Retry, CircuitBreaker, RateLimiter, Bulkhead, TimeLimiter, Fallback; `Duration` parser;
  `BulkheadFullException`; always-on auto-configuration.
- **`firefly/scheduling`** — `DistributedLock` port with `NoneLock`/`CacheLock`; `#[Scheduled]` attribute +
  `ScheduledScanner`/`ScheduledManifest`; `ScheduleWiringPass` (deferred, lock-guarded registration onto Laravel's
  scheduler); config-selected lock backend.
- **`firefly/scheduling-postgres`** — `PgAdvisoryLock` (session-scoped Postgres advisory lock), `#[ConditionalOnProperty]`-gated.

## [26.07.6] - 2026-07-17
### Added
- **`firefly/web`** — the HTTP layer: `#[RestController]` (a `#[Component]` stereotype, so controllers get
  constructor DI for free) with `#[RequestMapping]` + `#[GetMapping]`/`#[PostMapping]`/… compiled by a single
  `RouteScanner` into a `RouteManifest`; parameter binding (`#[PathVariable]`/`#[QueryParam]`/`#[RequestBody]`/
  `#[RequestHeader]`/`#[UploadedFile]`) with `#[Valid]` interception; JSON-native content negotiation over a
  `MessageConverter` seam (q-value `Accept` parsing; XML deferred to the seam); RFC-7807 rendering of every
  `FireflyException` as `application/problem+json` with `#[ExceptionHandler]` (local) + `#[ControllerAdvice]`
  (global) resolution; and an `#[Order]`-driven `WebFilter`→Laravel-middleware chain with framework
  `RequestContextFilter`/`CorrelationIdFilter`. A `RouteWiringPass` (phase `WiringPasses`) registers native
  Laravel routes, so dispatch runs inside the HTTP-kernel middleware pipeline (CORS/CSRF/secure-headers reuse).
- **`firefly/validation`** (extended) — the Bean-Validation constraint layer `#[Valid]` triggers: per-property
  constraint attributes (`#[NotBlank]`/`#[Size]`/`#[Email]`/`#[Iban]`/… + the `#[Rules]` escape hatch) mapped
  to Illuminate rules by a single `ConstraintScanner` (nested-cascade + cycle guard), a compiled
  `ConstraintManifest`, and a `BeanValidator` that reuses the M5 `IlluminateValidator`. `#[Valid]`'s target is
  broadened to also allow properties.

### Changed
- `Firefly\Kernel\Version::VERSION` → `26.07.6` (the single frozen-package edit).

## [26.07.5] - 2026-07-16
### Added
- **`firefly/autoconfigure`** — the auto-configuration engine:
  - `AutoConfiguration` — a discovered provider base that records only its candidacy into a container-bound
    `AutoConfigurationCollector` at `register()` time (never touching the kernel), so provider-registration
    order cannot affect wiring.
  - `DefinitionAssembler` — joins M2 `ComponentManifest` + M4 `ContextManifest` into conditioned
    `BeanDefinition`s (closing M4's deferred scanner→definition seam), and `AutoConfigManifestCompiler`, the
    compile façade over the M2/M4 scanners.
  - `AutoConfigDiscoveryPass` (phase 200, discover + assemble, no registry write) and `AutoConfigurationsPass`
    (phase 500, drain into the registry as `DefinitionSource::AutoConfiguration`), consumed by M4's incremental
    `ConditionPassTwoPass` for `#[ConditionalOnMissingBean]` back-off and `(order, FQCN)` first-wins.
  - `FireflyAutoConfigureServiceProvider` — the auto-discovered bootstrap that binds `FireflyKernel` +
    `BootContext` and contributes the full boot pipeline, scanning the app by convention from
    `firefly.scan.paths`. No modification to the frozen `firefly/context`.
- **`firefly/validation`** — a `Validator` port with a `validate(data, rules)` primitive over
  `Illuminate\Validation`, an inert `#[Valid]` marker (interception lands in M6), ~16 financial-domain `Rule`
  objects (IBAN, BIC, Luhn, ISIN, CUSIP, ...), and a `ValidationAutoConfiguration` that installs the default
  adapter and backs off when the app binds its own `Validator` — the first real end-to-end consumer of the
  auto-configuration engine. Failures throw the kernel's `ValidationException` (HTTP 422) carrying `FieldError`s.

## [26.07.4] - 2026-07-15
### Added
- **`firefly/context`** — the boot engine:
  - `FireflyKernel` — an ordered boot pipeline (`BootPhase`/`BootPass`) that later packages extend
    exclusively via `addPass()`, sorted by a deterministic `(phase, order(), FQCN)` total order —
    never by service-provider registration order — with a `FireflyServiceProvider` base class every
    package contributes passes through.
  - Two-pass conditional registration — `#[ConditionalOnProperty]`/`#[ConditionalOnClass]`/
    `#[ConditionalOnMissingClass]`/`#[ConditionalOnProfile]` (registry-independent) and
    `#[ConditionalOnBean]`/`#[ConditionalOnMissingBean]` (evaluated incrementally against the
    `BeanDefinitionRegistry`, never against resolved instances) — with bean conditions rejected with a
    `ConfigurationException` on a user component, since only auto-configurations run late enough for
    "does this bean exist?" to have a deterministic answer.
  - `BeanPostProcessor` — a two-pass `beforeInitialization`/`afterInitialization` chain, `#[Order]`-sorted
    and frozen from the compiled manifest, with `#[PostConstruct]` invoked strictly between the two
    passes and proxy substitution confined to `afterInitialization()`.
  - `#[PostConstruct]`/`#[PreDestroy]` lifecycle callbacks, dependency-injected via `$container->call()`,
    destroyed in reverse order at `ApplicationContext::close()`.
  - `ApplicationEventPublisher` over Laravel's event dispatcher, `#[AsEventListener]`, and the
    `ContextRefreshedEvent`/`ApplicationReadyEvent`/`ContextClosedEvent` lifecycle events.
  - Octane state hygiene: `OctaneListener`/`StateResetter` drain scoped beans' `#[PreDestroy]`
    callbacks and reset scoped instances on the request sandbox, per request/task/tick, while the boot
    pipeline itself runs once per worker.
- Harness: a `Context` Deptrac layer (may depend on `Kernel` + `Container` + `Config`).

### Fixed
- **`firefly/container`** — `#[Lazy]` was silently ignored. The attribute was scanned-but-discarded:
  `ComponentScanner` never read it and `ComponentDescriptor` had no field for it, so marking a
  component or `#[Bean]` method `#[Lazy]` had no effect whatsoever. It is now captured through the
  compiled manifest and honored by the boot engine's eager-singleton phase. (Cached manifests written
  by earlier versions still load; the field defaults to `false`.)

## [26.07.3] - 2026-07-15
### Fixed
- **`firefly/container`** — an explicit `#[Bean]` factory whose return type is an interface is no longer silently
  clobbered by interface auto-binding. `ContainerRegistrar::wireInterfaces()` now skips the single-default binding for
  any interface already bound by a `#[Bean]`, so an explicit bean definition takes precedence over an auto-wired
  `#[Primary]`/sole implementation (matching Spring semantics). Implementations are still tagged for `getAll()`.

## [26.07.2] - 2026-07-15
### Added
- **`firefly/config`** — Spring-style configuration over Laravel's config repository:
  - **Profiles** — `Profiles`/`ProfileResolver` (active profiles from `FIREFLY_PROFILES_ACTIVE`, else `APP_ENV`,
    else `default`) and a `#[Profile]` marker.
  - **Typed `Config` accessor** — `string()`/`int()`/`bool()`/`array()`/`get()`/`has()` with fail-fast
    `ConfigurationException` on missing-required or type-mismatched keys.
  - **`#[ConfigProperties]` binding** — a first-party `ReflectionConfigBinder` (behind a `ConfigBinder` seam)
    maps a config subtree onto a plain readonly DTO, with recursive nested binding, discovered by
    `ConfigPropertiesScanner` and compiled to a cached, Octane-safe manifest.
  - **`ConfigValueResolver`** — a config→env→default `ValueResolver` bound over `firefly/container`'s default,
    closing the resolver seam so `#[Value]` reads application config.
- Harness: a `Config` Deptrac layer (may depend on `Kernel` + `Container`).

## [26.07.1] - 2026-07-14
### Added
- **`firefly/container`** — attribute-driven dependency injection over `Illuminate\Container`:
  - Stereotype attributes `#[Service]`/`#[Repository]`/`#[Configuration]` (specialising `#[Component]`) and
    modifiers `#[Bean]`/`#[Primary]`/`#[Order]`/`#[Lazy]`/`#[Qualifier]`.
  - `ComponentScanner` + `ManifestCompiler` — PSR-4 scanning compiled to a cached, Octane-safe manifest.
  - `ContainerRegistrar` — Singleton/Transient/Scoped scopes, interface auto-binding, `#[Primary]` defaults,
    named aliases, tagged ordered lists, and `#[Bean]` factories with method injection.
  - `Firefly\Container\Container` facade — resolve by type/interface/name and `#[Order]`-sorted `getAll()`.
  - `#[Value]` injection (`${ENV:default}` + `#{expr}`) via a pluggable `ValueResolver`.
- Harness: Larastan added to PHPStan; a `Container` Deptrac layer (may depend on `Kernel` only).

## [26.07.0] - 2026-07-14

First milestone of the LaraFly Foundation cycle: the Composer monorepo, the quality harness, and the
`firefly/kernel` package.

### Added
- **Monorepo** of Composer packages (`symplify/monorepo-builder`) with local path-repo dev wiring under `packages/*`.
- **Quality harness** behind one `composer check`: Pest, PHPStan (level max), Laravel Pint, Deptrac
  (`deptrac/deptrac` 4.x), plus CalVer (`YY.MM.Patch`) versioning with a tag↔constant consistency test.
- **CI** (GitHub Actions): PHP 8.3/8.4/8.5 quality matrix + a strict MkDocs docs build.
- **Docs** (MkDocs Material): home, architecture overview, and the Error Handling module page.
- **`firefly/kernel`** — the zero-dependency foundation package:
  - `Lifecycle` — the `start()`/`stop()` contract for infrastructure adapters.
  - `FireflyException` taxonomy — a base plus 22 typed exceptions across Business, Security, Infrastructure,
    External, and Framework/Plugin groups, each carrying a stable error code, HTTP status, category, and severity.
  - RFC-7807 error model — `ErrorResponse` (with `fromException()`), `FieldError`, `ErrorCategory`, `ErrorSeverity`.
  - `Version` — the CalVer framework version constant.
