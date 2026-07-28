## Preface

Enterprise PHP has long meant assembling a service container from one package, a router from another, a validator from a third, and an event dispatcher from a fourth — each with its own configuration idiom, its own conventions, its own way of saying "inject this." Laravel solved a great deal of that fragmentation for web applications, but hexagonal, service-oriented PHP — attribute-driven dependency injection, declarative transactions, CQRS, event-driven architecture, method-level security, production observability — has stayed a matter of taste, folder-naming conventions, and hand-rolled glue.

**LaraFly** changes that. It brings the cohesive, convention-over-configuration experience Spring Boot gave the Java world to PHP 8.3+ and Laravel 13, built *on* Laravel rather than instead of it: the same Eloquent, the same service container, the same `artisan`, now organized by PHP 8 attributes into stereotyped components, a compiled dependency graph, and a deterministic boot pipeline.

This book teaches LaraFly **by example**. Every listing in these pages is not illustrative pseudocode — it is lifted from the framework's own shipped source or from `samples/lumen`, a real, running digital-wallet-and-ledger application that ships in the framework's monorepo, compiles, boots, and passes its own test suite. What you read is what actually works.

### Who This Book Is For

This book is for intermediate PHP developers who are comfortable with Laravel's basics — Eloquent models, routing, the service container, `artisan` — and want to see how a hexagonal, enterprise-shaped architecture is built *on top of* that foundation rather than instead of it. You need no prior exposure to Spring, CQRS, or event-driven architecture; each idea is introduced from first principles before the code that implements it.

Developers coming from Spring Boot, Micronaut, or Quarkus will feel especially at home. Wherever a LaraFly concept mirrors a Laravel-native one — a `#[Service]` next to a plain class bound in a service provider, a `#[RestController]` next to a plain Laravel controller — a **Laravel parity** callout draws the comparison explicitly, so you can map what you already know onto what is new.

### What You Will Build

Every chapter advances **Lumen**, a digital-wallet-and-ledger service: a `Wallet` can be opened, deposited to, withdrawn from, and transferred between other wallets, protecting one invariant above all others — **the balance never goes negative** — and recording every state change as a domain event that a listener projects into an append-only ledger. It is a small system, but it is shaped exactly like a real one: a domain layer with no framework dependency, a hexagonal port and its Eloquent adapter, CQRS command and query handlers, domain events bridged to an event bus, method-level security on a sensitive operation, and a thin REST controller that holds no business logic of its own.

The journey starts gently. The **Quick Start** takes you from an empty `composer create-project` to a running, curl-able endpoint, previewing the stereotypes, the container, and the compiled boot path in miniature before any chapter asks you to reason about them. **Chapter 1** steps back and makes the case for the whole approach — what problem LaraFly solves and the pillars it stands on. **Chapter 2** opens the engine room: the dependency-injection container, the stereotype attributes, and the component scan that compiles your annotated classes into a cached, zero-reflection boot manifest. Later parts of this book — arriving in the chapters that follow — build outward from that foundation into configuration, HTTP, persistence, domain modelling, CQRS, event-driven architecture, security, and observability, always through the same `Lumen` codebase, always with code you can run.

By the time you have worked through the full book, you will have a mental model for every layer of a production LaraFly service, and a real, tested application to show for it.

### How to Use This Book

**Read sequentially.** Each chapter builds on the vocabulary and the code of the one before it.

**Type the listings yourself.** Reading and typing code at the same time is how the patterns stick. Resist copy-pasting until you have written each listing at least once by hand.

**Run it.** Every listing in this book is real, and most of it lives, unchanged, in the framework's own `samples/lumen` package. Clone the framework's repository, and you can run the finished application — `composer test -- samples/lumen/tests` exercises the whole sample suite — and compare your own work against it at any point.

Each chapter closes with a short **Summary** of what you learned and, where it applies, a **Try it yourself** section that pushes one step further on your own.

### Conventions in Brief

Typographic and structural conventions — code-listing style, callout types, and figure numbering — are demonstrated, with live examples, in the **Conventions** section that follows this preface.

### The Companion Code

The finished `Lumen` application lives in the framework's monorepo at `samples/lumen`. It is the destination this book walks you toward: a layered project — `Domain`, `Application`, `Infrastructure`, `Web` — that the later chapters of this book grow, feature by feature. Use it to compare your own code, to catch up if a chapter moves faster than you expect, or simply to run the parts you are currently reading about.
