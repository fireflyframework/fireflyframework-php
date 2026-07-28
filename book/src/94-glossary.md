<span class="eyebrow">Appendix</span>

# Glossary {.chtitle}

**Actuator** — LaraFly's production-management surface: framework endpoints (`/actuator/health`, `/actuator/info`, `/actuator/env`, and others) mounted directly on the same Illuminate `Router` your own controllers use, secure-by-default and unreachable until explicitly exposed. The Spring Boot Actuator analogue, shipped by `firefly/actuator` (Chapter 11).

**Adapter** — A concrete class that implements a port by delegating to a specific technology — an Eloquent-backed repository, a Kafka-backed message broker. Adapters live at the edge of the hexagonal architecture and can be swapped without touching domain or application code (Chapters 5, 6).

**Aggregate root** — The single entry point to a cluster of domain objects that must stay consistent together. All state changes are routed through its methods, which enforce invariants and record domain events; external code loads and saves only the root, never its inner objects. `Wallet` is Lumen's aggregate root — its one invariant is that the balance never goes negative (Chapter 6).

**ApplicationContext** — The boot-engine facade: the object every test and every request-time consumer resolves to reach a booted bean, a compiled manifest, or the kernel's own state. `fireflyContext()` is how a `FireflyTestCase` subclass reaches it (Chapters 2, 12).

**Attribute (PHP 8)** — Native PHP 8 syntax (`#[Component]`, `#[CommandHandler]`, `#[Transactional]`, …) that LaraFly reads at scan time to discover a class's architectural role. Attributes replace the annotation-driven configuration Spring Boot expresses in Java; a compiled manifest is what makes reading them a one-time, not a per-request, cost (Chapter 2).

**Authentication** — An immutable token answering "who is making this request?" — a principal, its granted authorities, and whether it has actually been authenticated. `Authentication::authenticated()`/`unauthenticated()` are its only two constructors (Chapter 10).

**Authorization** — The question "is this principal allowed to do *this specific thing*?", answered independently of authentication by `HttpSecurity`'s deny-by-default URL rules and by method security (`#[PreAuthorize]`, `#[Secured]`, `#[RolesAllowed]`) (Chapter 10).

**Autoconfiguration** — A `#[Configuration]` class, ordered by `#[Order(N)]`, whose `#[Bean]` factory methods register a capability's default beans — always guarded by `#[ConditionalOnMissingBean]` so an application override wins. `ObservabilityAutoConfiguration`'s `#[Order(500)]` winning the `CqrsMetrics` race before `CqrsAutoConfiguration` evaluates at `#[Order(1000)]` is the canonical example of ordering deciding which default survives (Chapters 2, 7, 10, 11).

**Autowiring** — Resolving a bean's constructor parameters from their declared types alone, with no factory code written by hand. LaraFly's compiled component scan performs this once, at cache time, rather than by reflection on every request (Chapter 2).

**Bean** — Any object the container creates, wires, and manages. A class becomes a bean by carrying a stereotype attribute (`#[Component]`, `#[Service]`, `#[Repository]`, `#[Configuration]`), or by a `#[Bean]`-attributed factory method inside a `#[Configuration]` class (Chapter 2).

**BootPass** — A single, ordered unit of boot-time work — scanning, condition evaluation, bean registration, route mounting — run by the `FireflyKernel` at a specific `BootPhase`. `ActuatorRouteRegistrar` and `MeterBindingsPass` are both `BootPass`es (Chapters 2, 11).

**Circuit breaker** — A resilience pattern that trips to an open state after a threshold of consecutive failures against a dependency, short-circuiting further calls until the dependency has had time to recover. `firefly/observability`'s `MeterBindingsPass` exposes each configured breaker's live state as a `resilience_circuit_breaker_state{name}` gauge (Chapter 11).

**Command (CQRS)** — An object expressing a single write intent — "withdraw funds", "open a wallet" — dispatched through the `CommandBus` to exactly one `#[CommandHandler]`. Commands may be denied before they ever reach their handler by a `CommandAuthorizer` (Chapters 7, 10).

**CommandBus** — The pipeline that receives a `Command`, runs it through a bounded sequence of stages (correlate → validate → authorize → invoke → metrics), and routes it to its one registered `#[CommandHandler]` (Chapter 7).

