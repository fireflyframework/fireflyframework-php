<span class="eyebrow">Part I — Foundations · Chapter 1</span>

# Why LaraFly? {.chtitle}

By the end of this chapter you will understand the problem LaraFly exists to solve, the seven pillars its architecture rests on, and how each one builds on Laravel rather than replacing it — so that when Chapter 2 opens the dependency-injection container, you already know *why* it works the way it does.

---

## The cohesion problem

Picture the first day of a new PHP microservice. Laravel itself answers most of the obvious questions — routing, the ORM, the service container, the queue — and answers them well. But the moment the service needs to be more than a CRUD-and-Blade application, a second layer of questions appears, and Laravel deliberately leaves them to you.

How do you keep business rules out of your Eloquent models and your controllers? Where does a command handler live, and how does it get dispatched? How do you guarantee a database write and the domain event it raises either both happen or neither does? How do you publish that event to other services without hand-rolling a queue job for every one? How do you lock down one specific operation to one specific role, independent of route middleware? How do you know, in production, whether the service is actually healthy?

Every team answers these questions differently — a folder-naming convention here, a base-repository class there, a home-grown "service" layer with no shared shape from one project to the next. Six months later, a second team starts a second service and makes entirely different choices. Now two codebases share a language and a framework but nothing else: different testing strategies, different error-handling conventions, no shared mental model of how anything is wired together.

**Laravel gives PHP a superb foundation. It does not, by itself, give you a shared architecture for the layer above that foundation.**

Java developers solved the equivalent problem years ago with Spring Boot: one opinionated, cohesive framework, built *on* the JVM rather than replacing it, that makes the hard architectural decisions once and lets every team share the same answer. **LaraFly brings that same discipline to PHP, built on Laravel 13.**

---

## What LaraFly is

LaraFly is not a replacement for Laravel — it is a framework built *on* it, in the same way Spring Boot is built on the JVM and plain Java EE conventions. Every LaraFly application is a real Laravel application: the same Eloquent models, the same service container underneath, the same `artisan` command line, the same HTTP kernel and middleware pipeline. What LaraFly adds is a second, higher-level layer of PHP 8 **attributes** that describe *intent* — "this class is a service," "this method must run in a transaction," "this command handler requires the `ADMIN` role" — and a boot-time engine that reads those attributes once, compiles them into a manifest, and wires the resulting application deterministically.

You already saw a small piece of this in the Quick Start: `#[Service]` on a plain class was enough to make it injectable, with no manual binding. That single attribute is the smallest example of a pattern that repeats, at increasing depth, across the rest of this book: **declare what a class is, and let the framework work out what to do about it.**

!!! laravel "Laravel parity"
    Nothing about LaraFly asks you to abandon what you already know. A `#[Service]` bean still ends up as an ordinary object the Laravel container resolves; a `#[RestController]`'s route still runs through Laravel's own HTTP kernel and middleware; `#[Transactional]` (Chapter 8) still calls Laravel's own `DB::beginTransaction()`/`commit()`/`rollBack()` underneath. LaraFly's attributes describe *what to wire*; Laravel still does the wiring's actual work.

---

## Seven pillars

LaraFly's architecture rests on seven ideas. Each one is a real, independent package in the framework's monorepo — none of them requires the others — and each is the subject of one or more later chapters.

### 1. Attribute-driven DI and auto-configuration

`#[Service]`, `#[Repository]`, `#[Component]`, and `#[Configuration]`/`#[Bean]` mark a class or a factory method as a managed part of the application. A **component scan** — a one-time reflection pass — discovers every one of them and compiles the result into a cached PHP manifest. At runtime, nothing is scanned again: the container is built from that frozen manifest, which is what makes LaraFly safe and fast under long-running workers, not just classic PHP-FPM. This is the whole subject of Chapter 2.

### 2. `#[Transactional]` — declarative transaction demarcation

A method carrying `#[Transactional]` gets a generated proxy, built at compile time, that opens a real Laravel database transaction before the method runs and commits or rolls it back afterward — using manual `DB::beginTransaction()`/`commit()`/`rollBack()` rather than `DB::transaction($closure)`, specifically so a caught exception can be committed-and-rethrown when your own rollback rules say it should be, instead of always rolling back. You write the business method; the proxy carries the transaction boundary.

