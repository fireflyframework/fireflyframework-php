<p align="center">
  <img src="docs/assets/larafly-banner.svg" alt="LaraFly — Firefly Framework for PHP" width="100%">
</p>

<h1 align="center">LaraFly</h1>

<p align="center">
  <strong>Spring Boot's cohesion, native to Laravel 13.</strong>
</p>

<p align="center">
  <a href="https://github.com/fireflyframework/fireflyframework-php/actions/workflows/ci.yml"><img src="https://github.com/fireflyframework/fireflyframework-php/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://github.com/fireflyframework"><img src="https://img.shields.io/badge/Firefly_Framework-official-ff6600" alt="Firefly Framework"></a>
  <a href="docs/installation.md#requirements"><img src="https://img.shields.io/badge/php-8.3%2B-blue?logo=php&logoColor=white" alt="PHP 8.3+"></a>
  <a href="docs/laravel-comparison.md"><img src="https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white" alt="Laravel 13"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-Apache%202.0-green" alt="License: Apache 2.0"></a>
  <a href="CHANGELOG.md"><img src="https://img.shields.io/badge/version-26.07.18-brightgreen" alt="Version: 26.07.18"></a>
  <a href="docs/contributing.md#conventions"><img src="https://img.shields.io/badge/PHPStan-max-8A2BE2" alt="PHPStan: max"></a>
  <a href="pint.json"><img src="https://img.shields.io/badge/code%20style-Pint-F55247" alt="Code Style: Pint"></a>
</p>

<p align="center">
  <em>Dependency injection with stereotypes, conditional auto-configuration, hexagonal ports &amp; adapters,
  CQRS, event-driven architecture with a genuine same-transaction outbox, and Spring-Security-shaped method
  security — wired the moment you install a package, with your own beans always winning.</em>
</p>

<p align="center">
  <a href="#-the-book--larafly-by-example"><b>📘 Book</b></a> &nbsp;·&nbsp;
  <a href="#quickstart"><b>Quickstart</b></a> &nbsp;·&nbsp;
  <a href="#why-larafly">Why</a> &nbsp;·&nbsp;
  <a href="#architecture">Architecture</a> &nbsp;·&nbsp;
  <a href="#featured-patterns">Patterns</a> &nbsp;·&nbsp;
  <a href="#modules">Modules</a> &nbsp;·&nbsp;
  <a href="#documentation">Docs</a> &nbsp;·&nbsp;
  <a href="CHANGELOG.md">Changelog</a>
</p>

<details>
<summary><b>Table of contents</b></summary>