**Component** — The generic stereotype attribute for a managed bean that does not fit the more specific `#[Service]`/`#[Repository]`/`#[Configuration]` roles. All stereotypes are equivalent to the container; the distinction is for human readers and tooling (Chapter 2).

**Condition (`#[ConditionalOn*]`)** — An attribute (`#[ConditionalOnProperty]`, `#[ConditionalOnBean]`, `#[ConditionalOnMissingBean]`) evaluated during the boot pipeline's condition pass to decide whether a bean definition survives. `DbHealthIndicator`'s `#[ConditionalOnProperty]` with no `matchIfMissing` is what keeps it opt-in rather than on by default (Chapters 2, 11).

**Deptrac** — The static architecture-boundary linter (`deptrac/deptrac`) that enforces which package may depend on which, as declared layers in `deptrac.yaml`. A `0` violation count is part of this book's own definition of done for every chapter's code (Chapters 2, 11, 13).

**Domain event** — An immutable record of a business fact that has already happened — "wallet opened", "funds withdrawn" — recorded by an aggregate root and published, after a successful commit, through an `ApplicationEventPublisher` (Chapters 6, 8, 9).

**DTO (Data Transfer Object)** — A plain object carrying data across a layer boundary — a request body, a `#[ConfigProperties]`-bound configuration subtree — without exposing internal domain types (Chapters 3, 4).

**Entity** — A domain object with a stable identity that persists through state changes. LaraFly's `make:firefly-entity` scaffolds a class extending `Firefly\Domain\Entity` — there is no `#[Entity]` attribute (Chapters 6, 13).

**EventListener** — Either of two distinct, deliberately unrelated surfaces: `#[AsEventListener]` for Laravel's own in-process event dispatch, and `#[EventListener]` for `firefly/eda`'s broker-backed bus, which subscribes to an event-type *pattern* rather than a PHP class (Chapter 8).

**Granted authority** — A single permission or role string (`'ROLE_ADMIN'`, `'orders:read'`) carried by an `Authentication`. `hasRole('X')` normalises to a check against the granted authority `'ROLE_X'` (Chapter 10).

**Health indicator** — A one-method SPI (`HealthIndicator::health(): Health`) that a `#[Component]` implements to report whether some dependency or resource is up. `HealthEndpoint` aggregates every registered indicator to the single most-severe `Status` (Chapter 11).

**Hexagonal architecture** — An architectural style placing domain and application logic at the centre, surrounded by ports (interfaces) that adapters implement at the edges. Business code depends only on a port; which adapter satisfies it at runtime is a wiring decision, never a business-logic one (Chapters 1, 5, 6).

**Integration event** — A domain event that has crossed the boundary from the in-process domain model onto the broker-backed EDA bus, via the guarded after-commit bridge introduced alongside CQRS. Not the same object, and not the same surface, as the domain event that produced it (Chapters 7, 8, 9).

**JWT (JSON Web Token)** — A signed, expiring bearer token `JwtService` issues and validates. LaraFly's `JwtService` refuses to boot at all with a weak or placeholder signing secret, and refuses to accept any token missing its `exp` claim, however valid its signature (Chapter 10).

**Manifest** — A compiled, cacheable snapshot of everything a scanner found — routes, CQRS handlers, event listeners, security rules, transactional methods — written to `bootstrap/cache/firefly/` by `firefly:cache` and loaded back in at boot with no reflection at all (Chapters 2, 13).

**Meter** — A single named, tagged measurement — a `Counter`, a `Gauge`, or a `Timer` — held by a `MeterRegistry`. Registration is idempotent per `type|name|sorted-tags`; re-registering one name under a different type is a programming error the registry rejects loudly (Chapter 11).

**Method security** — Attribute-declared authorization (`#[PreAuthorize]`, `#[Secured]`, `#[RolesAllowed]`) enforced at the CQRS bus and the controller dispatcher by a closed-whitelist expression evaluator that never calls `eval()` (Chapter 10).

**Outbox pattern** — A technique for publishing a domain event reliably alongside the database write that produced it: both are written in the same local transaction, eliminating the need for a two-phase commit between the database and the message broker (Chapters 8, 9).

**Pest expectation** — A custom `expect()->extend(...)` matcher `firefly/testing` installs — `toHavePublished`, `toHaveHandledCommand`, `toBeUp`, `toHaveRecordedMetric`, `toBeProblemDetails` — registered once from the monorepo's root `tests/Pest.php` (Chapter 12).

