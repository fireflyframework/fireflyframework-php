# Bean Graph

The bean graph is the one page of the [admin dashboard](admin.md) that is more than a table: a layered, drawn
diagram of how your beans depend on one another, at `/firefly/graph`.

It answers a question `/actuator/beans` cannot. That endpoint tells you *which* beans exist; the graph tells you
what each one is **wired to**, which is what you actually want when a `#[ConditionalOnMissingBean]` did not fire the
way you expected, when a cycle has hung a boot, or when you are trying to work out what a package you just
installed attached itself to.

```
composer require firefly/admin      # already required by firefly/firefly; the graph is a page of the dashboard, not a package of its own
```

## What counts as a node

A LaraFly application has **three kinds of bean**, and all three are nodes:

| Kind | What it is | Where the node comes from |
|---|---|---|
| `component` | A scanned `#[Component]`/`#[Service]`/`#[Repository]`/`#[RestController]`/`#[Configuration]` class | The beans catalogue |
| `bean` | A **value produced by a `#[Bean]` factory method** on a `#[Configuration]` | The `produces` rows of the catalogue |
| `config` | A `#[ConfigProperties]` DTO bound from configuration | The `configprops` endpoint |

That list is the whole design, and it is worth saying why, because the first version of this page only knew about
the first kind and was therefore *structurally incapable* of showing framework wiring.

A framework's wiring lives almost entirely in the second kind. An auto-configuration is a `#[Configuration]` whose
`#[Bean]` methods produce `MeterRegistry`, `TransactionTemplate`, `AggregateTracker` and so on. When only declaring
classes were nodes, every edge pointing at one of those products pointed at a node that did not exist. Measured on
a stock skeleton: **42 nodes, 41 `#[Bean]` products missing, 21 dangling dependencies, and exactly one edge drawn.**
The graph was not sparse — it was a field of disconnected dots with the mechanism removed.

The third kind is a smaller version of the same mistake. A `#[ConfigProperties]` DTO is bound and injectable but is
neither scanned as a component nor produced by a factory, so nothing in the beans catalogue can see it: it showed
up as an *unresolved dependency* of the service that injects it rather than as the bean it is. It is read from the
`configprops` endpoint alongside the catalogue for exactly that reason.

### The identity of a `#[Bean]` product

Usually the produced **type** is the identity, because that is the key the container binds and the key every
consumer asks for. `MeterRegistry` is the node; the `#[Configuration]` that made it is recorded on the node as a
detail (`ObservabilityAutoConfiguration::meterRegistry()`), not as its name.

When **two factory methods produce the same type** — the shape that requires `#[Primary]`/`#[Qualifier]` to
disambiguate — the type alone would collapse them into one node and hide exactly the ambiguity the reader came to
look at. So each competitor gets `Declaring::method()` as its id, and the bare type resolves to the first of them.
That mirrors the container itself, where the type key aliases the winner and every candidate stays reachable by
name.

## What counts as an edge

Two kinds, and they mean different things:

| Edge | From → to | Meaning |
|---|---|---|
| `injects` | A bean → something it declared a dependency on | The consumer asked for it; the container satisfies it |
| `produces` | A `#[Configuration]` → the value one of its `#[Bean]` methods returns | This class is where that bean comes from |

`injects` edges are drawn for a component's **constructor** parameters *and* for a `#[Bean]` **factory method's**
parameters — the product depends on what its factory asked for. That union is where a framework's wiring actually
lives, and a graph built from constructors alone draws almost nothing.

Nothing is reflected at request time to work any of this out. `ComponentScanner` records the types at **scan** time
and they ride the compiled manifest exactly like every other scanned fact:

```php
/**
 * The class types this component's constructor asks for — the edges of the bean graph.
 *
 * Recorded at scan time, where reflection is already sanctioned, because the alternative is
 * reflecting at request time to answer "what depends on what", which the reflection-free boot
 * contract forbids. Only CLASS and INTERFACE types are kept: a scalar or a builtin is configuration,
 * not a wiring edge, and putting it in the graph would drown the edges that matter.
 */
public array $dependencies = [],
```

A `string $name` parameter is configuration, not wiring, and is not an edge. A **nullable or defaulted** class
parameter *is* an edge — an optional collaborator is still a relationship.

## Why an edge through an interface is labelled with that interface

A constructor asks for a **type**, and that type is very often an interface — `EventPublisher`,
`HealthIndicator`, `Cache` — while the bean that satisfies it is a concrete class, or the return of a factory
method. An edge list built naively from declared types therefore points at nodes that do not exist.

So every dependency is resolved through an index of *what satisfies what* — a component's `interfaces`, and every
`#[Bean]` method's produced type — before it becomes an edge. `PostgresEventPublisher` is what `EventPublisher`
links to.

The edge then records the interface it went through, in a member called **`via`**, and both surfaces show it: the
diagram's edge `<title>` reads `Consumer → Target (via EventPublisher)`, and the **Relations** table has a
*Wired by* column naming the interface, or `—` when the constructor named the concrete type.

That label is the honesty in the whole page. Without it the reader is shown a relationship they never wrote —
`WalletService → EloquentWalletRepository` is *true*, but what they wrote was `WalletRepository`, and the gap
between the two is precisely where a mis-wiring hides. With it, the indirection is visible and the port they
depend on is named.

The index is built in catalogue order and **first implementor wins**, deterministically — the catalogue is emitted
in scan order, so the same application always draws the same graph rather than reshuffling between machines. An
interface with several implementors is a real ambiguity that the container resolves with `#[Primary]`/`#[Qualifier]`,
and the graph says so by listing the edge as `via` rather than pretending the choice was obvious.

