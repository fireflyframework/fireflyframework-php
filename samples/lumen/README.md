# Lumen — Digital Wallet Sample

A DDD-flavoured digital-wallet & ledger service built on LaraFly (the Firefly
Framework for PHP, on Laravel 13). A **Wallet** can be opened, deposited to,
withdrawn from, and transferred between, protecting one core invariant —
**the balance never goes negative** — and modelling money with an exact,
immutable **`Money`** value object (integer minor units + ISO-4217 currency).

Every pattern here is the real, running framework API: hexagonal ports,
`#[Transactional]` command handlers, CQRS command/query buses, domain events
projected through EDA into a read-model ledger, method security on a
sensitive command, and a thin REST layer with RFC-7807 problem-details.

## Layered structure

```
samples/lumen/
├── src/
│   ├── Domain/            # Wallet aggregate + Money value object + domain events
│   ├── Application/       # CQRS: Command/Query + Handler pairs, the LedgerProjector listener
│   ├── Infrastructure/     # WalletRepository port + EloquentWalletRepository adapter + migrations
│   └── Web/                # WalletController (#[RestController]) + validated request DTOs
├── tests/                  # Pest coverage: domain, infrastructure, application, security, web, integration
└── config/firefly.php      # Framework configuration (scan paths)
```

The split mirrors every domain service in the Firefly ecosystem: `Domain`
holds the aggregate and its invariants, `Application` holds the CQRS
use-cases, `Infrastructure` holds the repository adapter, and `Web` exposes
HTTP endpoints — none of which contain business logic of their own.

## What the sample shows

- **Domain-driven aggregate** — `Wallet` protects `balance >= 0` and raises
  `WalletOpened`/`FundsDeposited`/`FundsWithdrawn`/`TransferCompleted` domain
  events on every state change. `Money` is an immutable value object with
  structural equality and exact integer arithmetic.
- **Hexagonal repository** — the application layer depends on the
  `WalletRepository` *port*; `EloquentWalletRepository` is the Eloquent
  *adapter*.
- **CQRS** — write intents (`OpenWallet`, `Deposit`, `Withdraw`, `Transfer`)
  and read intents (`GetBalance`, `GetLedger`) flow through the
  `CommandBus`/`QueryBus` to their `#[CommandHandler]`/`#[QueryHandler]`
  handlers. `#[Transactional]` handlers commit atomically; `TransferHandler`
  debits and credits both wallets inside one boundary, so a failure on either
  leg rolls the whole transfer back — money can neither vanish nor double.
- **Domain events → EDA projection** — a committed command's domain events
  are bridged to the real `InMemoryEventBus`; `LedgerProjector`
  (`#[EventListener]`) turns them into append-only `ledger_entries` rows the
  `GetLedger` query reads back.
- **Method security** — `WithdrawHandler` carries
  `#[PreAuthorize("hasRole('ADMIN') or hasRole('WALLET_OWNER')")]`, enforced
  by `SecurityCommandAuthorizer` at the bus, before the handler ever runs.
- **A thin REST controller** — `WalletController` maps HTTP onto
  commands/queries and dispatches through the bus; a `#[Valid]` request body
  is validated before the DTO is even hydrated, and any business/security
  fault (not-found, overdraw, denied) renders as an RFC-7807
  `application/problem+json` response via the framework's own
  `ProblemDetailsRenderer` — no bespoke error handling in the sample itself.

## REST API

| Method | Path                                    | Purpose                                  |
|--------|------------------------------------------|-------------------------------------------|
| POST   | `/api/v1/wallets`                         | Open a wallet (201)                       |
| POST   | `/api/v1/wallets/{id}/deposit`            | Deposit funds (minor units)               |
| POST   | `/api/v1/wallets/{id}/withdraw`           | Withdraw funds (minor units) — secured    |
| POST   | `/api/v1/wallets/transfers`               | Transfer funds between two wallets        |
| GET    | `/api/v1/wallets/{id}/balance`            | Fetch just the balance                    |
| GET    | `/api/v1/wallets/{id}/ledger`             | Fetch the wallet's projected ledger rows  |

Amounts are in **minor units** (cents): `1500` means €15.00 for an EUR
wallet. The withdraw endpoint requires an authenticated principal carrying
`ROLE_ADMIN` or `ROLE_WALLET_OWNER` in the `SecurityContextHolder` — without
one, it renders a 403 `application/problem+json` response (the endpoint is
secured, not broken).

## Run it

```bash
composer test -- samples/lumen/tests   # the whole sample suite, all green
```

This sample has no standalone entry point of its own — it is a package
inside the LaraFly monorepo, wired into the same Pest/PHPStan/Pint/Deptrac
gates as every other package. Once `firefly/cli`'s `firefly:cache` +
`firefly:serve` are wired into a real application (see `firefly/skeleton`),
the same `WalletController` serves unchanged over HTTP with zero code
changes — only configuration differs between the scan-time dev path this
test harness exercises and the cached zero-reflection production path.

## License

Apache-2.0 © Firefly Software Solutions Inc.