### 3. CQRS — commands, queries, and a bus

Writes and reads travel two separate paths. A `#[CommandHandler]` handles one write intent; a `#[QueryHandler]` answers one read; both are dispatched through a bus rather than called directly. A `#[Transactional]` command handler gets its transaction semantics for free from the same proxy pillar 2 describes — CQRS adds no interception logic of its own.

### 4. Event-driven architecture, with a genuine outbox

An aggregate raises domain events describing what happened; after a commit, those events can be projected in-process or bridged out to `firefly/eda`'s broker-backed `EventPublisher` — in-memory for tests, a Laravel queue for async delivery, or a real message broker (Kafka, RabbitMQ, or Postgres `LISTEN`/`NOTIFY`) for cross-service delivery. The Postgres adapter in particular writes the outbox row in the *same* database transaction as the aggregate itself, so an event can never be recorded without the write it describes actually having committed.

### 5. Security

A Spring-Security-6-shaped principal model — an immutable `Authentication`, a `SecurityContext`, `GrantedAuthority` — backs a deny-by-default `HttpSecurity` URL configuration and method-level guards like `#[PreAuthorize]`, enforced at the CQRS bus and at controller dispatch, independent of route middleware.

### 6. Actuator — a production management surface

`/actuator/health`, `/actuator/info`, and friends, modelled directly on Spring Boot Actuator: a `HealthIndicator` SPI, liveness/readiness probe groups, and a route-registration pass that mounts these endpoints with zero code changes to your own application — configuration alone decides what is exposed.

### 7. Zero-reflection boot

Every one of the previous six pillars is discovered by reflection **exactly once** — at `firefly:cache` time, not at request time. The compiled manifests that scan produces are what a running application actually boots from. This is the thread that ties the whole framework together, and it is where Chapter 2 begins.

::: figure art/figures/boot-pipeline.svg | Figure 1.1 — Every Firefly package contributes a boot pass; the kernel alone decides the order they run in.

---

## Why build Lumen

The rest of this book teaches these seven pillars by building one real application, **Lumen**, a digital-wallet-and-ledger service — the same one you glimpsed at the end of the Quick Start. A wallet can be opened, deposited to, withdrawn from, and transferred between other wallets, and it protects exactly one rule above all others: **the balance never goes negative**. That single rule is small enough to hold in your head completely, which leaves your attention free for how each pillar is built, rather than for what the domain means.

Every listing you will read from here on is not illustrative — it is copied from the real `samples/lumen` package that ships in the framework's monorepo, and it lints, compiles, and passes its own test suite exactly as printed.

!!! note "Note"
    Lumen already exists, fully built, in `samples/lumen` — this book does not modify it. Instead, each chapter *explains* one more slice of it, in the order that makes the underlying ideas clearest, so that by the time you have read the whole book you understand every line of a real, production-shaped service.

---

## What you learned {.recap}

| Pillar | What it gives you | Where it's covered |
|---|---|---|
| Attribute-driven DI & auto-configuration | Stereotyped beans, constructor injection, a compiled manifest | **Chapter 2** |
| `#[Transactional]` | Declarative transaction boundaries via a generated proxy | Later chapters |
| CQRS | A command/query bus separating writes from reads | Later chapters |
| EDA + genuine outbox | Domain events bridged to in-memory, queue, or broker delivery | Later chapters |
| Security | Deny-by-default HTTP security + method-level guards | Later chapters |
| Actuator | A production health/info/management surface | Later chapters |
| Zero-reflection boot | One compile step, then a frozen manifest at runtime | **Chapter 2** |

---

## Try it yourself {.exercises}

1. **Read the pitch in one sitting.** Skim `samples/lumen/README.md` end to end. For each bullet point under "What the sample shows," name which of the seven pillars above it belongs to.
2. **Find the seam.** Open `skeleton/config/firefly.php` again and locate `scan.paths`. Every one of the seven pillars ultimately depends on that one array being correct — write, in your own words, what would happen if it pointed at the wrong namespace.
3. **Compare architectures.** If you have built a service in plain Laravel before, list three architectural decisions you made by convention (where commands live, how you handled transactions, how you secured one sensitive endpoint). You will revisit each one, built the LaraFly way, in a later chapter.