- [📘 The Book — *LaraFly by Example*](#-the-book--larafly-by-example)
- [Why LaraFly?](#why-larafly)
- [Quickstart](#quickstart)
- [Philosophy](#philosophy)
- [Architecture](#architecture) — [Boot Pipeline](#the-boot-pipeline) · [DI &amp; Auto-Configuration](#dependency-injection--auto-configuration) · [Request Lifecycle](#request-lifecycle) · [CQRS ⟷ EDA Bridge](#the-cqrs--eda-bridge) · [Same-Tx Outbox](#the-genuine-same-transaction-outbox)
- [Featured Patterns](#featured-patterns)
- [Installation](#installation)
- [CLI &amp; Project Scaffolding](#cli--project-scaffolding)
- [Modules](#modules)
- [Documentation](#documentation)
- [Roadmap](#roadmap)
- [Firefly Framework Ecosystem](#firefly-framework-ecosystem)
- [Requirements](#requirements)
- [Contributing](#contributing)
- [License](#license)

</details>

---

## 📘 The Book — *LaraFly by Example*

**LaraFly by Example** is the official, project-driven book for the framework — a PHP sibling to
[*PyFly by Example*](https://github.com/fireflyframework/fireflyframework-pyfly). It builds **Lumen**, the
wallet-and-ledger service in [`samples/lumen/`](samples/lumen/), from an empty directory into a secured,
event-driven, actuator-observed microservice, chapter by chapter — every listing drawn from that real project
(it boots and its tests pass against this framework version, `26.07.18`).

The book is **complete and bilingual (English + Spanish)**: a quick start, **thirteen chapters** across four
parts — Foundations (DI, config, HTTP), Modelling & Persisting the Domain (repositories, DDD), Coordinating &
Securing the App (CQRS, EDA + transactional outbox, `#[Transactional]`, security), and Observability, Testing
& Delivery (actuator, testing, the CLI + zero-reflection cache) — plus a Laravel→LaraFly cheat-sheet and a
glossary. Every fenced PHP listing is `php -l`-verified against the real sample. The sources live under
[`book/`](book/README.md) ([EN manuscript](book/src/) · [ES manuscript](book/src-es/)) and build to PDF + EPUB
in both languages via [`book/build/run.sh`](book/README.md). `samples/lumen/` is the fastest way to see the
whole stack fit together end to end, and the [Featured Patterns](#featured-patterns) section below walks
through its most important pieces.

---

## Why LaraFly?

### The problem

Laravel gives you a superb HTTP kernel, ORM, and ecosystem — but it doesn't tell you how to structure a
non-trivial application. Where does business logic live? How do controllers stay thin? How do you keep
Eloquent out of your domain model? How do you make a wallet transfer and its audit-log write commit or roll
back *together*? How do you stop an unauthenticated request from calling an admin-only command bus handler?
Every team answers these questions differently, and every answer has to be re-learned by the next engineer who
joins.

**Laravel gives you infinite flexibility. What it doesn't give you is a shared architecture.**

### What LaraFly is

LaraFly is a **cohesive, hexagonal application layer** on top of Laravel 13 — the PHP member of the
[Firefly Framework](https://github.com/fireflyframework) family, built to feel exactly like Spring Boot to
anyone who has used it: PHP-8-attribute dependency injection with stereotypes, conditional auto-configuration
that backs off the moment you supply your own bean, a CQRS command/query bus, event-driven architecture with a
**genuine same-transaction outbox** (not a "publish and hope"), declarative `#[Transactional]` transaction
demarcation, deny-by-default method and URL security, and a production-ready actuator/observability surface —
all compiled ahead of time into `bootstrap/cache/firefly/` for a **zero-reflection boot**.

```php
// skeleton/app/GreetingService.php — a #[Service] bean, autowired by type
#[Service]
final class GreetingService
{
    public function __construct(private readonly GreetingProperties $properties) {}

    public function greet(string $name): string
    {
        return sprintf('%s, %s!', $this->properties->salutation, $name);
    }
}

// skeleton/app/Http/GreetingController.php — a #[RestController], routes compiled to a manifest
#[RestController]
final class GreetingController
{
    public function __construct(private readonly GreetingService $greetings) {}

    #[GetMapping('/greetings/{name}')]
    public function show(#[PathVariable] string $name): array
    {
        return ['message' => $this->greetings->greet($name)];
    }
}
```

No service-provider boilerplate, no manual route registration: the component scanner finds `GreetingService`
and `GreetingController`, the container autowires `GreetingProperties` into the service by constructor type,
and the route scanner compiles `#[GetMapping('/greetings/{name}')]` into the route table. `php artisan
firefly:cache` compiles all of that ahead of time for a reflection-free boot; without it the same scan simply
runs in-process at boot instead, so the app behaves identically either way. See [Featured Patterns](#featured-patterns) below for the full CQRS, EDA,
outbox, and security tour, drawn from the runnable `samples/lumen/` wallet-ledger sample.

LaraFly is not a fork of Laravel and does not hide it — every package layers cleanly on top of
`Illuminate\Container`, Eloquent, the HTTP kernel, and the queue/cache/scheduler, so everything you already
know about Laravel keeps working underneath.

### Who is LaraFly for?

- **Laravel developers** who want enterprise-grade architecture — DI stereotypes, hexagonal ports, CQRS, an
  event bus with a real outbox — without assembling it package by package.
- **Teams** who want every service in the org to share one convention instead of reinventing structure per
  project.
- **Architects** building polyglot platforms who need consistency across Java, Python, and PHP services.
- **Anyone coming from Spring Boot** who wants the same mental model expressed natively in PHP — see the
  [Laravel ↔ Spring Boot comparison guide](docs/laravel-comparison.md).

---

## Quickstart

```bash
# 1 · Install the global installer once, then scaffold a new app
composer global require firefly/installer
firefly new my-app
cd my-app

# 2 · Compile the app for a zero-reflection boot (idempotent — create-project already ran this once)
php artisan firefly:cache

# 3 · Run it
php artisan firefly:serve
```

`firefly new` wraps `composer create-project firefly/skeleton` (git-init included by default), which already
wires a `#[Controller]` welcome page, a sample `#[RestController]`/`#[Service]` pair, sqlite for storage, and a
`post-create-project-cmd` hook that ran `firefly:cache` for you — so the app is already booting
reflection-free. Re-run `firefly:cache` any time you add or change a
`#[Component]`/`#[RestController]`/`#[CommandHandler]`/etc. class; `firefly:clear` drops back to the
in-process scan, which is slower but functionally identical. See [Installation](#installation) for the
non-global-installer path and [CLI & Project Scaffolding](#cli--project-scaffolding) for the full command
reference.

---

## Philosophy

Four principles shape every design decision in LaraFly.

### Convention Over Configuration

A new capability package should work the moment it's installed. `firefly/autoconfigure` discovers each
package's `#[Configuration]` classes, and a `#[ConditionalOnMissingBean]`-guarded `#[Bean]` backs off silently
the instant you register your own — "default, with override," the same contract Spring Boot's auto-configuration
gives you.

### Your Code, Not Laravel's

Business logic should never `use Illuminate\Database\Eloquent\Model` directly if it can help it. LaraFly
enforces **hexagonal architecture** — ports and adapters — across every capability that touches storage or
transport:

```php
// samples/lumen/src/Infrastructure/WalletRepository.php — the PORT the domain depends on
interface WalletRepository
{
    public function save(Wallet $wallet): Wallet;

    public function findById(string $id): ?Wallet;

    /** @return list<Wallet> */
    public function findByOwnerId(string $ownerId): array;
}
```

```php
// samples/lumen/src/Infrastructure/EloquentWalletRepository.php — the ADAPTER, auto-bound by interface
#[Repository]
final class EloquentWalletRepository extends EloquentRepository implements WalletRepository
{
    protected string $model = Wallet::class;
    // save()/findById()/findByOwnerId() implement the port over Eloquent — see the file for the full body.
}
```

Application and command-handler code depends only on `WalletRepository`; the container's nominal
interface-binding wires `EloquentWalletRepository` in behind it automatically. Deptrac enforces the boundary at
the monorepo level — a domain package that imports `Illuminate\Database\*` fails the architecture gate.

### Typed and Attribute-Driven

Every public surface is typed and analysed at **PHPStan `max`** strictness — no `mixed` left un-narrowed, no
`@phpstan-ignore` as a substitute for a real fix. Framework behaviour is declared with PHP 8 attributes
(`#[Service]`, `#[RestController]`, `#[CommandHandler]`, `#[Transactional]`, `#[PreAuthorize]`, ...), scanned
once and compiled to a cached manifest — never re-reflected on a production request.

### Secure and Production-Ready by Default

`firefly/security`'s HTTP rule chain is **deny-by-default**: an unmatched URL is denied, not silently allowed.
`firefly/actuator` ships health/info/metrics endpoints that are **unexposed (404) until you opt in**. A cached
`firefly:cache` boot is the *supported* way to run in production — reflection only ever happens once, at build
time, never per request.

---

## Architecture

LaraFly is **hexagonal** end to end: every capability exposes a nominal PHP interface (a *port*) with one or
more adapters, domain/application code depends only on ports, and Deptrac enforces the boundary at the
monorepo level.

### The boot pipeline

Every capability package plugs into one shared `FireflyKernel` through a `FireflyServiceProvider`: a
provider's `register()` only *buffers* its `BootPass` contributions into a `PendingBootPasses` collector — it
never resolves the kernel itself, so Laravel's alphabetical package-discovery order can never affect boot
order. The kernel then drains that buffer and drives the real, phased order:

<p align="center">
  <img src="docs/assets/diagrams/boot-pipeline.svg" alt="LaraFly boot pipeline: providers buffer BootPass contributions; the kernel drains them in phased order — component scan, context refresh, auto-configuration discovery/commit/back-off, route/handler/listener manifests, then the app serves requests." width="100%">
</p>

### Dependency Injection & Auto-Configuration

The DI container (`firefly/container`) resolves dependencies from **type hints** discovered by a component
scan — no XML, no service locators:

```php
use Firefly\Container\Attributes\{Primary, Qualifier, Service};

interface Greeter { public function greet(): string; }

#[Service] #[Primary]
final class EnglishGreeter implements Greeter { public function greet(): string { return 'Hello'; } }

#[Service('spanish')] #[Qualifier('spanish')]
final class SpanishGreeter implements Greeter { public function greet(): string { return 'Hola'; } }
```

```php
$container->get(Greeter::class);         // EnglishGreeter (the #[Primary] one)
$container->getByName('spanish');        // SpanishGreeter
$container->getAll(Greeter::class);      // every implementation, sorted by #[Order]
```

`firefly/autoconfigure` layers Spring-Boot-style starters on top: a candidate `#[Configuration]` class is
*discovered* at phase 200, its bean definitions are *committed* to the registry at phase 500 tagged
`DefinitionSource::AutoConfiguration`, and a `#[ConditionalOnMissingBean(X)]`-guarded `#[Bean]` only survives
*back-off* at phase 600 if nothing else already registered an `X` — so your own bean always wins, regardless of
provider load order:

<p align="center">
  <img src="docs/assets/diagrams/di-autoconfig.svg" alt="LaraFly dependency injection and auto-configuration: a component scan discovers #[Service]/#[Repository]/#[Configuration] classes, auto-configuration candidates are discovered then committed then back off against any user-supplied bean, and the container resolves the final graph by type, interface, and name." width="100%">
</p>

### Request Lifecycle

An HTTP request flows through the Spring-style filter chain (`firefly/web`), into a `#[RestController]`
action, through argument-resolved, `#[Valid]`-checked parameters, into the CQRS bus, through a
`#[CommandHandler]`/`#[QueryHandler]`, into a repository port and its Eloquent adapter, and back — with any
raised domain events drained after commit:

<p align="center">
  <img src="docs/assets/diagrams/request-lifecycle.svg" alt="LaraFly request lifecycle: an HTTP request flows through the web-filter chain into a #[RestController], through validation, into the CommandBus/QueryBus, into a #[CommandHandler] and its repository port/Eloquent adapter, with domain events draining after commit and a JSON (or RFC-7807 problem+json) response returned." width="100%">
</p>

### The CQRS ⟷ EDA bridge

`firefly/cqrs`'s bus wraps every handler call in a bounded pipeline (correlate → validate → authorize → invoke
→ metrics) and re-throws any fault as a category-preserving `CommandProcessingException`/
`QueryProcessingException`. After a `#[Transactional]` handler's unit of work commits, the same bridge
republishes each raised `DomainEvent` onto the `firefly/eda` bus as an integration event — closing the
domain → integration-event loop with no glue code in application handlers:

<p align="center">
  <img src="docs/assets/diagrams/cqrs-eda-bridge.svg" alt="LaraFly CQRS/EDA bridge: a #[Transactional] command handler raises domain events on the aggregate, the DomainEventDispatcher drains them after commit, and a guarded-wildcard listener republishes each one onto the eda EventPublisher as an integration event, delivered to #[EventListener] subscribers." width="100%">
</p>

### The genuine same-transaction outbox

The default `memory`/`queue` `firefly/eda` providers publish only *after* the aggregate's transaction commits —
never atomically with it. `firefly/eda-postgres` closes that gap: with `firefly.eda.provider=postgres`, the
outbox row is written **inside** the aggregate's own open transaction, on the aggregate's own connection, so it
commits or rolls back atomically with the aggregate — no dual-write, no "commit then hope the publish
succeeds":

<p align="center">
  <img src="docs/assets/diagrams/outbox-flow.svg" alt="The genuine same-transaction outbox: an aggregate's transaction begins, the domain write and the outbox INSERT + pg_notify happen on the same connection inside the same transaction, and only on commit does Postgres deliver the NOTIFY to a LISTEN-ing consumer that drains the row to subscribed #[EventListener] handlers." width="100%">
</p>

See the [same-tx outbox showcase](#same-transaction-outbox--fireflyedaproviderpostgres) below for the config
and the seam that makes this possible.

---

## Featured Patterns

Nine showcases below, each an accurate snippet lifted straight from `samples/lumen/` (the wallet-and-ledger
sample) or the framework itself — no invented API. Every attribute and class shown here compiles against the
shipped `26.07.18` release.

### Attribute DI — `#[Service]`

<!-- source: skeleton/app/GreetingService.php -->

```php
use Firefly\Container\Attributes\Service;

#[Service]
final class GreetingService
{
    public function __construct(private readonly GreetingProperties $properties) {}

    public function greet(string $name): string
    {
        return sprintf('%s, %s!', $this->properties->salutation, $name);
    }
}
```

A `#[Service]` is a specialisation of `#[Component]` — registered as a container singleton by a scan that finds
every class carrying it, with its `GreetingProperties` constructor dependency autowired by type.
**Highlights:** stereotypes, scopes, `#[Primary]`/`#[Qualifier]`, `#[Order]` — see
[Dependency Injection](docs/modules/dependency-injection.md).

### REST controller — `#[RestController]`

<!-- source: samples/lumen/src/Web/WalletController.php -->

```php
#[RestController]
#[RequestMapping('/api/v1/wallets')]
final class WalletController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

    #[PostMapping(status: 201)]
    public function open(#[Valid] #[RequestBody] OpenWalletRequest $body): array
    {
        $id = $this->commands->send(new OpenWallet($body->owner_id, Currency::from($body->currency)));

        return ['wallet_id' => $id];
    }

    #[GetMapping('/{id}/balance')]
    public function balance(#[PathVariable] string $id): array
    {
        $balance = $this->queries->ask(new GetBalance($id));
        if ($balance === null) {
            throw new ResourceNotFoundException("Wallet {$id} not found");
        }

        return ['wallet_id' => $id, 'balance_minor' => $balance];
    }
}
```

A thin HTTP-onto-CQRS mapping: every action builds a command/query, dispatches it through the bus, and shapes
the response array. A domain fault (an unknown wallet, an overdraw, a denied `#[PreAuthorize]`) surfaces as an
RFC-7807 `problem+json` response from the framework's global renderer — no local `#[ExceptionHandler]` needed.
**Highlights:** `#[GetMapping]`/`#[PostMapping]`, `#[PathVariable]`/`#[RequestBody]`, the web filter chain — see
[Web Layer](docs/modules/web.md).

### CQRS — `#[CommandHandler]`

<!-- source: samples/lumen/src/Application/Command/OpenWalletHandler.php -->

```php
#[CommandHandler]
class OpenWalletHandler
{
    public function __construct(private readonly WalletRepository $wallets) {}

    #[Transactional]
    public function handle(OpenWallet $command): string
    {
        $id = 'wlt-'.bin2hex(random_bytes(8));
        $wallet = Wallet::open($id, $command->ownerId, $command->currency);
        $this->wallets->save($wallet);

        return $id;
    }
}
```

The handled command type is inferred from the sole `handle()` parameter — no explicit
`#[CommandHandler(OpenWallet::class)]` needed. `#[CommandHandler]`/`#[QueryHandler]` both specialise
`#[Component]`, so the handler is a constructor-injected DI bean automatically. **Highlights:** the bounded
command/query pipeline (correlate → validate → authorize → invoke → metrics), category-preserving exception
wrapping — see [CQRS](docs/modules/cqrs.md).

### Domain aggregate + repository — `AggregateRoot`-style events, `EloquentRepository`

<!-- source: samples/lumen/src/Domain/Wallet.php, samples/lumen/src/Domain/Event/WalletOpened.php -->

```php
final class Wallet extends Model implements RecordsDomainEvents
{
    use HasDomainEvents;

    public static function open(string $id, string $ownerId, Currency $currency): self
    {
        if (trim($ownerId) === '') {
            throw new ConflictException('owner_id is required');
        }

        $wallet = new self(['id' => $id, 'owner_id' => $ownerId, 'currency' => $currency->value, 'balance_minor' => 0]);
        $wallet->raiseEvent(new WalletOpened($id, $ownerId, $currency->value));

        return $wallet;
    }
}

#[PublishDomainEvent('wallet.events')]
final readonly class WalletOpened extends DomainEvent
{
    public function __construct(
        public string $walletId,
        public string $ownerId,
        public string $currency,
    ) {
        parent::__construct();
    }
}
```

`raiseEvent()` (from the `HasDomainEvents` trait) buffers the event on the aggregate; `#[Transactional]`'s
generated proxy is the sole caller of `DomainEventDispatcher::dispatchAfterCommit()`, so the event only
publishes once the enclosing unit of work actually commits — never on a rolled-back write. **Highlights:**
`Entity`/`ValueObject`/`AggregateRoot`, derived queries, specifications — see
[Domain (DDD)](docs/modules/domain.md) and [Data & Repositories](docs/modules/data.md).

### EDA — `#[EventListener]`

<!-- source: samples/lumen/src/Application/Listener/LedgerProjector.php -->

```php
#[Component]
final class LedgerProjector
{
    #[EventListener(['WalletOpened', 'FundsDeposited', 'FundsWithdrawn', 'TransferCompleted'])]
    public function onWalletEvent(EventEnvelope $envelope): void
    {
        $walletId = $envelope->payload['walletId'] ?? '';
        $amountMinor = $envelope->payload['amountMinor'] ?? 0;
        $balanceMinor = $envelope->payload['balanceMinor'] ?? 0;

        LedgerEntry::query()->create([
            'wallet_id' => is_string($walletId) ? $walletId : '',
            'event_type' => $envelope->eventType,
            'amount_minor' => is_int($amountMinor) ? $amountMinor : 0,
            'balance_minor' => is_int($balanceMinor) ? $balanceMinor : 0,
            'occurred_at' => now(),
        ]);
    }
}
```

`#[EventListener]` enumerates event-**type** names (matched with `fnmatch` against `$envelope->eventType`), not
the `#[PublishDomainEvent]` destination — a common gotcha the sample's own docblock calls out explicitly. This
projector turns committed wallet events into an append-only `ledger_entries` read model. **Highlights:** the
in-memory/queue adapters, retry + `DeadLetterStore`, and why `#[AsEventListener]` (in-process) and
`#[EventListener]` (the broker bus) are two distinct surfaces — see
[Event-Driven Architecture](docs/modules/eda.md).

### Same-transaction outbox — `firefly.eda.provider=postgres`

<!-- source: docs/modules/eda-brokers.md -->

```php
// config/firefly.php
'eda' => [
    'provider' => 'postgres',
    'postgres' => [
        'channel' => 'firefly_eda_events',
        'max_attempts' => 3,
    ],
],
```

```bash
composer require firefly/eda-postgres
php artisan migrate                 # creates firefly_eda_outbox
php artisan firefly:eda:consume     # terminal in-process delivery, run as a long-lived worker
```

No application code changes — the same `#[EventListener]` handlers and the same `EventPublisher::publish()`
call sites now run against a durable, same-transaction outbox instead of memory/queue. The seam is a small
`PreCommitEventHook` interface `firefly/data`'s `DomainEventDispatcher` calls (if bound) *before* the ordinary
after-commit dispatch — null by default, so installing the package without selecting `provider=postgres` is
fully inert. **Highlights:** `pg_notify`/`LISTEN` low-latency wake, `FOR UPDATE SKIP LOCKED` claiming, the
optional `firefly:outbox:relay` to a distinct downstream broker — see [EDA Brokers](docs/modules/eda-brokers.md).

### Transactions — `#[Transactional]` money-can't-vanish transfer

<!-- source: samples/lumen/src/Application/Command/TransferHandler.php -->

```php
#[CommandHandler]
class TransferHandler
{
    public function __construct(private readonly WalletRepository $wallets) {}

    #[Transactional(propagation: Propagation::REQUIRED)]
    public function handle(Transfer $command): void
    {
        $source = $this->wallets->findById($command->sourceWalletId)
            ?? throw new ResourceNotFoundException("Wallet [{$command->sourceWalletId}] not found");
        $destination = $this->wallets->findById($command->destinationWalletId)
            ?? throw new ResourceNotFoundException("Wallet [{$command->destinationWalletId}] not found");

        $amount = new Money($command->amountMinor, $source->currency());
        $source->withdraw($amount);       // debit (raises FundsWithdrawn)
        $this->wallets->save($source);    // persist inside the tx, so it can genuinely roll back
        $destination->deposit($amount);   // credit — throws on currency mismatch -> whole tx rolls back
        $this->wallets->save($destination);
        $source->recordTransferTo($command->destinationWalletId, $amount); // both legs succeeded -> raise TransferCompleted
        // commit here -> FundsWithdrawn + FundsDeposited + TransferCompleted drain atomically after the unit of work commits.
    }
}
```

`#[Transactional]` is load-bearing, not cosmetic: a proxy generated at scan time drives manual
`DB::beginTransaction()`/`commit()`/`rollBack()` boundaries (never `DB::transaction($closure)`, so a caught
exception can be committed-and-rethrown when it matches `noRollbackFor`). The debit is persisted *inside* the
transaction before the credit runs, so a currency mismatch on the credit leg genuinely rolls the debit back too
— there is no path where the source loses funds the destination never receives. **Highlights:** the seven
Spring propagation modes (including `NESTED` over Laravel savepoints), `rollbackFor`/`noRollbackFor` — see
[Transactions](docs/modules/transactional.md).

### Method security — `#[PreAuthorize]`

<!-- source: samples/lumen/src/Application/Command/WithdrawHandler.php -->

```php
#[CommandHandler]
class WithdrawHandler
{
    public function __construct(private readonly WalletRepository $wallets) {}

    #[PreAuthorize("hasRole('ADMIN') or hasRole('WALLET_OWNER')")]
    #[Transactional]
    public function handle(Withdraw $command): int
    {
        $wallet = $this->wallets->findById($command->walletId)
            ?? throw new ResourceNotFoundException("Wallet [{$command->walletId}] not found");

        $wallet->withdraw(new Money($command->amountMinor, $wallet->currency()));
        $this->wallets->save($wallet);

        return $wallet->balanceMoney()->minorUnits;
    }
}
```

`SecurityCommandAuthorizer` enforces this **before** the handler runs, at the bus — a denied withdraw surfaces
as a `CommandProcessingException` wrapping an `AuthorizationException` (403), never a silent no-op. The
expression evaluator is a closed, no-`eval` whitelist tokenizer (`hasRole`, `hasAnyRole`, `hasAuthority`,
`hasPermission`, `isAuthenticated`, `permitAll`, `denyAll`, `#param` references only). **Highlights:** the
deny-by-default `HttpSecurity` URL DSL, `JwtService`/OAuth2 resource server, CSRF + security headers — see
[Security](docs/modules/security.md).

### Observability — a `HealthIndicator` bean

<!-- source: packages/actuator/src/Health/PingHealthIndicator.php -->

```php
#[Component]
final class PingHealthIndicator implements HealthIndicator
{
    public function health(): Health
    {
        return Health::up();
    }
}
```

Any `#[Component]` implementing `HealthIndicator` is discovered by a bean-scan registrar and aggregated by
`GET /actuator/health` — a thrown `health()` degrades to `DOWN`, never an unhandled 500. Sensitive endpoints
(`/actuator/env`, `/beans`, `/conditions`, ...) return **404** until explicitly exposed via
`firefly.management.endpoints.web.exposure.include`, and `/actuator/metrics` + `/actuator/prometheus` light up
the moment `firefly/observability` is installed. **Highlights:** liveness/readiness groups, `Db`/`DiskSpace`
built-in indicators, `firefly:health`/`firefly:metrics` actuator-over-CLI — see
[Actuator](docs/modules/actuator.md) and [Observability](docs/modules/observability.md).

---

### Two browser surfaces, neither of which needs npm or a CDN

`composer require firefly/admin` mounts a server-rendered dashboard at `/firefly`: thirteen pages over the
actuator's own endpoints — health, metrics, HTTP traffic, beans, a drawn
[**bean graph**](docs/modules/bean-graph.md), conditions, routes, scheduled tasks, environment, config
properties, caches and loggers. It reads those endpoints **in-process** rather than over HTTP, so it renders
pages the JSON surface deliberately keeps unexposed — which makes its own URL the entire security boundary.
`firefly.admin.enabled` therefore defaults to `app.debug`, and an application that enables it with debug off
**must put the route behind its own auth middleware**. Read
[the access model](docs/modules/admin.md#access-the-whole-security-boundary) before you do.

`composer require firefly/openapi` mounts `GET /openapi.json` and a console at `/openapi`, both generated from
the same `RouteManifest` the dispatcher dispatches from and the same `ConstraintManifest` the validator
validates with — no annotation dialect, and nothing that can drift. `php artisan firefly:openapi --output=`
makes the document a committable build artifact a CI job can diff. The default console is the **official
Swagger UI, served from your own origin** out of the `swagger-api/swagger-ui` composer package: full feature
set, no CDN request, no npm step, and it still renders in an air-gapped or strict-CSP deployment. See
[OpenAPI](docs/modules/openapi.md).

---

## Installation

**Requirements:** PHP 8.3+ (8.4 recommended), Composer 2, and an existing (or new) Laravel 13 application —
LaraFly layers onto Laravel, it is not a standalone runtime. Full details in
[Installation](docs/installation.md).

**New project — the global installer:**

```bash
composer global require firefly/installer
firefly new my-app
```

**New project — without the installer** (exactly what `firefly new` wraps):

```bash
composer create-project firefly/skeleton my-app
```

**Adding LaraFly to an existing Laravel app** — `firefly/firefly` is a `type: metapackage` (the Maven BOM
analogue) that pulls in the whole runtime family, developer console included, with one line:

```bash
composer require firefly/firefly
```

The broker adapters (`firefly/eda-rabbitmq`, `firefly/eda-postgres`, `firefly/eda-kafka`), the browser dashboard
(`firefly/admin`), the API-documentation package (`firefly/openapi`) and the test kit
(`firefly/testing`) stay separate — require them only if you use them.

```bash
composer require firefly/admin     # /firefly — the dashboard over the actuator (see its access model first)
composer require firefly/openapi   # /openapi.json + /openapi — a spec that cannot drift, and Swagger UI
```

Point LaraFly at your app's classes and compile it:

```php
// config/firefly.php
'scan' => [
    'paths' => [
        'App\\' => app_path(),
    ],
],
```

```bash
php artisan firefly:cache
php artisan firefly:serve
```

---

## CLI & Project Scaffolding

`firefly/cli` is the developer-experience console — the Spring Boot Maven/Gradle-plugin analogue.

| Command | What it does |
|---|---|
| `firefly:cache` | Compiles the app into `bootstrap/cache/firefly/` — DI, routes, `#[ControllerAdvice]` exception handlers, validation constraints, config properties, CQRS handlers, event/message listeners, scheduled tasks, security methods, and `#[Transactional]` proxy classes — for a zero-reflection boot. |
| `firefly:clear` | The inverse — deletes `bootstrap/cache/firefly/`; the app falls back to in-process scanning. |
| `firefly:about` / `:routes` / `:health` / `:metrics` | Actuator-over-CLI: render the `info`/`env`/`beans`/`conditions`/`mappings` endpoints, the route table, aggregated health, or the metrics snapshot **in-process**, with no HTTP round-trip. |
| `make:firefly-controller` / `-service` / `-component` / `-handler` / `-listener` / `-entity` / `-repository` / `-config-properties` | One generator per stereotype — `--query` on `-handler` scaffolds a `#[QueryHandler]`, `--message` on `-listener` scaffolds a `#[MessageListener]`. `-handler` writes **two** files: the handler and the concrete command/query class its `handle()` takes. |
| `firefly:serve` / `firefly:db` | Thin passthroughs to `artisan serve` (or `octane:start` when Octane is installed) and Laravel's own `migrate`/`db:seed`/`migrate:fresh`. |

```bash
php artisan make:firefly-handler RegisterWidget
php artisan make:firefly-handler CountWidgets --query
php artisan make:firefly-listener WidgetEventListener
```

Full flag reference and generated-file contents: [CLI](docs/cli.md).

---

## Modules

27 packages under `packages/*` (plus `firefly/skeleton` at the top level — 28 shippable units in total), each
its own installable Composer package with its own tests and its own [module guide](docs/modules/):

| Group | Module | Package(s) |
|---|---|---|
| Foundation | [Error Handling](docs/modules/error-handling.md) — the product-agnostic error model and RFC-7807 `ErrorResponse` | `firefly/kernel` |
| Foundation | [Dependency Injection](docs/modules/dependency-injection.md) — stereotypes, scopes, `#[Primary]`/`#[Qualifier]`/`#[Order]` | `firefly/container` |
| Foundation | [Configuration](docs/modules/configuration.md) — profiles, `#[ConfigProperties]` binding | `firefly/config` |
| Foundation | [Application Context](docs/modules/context.md) — the phased boot engine (`ApplicationContext` port) | `firefly/context` |
| Foundation | [Auto-Configuration](docs/modules/starters.md) — `#[Configuration]`/`#[Bean]` starters, conditions | `firefly/autoconfigure` |
| Foundation | [Validation](docs/modules/validation.md) — constraint attributes, `#[Valid]`, structured 422s | `firefly/validation` |
| Web & API | [Web Layer](docs/modules/web.md) — `#[RestController]`/`#[Controller]` routing, `RouteManifest`, JSON + HTML negotiation | `firefly/web` |
| Web & API | [Web Filters](docs/modules/web-filters.md) — the ordered filter chain onto Laravel middleware | `firefly/web` |
| Web & API | [OpenAPI](docs/modules/openapi.md) — OpenAPI 3.1 generated from the compiled manifests, `firefly:openapi`, official Swagger UI from your own origin | `firefly/openapi` |
| Resilience & Scheduling | [Resilience](docs/modules/resilience.md) — retry, circuit breaker, bulkhead, timeout, rate limiter, fallback | `firefly/resilience` |
| Resilience & Scheduling | [Scheduling](docs/modules/scheduling.md) — `#[Scheduled]` + distributed locks (cache or Postgres advisory) | `firefly/scheduling`, `firefly/scheduling-postgres` |
| Data & Domain | [Domain (DDD)](docs/modules/domain.md) — `Entity`, `ValueObject`, `AggregateRoot`, `DomainEvent` | `firefly/domain` |
| Data & Domain | [Data & Repositories](docs/modules/data.md) — CRUD/paging ports, derived queries, specifications | `firefly/data` |
| Data & Domain | [Relational Data](docs/modules/data-relational.md) — `EloquentRepository`, soft-delete, auditing, optimistic locking | `firefly/data` |
| Data & Domain | [Transactions](docs/modules/transactional.md) — `#[Transactional]`, propagation, isolation | `firefly/data` |
| Eventing & Messaging | [Event-Driven Architecture](docs/modules/eda.md) — `EventPublisher`, `#[EventListener]`, retry/DLQ | `firefly/eda` |
| Eventing & Messaging | [EDA Brokers](docs/modules/eda-brokers.md) — RabbitMQ/Postgres/Kafka adapters, the same-tx outbox | `firefly/eda-rabbitmq`, `firefly/eda-postgres`, `firefly/eda-kafka` |
| Eventing & Messaging | [Messaging](docs/modules/messaging.md) — the lower-level raw-bytes broker layer | `firefly/messaging` |
| CQRS | [CQRS](docs/modules/cqrs.md) — `CommandBus`/`QueryBus`, the domain→integration-event bridge | `firefly/cqrs` |
| Security | [Security](docs/modules/security.md) — principal model, `HttpSecurity`, `#[PreAuthorize]`, JWT/OAuth2 | `firefly/security` |
| Operations | [Actuator](docs/modules/actuator.md) — health, info, env, beans, conditions, mappings | `firefly/actuator` |
| Operations | [Observability](docs/modules/observability.md) — Prometheus-format metrics, `/actuator/prometheus` | `firefly/observability` |
| Operations | [Admin Dashboard](docs/modules/admin.md) — the browser dashboard over the actuator, read in-process | `firefly/admin` |
| Operations | [Bean Graph](docs/modules/bean-graph.md) — the dashboard's drawn dependency graph, with cycle reporting | `firefly/admin` |
| Testing | [Testing](docs/modules/testing.md) — `FireflyTestCase`, recording doubles, Pest expectations | `firefly/testing` |
| Testing | [Integration Testing](docs/modules/integration-testing.md) — `@group integration`, testcontainers | `firefly/testing` |
| Tooling | [Installer](docs/modules/installer.md) — the global `firefly new` scaffolding tool | `firefly/installer` |

`firefly/firefly` (the runtime metapackage) and `firefly/cli` (the dev-console — see
[CLI & Project Scaffolding](#cli--project-scaffolding) above) round out the 27 packages; `firefly/skeleton`
is the 28th unit, a `type: project` create-project template at the top level.

---

Start at the **[documentation table of contents](docs/README.md)** — it groups every guide by topic. Highlights:

- [Getting Started](docs/getting-started.md) — the full quickstart, adding LaraFly to an existing app.
- [Tutorial](docs/tutorial.md) ([Español](docs/tutorial.es.md)) — build the wallet service end to end in ~12 guided steps.
- [Installation](docs/installation.md) — requirements, the installer, what you get out of the box.
- [Architecture](docs/architecture.md) — the boot pipeline and kernel layer, in more depth.
- [CLI Reference](docs/cli.md) — every `firefly:*` command and `make:firefly-*` generator.
- [Laravel ↔ Spring Boot Comparison](docs/laravel-comparison.md) — concept-by-concept mapping for both audiences.
- [Versioning](docs/versioning.md) · [Contributing](docs/contributing.md) · [Publishing](docs/publishing.md).
- Every [module guide](#modules) above.
- [*LaraFly by Example*](book/README.md) — the complete bilingual book (13 chapters + appendices, PDF + EPUB).
- [`samples/lumen/`](samples/lumen/) — the wallet-and-ledger sample this README's showcases are drawn from;
  run its own test suite with `vendor/bin/pest samples/lumen/tests`.

Between the [tutorial](docs/tutorial.md), the [book](book/README.md), the [module guides](#modules), and the
runnable [`samples/lumen/`](samples/lumen/), you have a complete, concrete tour of the framework — from a first
HTTP endpoint to CQRS, the transactional outbox, method security, and the zero-reflection production cache.

---

## Roadmap

LaraFly's Foundation milestones (M1 through M14 — kernel, container, config, context, autoconfigure,
validation, web, resilience, scheduling, data/domain, eda/messaging, cqrs, security, actuator/observability,
testing, and the CLI/metapackage/skeleton) are complete, followed by real message-broker adapters and the
genuine same-transaction outbox (RabbitMQ, Postgres, Kafka) and the global `firefly new` installer. What's
still ahead, accurately:

- **Broker integration spot-runs in CI.** Each broker adapter ships a genuine `@group('integration')`
  round-trip test (RabbitMQ, Postgres, Kafka) gated behind Docker + a live connection string — these run
  on demand, not in the default `vendor/bin/pest`/CI pass; wiring a scheduled/opt-in CI lane for them is
  still open.
- **More actuator endpoints.** `/httpexchanges`, `/caches`, `/configprops`, `/refresh`, `/threaddump`, and
  `/shutdown` are not yet implemented; there is also no second management port (an Octane second-listener
  is one option under consideration).
- **Deeper security surfaces.** An OAuth2 authorization server, OAuth2 client/login, and real IdP adapters
  (Keycloak/Cognito/Entra/internal-db, MFA) are deferred to their own future packages, matching the Java
  Firefly Framework's module topology. Generalising `#[PreAuthorize]` to *any* bean method (today it's
  enforced at the CQRS bus and the controller dispatcher) is a flagged spike, not yet scheduled.
- **Read models / projections as a first-class concept.** The sample's `LedgerProjector` shows the pattern
  today via a plain `#[EventListener]`; a dedicated `firefly/eventsourcing`-style package for event
  sourcing/snapshots/projections is future work, as it is in PyFly.
- **Documentation.** The end-to-end [tutorial](docs/tutorial.md) (EN + ES), the *LaraFly by Example*
  [book](book/README.md) (13 chapters + appendices, EN + ES, PDF + EPUB), and a
  [docs table of contents](docs/README.md) all shipped with the documentation-parity milestone.
  Deeper guides (more recipes, more diagrams) continue to grow from here.

See [CHANGELOG.md](CHANGELOG.md) for the complete, dated history of every shipped milestone.

---

## Firefly Framework Ecosystem

LaraFly is part of the [Firefly Framework](https://github.com/fireflyframework) ecosystem — one programming
model across every runtime:

| Platform | Repository | Status |
|---|---|---|
| **Java / Spring Boot** | [`fireflyframework-*`](https://github.com/fireflyframework) (40+ modules) | Production |
| **Python** | [`fireflyframework-pyfly`](https://github.com/fireflyframework/fireflyframework-pyfly) | Beta (CalVer 26.05+) |
| **PHP / Laravel** | [`fireflyframework-php`](https://github.com/fireflyframework/fireflyframework-php) (this repo) | Active development (CalVer 26.07+) |
| **.NET 9** | [`fireflyframework-dotnet`](https://github.com/fireflyframework/fireflyframework-dotnet) | Beta (CalVer 26.05+) |
| **Rust** | [`fireflyframework-rust`](https://github.com/fireflyframework/fireflyframework-rust) | Active development |
| **Frontend (Angular)** | [`flyfront`](https://github.com/fireflyframework/flyfront) | Active development |
| **GenAI** | [`fireflyframework-genai`](https://github.com/fireflyframework/fireflyframework-genai) | Active development |
| **CLI (Go)** | [`fireflyframework-cli`](https://github.com/fireflyframework/fireflyframework-cli) | Active development |

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.3+ (8.4 recommended) |
| Composer | 2.x |
| Laravel | 13 |
| Git | for cloning and for `firefly new`'s optional `git init` |

---

## Contributing

`fireflyframework-php` is a single monorepo housing every LaraFly package as an independent Composer unit
under `packages/*`, plus `firefly/skeleton` at the top level. Before opening a PR, the full gate must pass:

```bash
composer install
git config core.hooksPath scripts/hooks   # activates the committed pre-push safety guard

composer check                            # pint --test && phpstan analyse && pest && deptrac analyse
composer mono-validate                    # validates every packages/*/composer.json
.venv-docs/bin/mkdocs build --strict      # docs/: link integrity, no orphaned pages
bash scripts/check-no-sensitive-tracked.sh
```

Package boundaries are enforced with **Deptrac**; packages from an already-shipped milestone are treated as
**FROZEN** — further edits to their `src/` are the exception, not the rule. See
[Contributing](docs/contributing.md) for the full guide, including the never-commit list
(`.superpowers/`, `.claude`, `.env`, private key material) enforced by the pre-push guard.

---

## License

Apache-2.0 © Firefly Software Solutions Inc.