**Port** — An interface a piece of business logic depends on, with no implementation detail attached. `EventPublisher`, `CommandBus`, `HealthIndicator`, and `MeterRegistry` are all ports; the concrete adapter satisfying each is a wiring decision (Chapters 1, 5, 11).

**Principal** — The "who" of a request — the `mixed $principal` an `Authentication` carries once authenticated, together with its granted authorities (Chapter 10).

**Problem Details (RFC 7807)** — The standard `application/problem+json` error shape (`type`, `title`, `status`, plus framework-specific extension keys like `code`/`category`) every unhandled exception renders as. `ProblemDetailsRenderer` is what produces it (Chapters 4, 12).

**Projection** — A read model built by consuming a stream of domain events, kept separate from the write model that produced them — Lumen's ledger is a projection over `Wallet`'s own domain events (Chapter 6).

**Proxy (`#[Transactional]` proxy)** — A generated subclass that wraps a `#[Transactional]`-attributed method with declarative transaction boundaries (`beginTransaction()`/`commit()`/`rollBack()`), produced by the same reflection-free `ProxyClassGenerator` whether at boot time (dev) or ahead of time by `firefly:cache` (production) (Chapters 9, 13).

**Query (CQRS)** — An object expressing a read intent — "get wallet balance" — dispatched through the `QueryBus` to exactly one `#[QueryHandler]`. Queries never mutate state (Chapter 7).

**QueryBus** — The read-side counterpart to `CommandBus`: receives a `Query`, routes it to its `#[QueryHandler]`, and (Chapter 7's pipeline) can serve a cached result transparently.

**Recording double** — A plain implementation of a real Firefly port that records what it saw, so a test can assert on behavior — `RecordingEventPublisher`, `RecordingCommandBus`, and eight siblings ship in `firefly/testing`, one per port Laravel's own `Event::fake()`/`Bus::fake()` cannot see (Chapter 12).

**Repository** — A collection-like abstraction over persistence, letting application code load and save aggregates or entities with no SQL of its own. `CrudRepository` is the typed base every domain repository extends (Chapter 5).

**Secure by default** — LaraFly's operational posture wherever it applies: an actuator endpoint is unreachable (404) until explicitly exposed, an `HttpSecurity` URL rule denies anything unmatched, and a JWT secret that looks weak refuses to boot at all — the system fails closed, not open (Chapters 10, 11).

**SecurityContext** / **SecurityContextHolder** — An immutable snapshot of the current `Authentication`, held per-request by a static accessor backed by Laravel's own `Context` facade. Every authentication filter clears it in a `finally` block so one request's principal can never leak into the next (Chapter 10).

**Service** — A managed bean, `#[Service]`-attributed, that houses business logic and orchestrates calls to repositories, event publishers, and other services (Chapter 2).

**Slice test** — A test that boots only the beans a narrow vertical needs — the web pipeline over one controller (`WebSliceTestCase`), or the data pipeline over one repository (`DataSliceTestCase`) — rather than the whole application, so a mis-wired slice fails fast rather than passing by accident (Chapter 12).

**Stereotype** — An attribute that both registers a class as a bean and signals its architectural role — `#[Service]`, `#[Repository]`, `#[Component]`, `#[Configuration]`, `#[RestController]`. Every stereotype is a specialization of the base `#[Component]` (Chapter 2).

**Testcontainers** — A library that starts real Docker containers for integration tests and tears them down afterward. `firefly/testing`'s `RequiresDocker` trait and `fireflyConfigFor()` helper make a testcontainers-backed test opt-in and CI-safe even with no Docker present (Chapter 12).

**Transactional (`#[Transactional]`)** — A class/method attribute declaring propagation (`REQUIRED`, `REQUIRES_NEW`, `NESTED`, …), isolation, read-only, and rollback rules — enforced by a generated proxy rather than a manual `DB::transaction()` closure (Chapter 9).

**Value object** — An immutable domain object identified by its value rather than by an identity field; two value objects are equal if all their fields are equal. `Money` — an integer amount in minor units plus a `Currency` — is Lumen's canonical value object (Chapter 6).

**Zero-reflection boot** — The state an application reaches once `firefly:cache` has run: every manifest is loaded from a pre-compiled PHP file under `bootstrap/cache/firefly/`, so no attribute, class, or method is ever reflected on again at request time (Chapter 13).