## Layers, and why arrows read downward

Level assignment is a **longest-path** walk over the resolved edges: a node's depth is one more than the deepest
thing it depends on, and the levels are then flipped so that level 0 holds the things nothing depends on. The
result is that a node always sits below everything that depends on it, arrows flow consistently downward, and the
eye can follow a chain from a controller to the repository at the bottom of it.

Within a level the nodes are **clustered by module** — the first two namespace segments, `Firefly\Observability`,
`App\Http` — so related things end up adjacent rather than scattered, and each module gets a stable colour assigned
by position, with a legend whose entries toggle. Hues are kept away from the red and green the rest of the
dashboard reserves for status.

Each level is then **wrapped into a grid of its own** rather than laid out as one row. A pure layered layout is
wrong for this graph: dependency depth is shallow and wide, so most beans land on one or two levels — a stock
skeleton produced a single row 54 nodes and 9184px across, which the fit-to-view control then scaled to 11%, i.e.
unreadable. Wrapping keeps the drawing a compact rectangle while arrows still read downward from dependents to
dependencies.

## Cycles are reported, not fatal

Depth is memoised and the walk carries its own visited set, so a **cycle terminates instead of recursing forever**.
The edge that closed it is collected, and when the walk finds one a *Circular dependencies* panel appears above the
diagram listing every closing edge, with the **Cycles** stat turned red.

Reporting rather than throwing is the deliberate choice, and it is worth being explicit about why. A cycle is a
fact about *your application*, not a malfunction of the page that drew it — and the page is very often the only
thing that can tell you. The container has no cycle detection of its own, so a cycle among eager singletons does
not produce a helpful error: it exhausts memory at boot. If this page refused to render on finding one, the single
tool capable of naming the two classes involved would go dark at exactly the moment you needed it, and you would be
back to a process that died with no message.

So it renders, draws everything else, and names the closing edges. The panel's own advice is the right one: break
one of these edges, usually by depending on an interface and letting the other side provide it.

## The panels

| Panel | Shows | Notes |
|---|---|---|
| Stats | Beans, Components, `#[Bean]` products, Config DTOs, Relations, Layers, Cycles | Cycles renders as a red chip when non-zero |
| Circular dependencies | Every closing edge, `Bean` → `Depends on` | Only rendered when there is at least one |
| Wiring | The layered SVG diagram — module legend with toggles, a find box, Fit/Reset controls, drag-to-pan and scroll-to-zoom, and an inspector panel showing a selected bean's dependencies and dependents | Suppressed past the node ceiling |
| Relations | Every edge as `Bean` / `Depends on` / `Wired by` | Always rendered, filterable — the fallback when the diagram is suppressed |
| Provided outside the container | Declared types nothing in the container provides | Chips, with the full type as a tooltip |

Each node is a rounded box carrying the bean's short name, its kind, and its in/out degree; its `<title>` carries
the fully-qualified identity and, for a `#[Bean]` product, the factory method that produced it. Edges are curves
with an arrow marker, styled by edge type, and an edge that went through an interface carries the interface in its
title.

The diagram is plain inline SVG generated server-side — no JavaScript graph library, no layout engine, no network
request. It is the same "no npm step, no CDN" rule the [rest of the dashboard](admin.md#no-build-step) follows.

## Two honest limits

**Past 220 nodes the diagram is suppressed.** The ceiling is `firefly.admin.graph.max-nodes`, default `220`; above
it the Wiring panel says so and the Relations table below carries the same information as a filterable list. A
diagram past a couple of hundred nodes is a hairball, not something a person can read, and rendering one anyway
would be a worse answer than declining to. It is configurable because "unreadable" depends on the screen and the
application — raise it to draw a bigger graph anyway, or set it to `0` to always get the list.

**"Provided outside the container" is not a warning.** Those are declared types satisfied by a Laravel container
binding rather than a bean — the `Request`, the config repository, a database connection, a framework contract.
They are listed rather than silently dropped precisely because *"why is my bean not in the graph"* is the question
this page has to be able to answer. A type appearing there is usually correct; a type appearing there that you
expected to be a bean of yours means your scan did not see it, and `firefly.scan.paths` is the first thing to
check.

## Reading it against the Conditions page

The graph and [Conditions](admin.md#the-pages) answer complementary questions, and the pair is the fastest
way to diagnose an auto-configuration surprise:

1. **Conditions** says *whether* a framework bean was registered or backed off, and on which condition.
2. **The graph** says what the bean that *did* win is wired to, and through which interface.

An `EventPublisher` edge pointing at `InMemoryEventPublisher` when you configured `firefly.eda.provider=rabbitmq`
is visible in one glance on the graph, and Conditions then tells you which `#[ConditionalOnProperty]` did not match.

## Known-latent

- **`#[Primary]`/`#[Qualifier]` do not steer the index.** First writer in scan order wins, both for an interface
  with several implementors and for the bare type key of a contested `#[Bean]`. Every competitor still gets its own
  node and the edge is marked `via`, so the ambiguity is visible — but the drawn target may not be the one the
  container resolves.
- **No crossing minimisation.** Nodes are ordered within a level by module and then label, and levels are wrapped
  into grids; there is no pass that reorders them to reduce edge crossings, so a dense graph has crossing edges.
- **An unresolved type is reported, never explained.** The page can say a type is provided outside the container;
  it cannot say by *which* binding, because a Laravel container binding carries no descriptor to read.

---

See also: [Admin Dashboard](admin.md) for the page's access model, [Dependency Injection](dependency-injection.md)
for what the stereotypes and scopes on each node mean, and [Auto-Configuration](starters.md) for the conditions
that decided which beans exist at all.
