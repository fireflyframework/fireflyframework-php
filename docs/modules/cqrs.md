# CQRS (Command / Query)

`firefly/cqrs` adds the command/query **dispatch** surface — a synchronous, in-process mediator — on
top of the shipped seams. It writes no interception code: a `#[Transactional]` command handler gets
transaction semantics for free from the firefly/data proxy. Read models / projections are a future
`firefly/eventsourcing` package.

## The buses

- `CommandBus::send(object $command): mixed` — the write side.
- `QueryBus::ask(object $query): mixed` — the read side (`ask`, not `query`, to avoid colliding with
  Eloquent's `query()`).

Both run a bounded pipeline of injected, no-op-by-default collaborators:

- **Command:** correlate → validate → authorize → resolve + invoke handler → metrics.
- **Query:** correlate → validate → authorize → cache-get (short-circuit on hit) → resolve + invoke
  handler → cache-put → metrics.

Any handler/stage throwable is re-thrown **wrapped** in a category-preserving
`CommandProcessingException` / `QueryProcessingException` (unless it already is one). "Category-preserving"
means a `ValidationException` (422) or a not-found (500 framework) keeps its own HTTP status / category /
severity for RFC-7807 rendering — the wrapper never masks an expected fault to a generic 500.

## Handlers

Annotate a class with `#[CommandHandler]` or `#[QueryHandler]` (both specialise `#[Component]`, so the
handler is a constructor-injected DI bean automatically — no separate `#[Service]`). The handler exposes
one public `handle()` method taking the message and returning the result:

```php
#[CommandHandler]
final class OpenAccountHandler
{
    public function __construct(private readonly AccountRepository $accounts) {}

    #[Transactional]
    public function handle(OpenAccount $command): int
    {
        $account = Account::open($command->owner, $command->balance);
        $this->accounts->save($account);

        return $account->id;
    }
}
```

The handled message type is inferred from the `handle()` parameter at scan time (mirroring
`#[AsEventListener]`); pass an explicit class to disambiguate a union/`object` parameter:
`#[CommandHandler(OpenAccount::class)]`. An unresolvable type or a duplicate handler fails loud
(`CqrsConfigurationException`) at build/boot, never silently. `HandlerScanner` (the sole reflection site in
the package) discovers handlers and compiles them into a `HandlerManifest`; `CqrsHandlerWiringPass`
populates the `HandlerRegistry` from that manifest at boot with fresh-per-dispatch invokers (so a
`#[Transactional]` handler is always observed through its proxy).

The marker interfaces `Command`/`Query`/`CommandHandler`/`QueryHandler` are optional documentary
type-markers — discovery is by attribute, and PHP parameter invariance means the handler interfaces
declare no `handle()` method.

## Pipeline seams

All default to no-ops so the pipeline is a series of skippable collaborators:

- **Validation** — reuse the shipped `Validator`: a message implementing `Validatable`
  (`validationData()` / `validationRules()`) is validated; others are not.
- **Authorization** — interface-only seam (`CommandAuthorizer` / `QueryAuthorizer`), allow-all default;
  real policy evaluation is M11-security.
- **Correlation** — `CorrelationContext` propagates a uuid4 correlation id, restored after each dispatch
  and stamped into integration-event headers (`x-correlation-id`).
- **Metrics** — `CqrsMetrics` seam (no-op default); the real recorder is M12-observability.
- **Query cache** — `QueryCache` seam (`NoOpQueryCache` = always-miss) consulted for a `Cacheable` query;
  a real adapter lands with `firefly/cache`.

## The domain → integration-event bridge

The bridge re-emits every **committed** in-process `DomainEvent` onto the firefly/eda `EventPublisher`
as an integration event, mapping `eventType` = the event's short class name, `payload` = the event's
public fields, and `destination` = an explicit argument → the event's `#[PublishDomainEvent(destination:)]`
→ `firefly.cqrs.default_destination` (`cqrs.events`).

**How it triggers:** firefly/data publishes each committed `DomainEvent` to the in-process
`ApplicationEventPublisher` inside `DB::afterCommit`. `DomainEventBridgeWiringPass` registers a single
**guarded wildcard listener** on the event dispatcher that filters `instanceof DomainEvent` and delegates
to `DomainEventBridge`, which applies the failure strategy and publishes through the resolved
`CommandEventPublisher` (`NoOpEventPublisher` or `EdaCommandEventPublisher`). (It does NOT use
`#[AsEventListener]` typed on `DomainEvent`: Laravel's dispatcher matches an object event by its concrete
class plus interfaces — never parent classes — so a base-class listener would never fire for a concrete
subclass.)

**Semantic scope (read this):** the bridge fires for **any** aggregate committed through a firefly/data
repository — not only writes originating from a `CommandBus` command. This is deliberate and correct
integration-event semantics: an integration event is published because a fact was committed to the write
model, regardless of whether a CQRS command, a controller, a scheduled job, or a message consumer drove
the mutation. This is also the deliberate improvement over pyfly, which drains events by inspecting the
current handler's aggregate (duck-typing on the command-handling call) rather than listening for any
committed aggregate. An app that wants to emit an integration event WITHOUT an in-process domain event
injects `CommandEventPublisher` directly; an app that wants a domain event to stay in-process only does not
enable the bridge (or scopes destinations via `#[PublishDomainEvent]`).

**Failure strategy** (`firefly.cqrs.event_failure_strategy`): the publish runs AFTER the DB commit, inside
`DB::afterCommit`, so the write already succeeded. `log` (default) swallows + logs a publish failure — the
command result stands, the publish is best-effort; `raise` re-throws it wrapped in
`CommandProcessingException`. Either way, the bridge fires only on a real commit — a rolled-back unit of
work raises no `DomainEvent` at all (Laravel discards `afterCommit` callbacks on rollback), so nothing is
ever published for it.

**Non-atomic caveat:** the DB commit and the broker publish are two steps with a gap — an honest
at-least-once-ish, non-atomic guarantee. True exactly-once durability is the SP-4 transactional outbox
(`firefly/eda-postgres`), which hangs off the same `DB::afterCommit` hook + a Postgres advisory lock.

When no firefly/eda `EventPublisher` is bound, the bridge falls back to `NoOpEventPublisher` (drops
silently), so cqrs installed without eda still boots and simply emits no integration events.

`firefly/cqrs` is an independent layer that does **not** depend on `firefly/data` (no `Cqrs → Data` Deptrac
edge): the bridge listens for `DomainEvent`s on the in-process `Context` dispatcher, never on `firefly/data`'s
`AggregateTracker`/`DomainEventDispatcher` directly.

## Configuration (`firefly.cqrs.*`, snake_case)

| Key | Default | Meaning |
|---|---|---|
| `firefly.cqrs.default_destination` | `cqrs.events` | Fallback integration-event destination. |
| `firefly.cqrs.event_failure_strategy` | `log` | `log` (swallow) or `raise` (re-throw) on a post-commit publish failure. |
| `firefly.cqrs.query.cache_ttl` | _(unset)_ | Reserved for the real query cache (firefly/cache). |
| `firefly.cqrs.enabled` | `true` | Reserved — the auto-configuration is always-on in v1. |

## Laravel comparison

| Concern | Plain Laravel | LaraFly (`firefly/cqrs`) |
|---|---|---|
| Command/query dispatch | `dispatch()` a job, or call a service directly; no read/write split | `CommandBus::send` / `QueryBus::ask` with attribute-discovered handlers + a bounded pipeline |
| Handler discovery | manual binding / `$dispatchesJobs` | `#[CommandHandler]`/`#[QueryHandler]` compiled to a manifest, zero boot reflection |
| Transactions | `DB::transaction(fn () => …)` in the handler body | `#[Transactional]` on `handle()` — interception is free (firefly/data proxy) |
| Domain → integration events | hand-rolled (dispatch a job in the model event) | committed `DomainEvent`s auto-re-emitted onto the eda `EventPublisher` after commit |

## Known-latent

Read-model / projection scaffolding (→ `firefly/eventsourcing`), real authorization (→ M11), real query
cache (→ firefly/cache), CQRS metrics + health (→ M12), attribute-driven command validation, the fluent
builder / distributed-tracing ergonomics, and exactly-once outbox durability (→ SP-4) are deferred and
documented honestly. As with `#[Transactional]`/`#[EventListener]`, **app-level `HandlerManifest` compilation
via `firefly:cache` lands in M15** — until then an application (or its test suite) supplies its compiled
manifest inline (run `HandlerScanner::scan()` + bind the resulting `HandlerManifest`) rather than through an
automated cache-warm command; a handler absent from the bound manifest simply never registers. Under
Octane, `CorrelationContext` is per-request state and the `HandlerRegistry` is rebuilt per worker boot.
