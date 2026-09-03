# Bean Graph

The bean graph is the one page of the [admin dashboard](admin.md) that is more than a table: a layered, drawn
diagram of how your beans depend on one another, at `/firefly/graph`.

It answers a question `/actuator/beans` cannot. That endpoint tells you *which* beans exist; the graph tells you
what each one is **wired to**, which is what you actually want when a `#[ConditionalOnMissingBean]` did not fire the
way you expected, when a cycle has hung a boot, or when you are trying to work out what a package you just
installed attached itself to.

```
composer require firefly/admin      # the graph is a page of the dashboard, not a package of its own
```

## Where the edges come from

Nothing is reflected at request time. `ComponentScanner` records, at **scan** time, the class and interface types
each component's constructor asks for, and `ComponentDescriptor::$dependencies` carries them through the compiled
manifest exactly like every other scanned fact:

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

`ActuatorRouteRegistrar` snapshots the condition-filtered registry into `BeansCatalog` at boot, and `BeanGraph`
turns that catalogue into nodes and edges. A `string $name` parameter is configuration, not wiring, and is not an
edge. A **nullable or defaulted** class parameter *is* an edge — an optional collaborator is still a relationship.

The field is declared last with a default, so a manifest compiled before it existed still rehydrates; an app
running on an old `bootstrap/cache/firefly/` gets a graph of nodes with no edges until the next `firefly:cache`.

## The hard part is not drawing, it is resolving

A constructor asks for a **type**, and that type is very often an interface — `EventPublisher`, `HealthIndicator`,
`Cache` — while the bean that satisfies it is a concrete class that merely implements it. An edge list built
naively from constructor types therefore points at nodes that do not exist, and the graph comes out as a field of
disconnected dots.

Every dependency is resolved through an interface index first, so `PostgresEventPublisher` is what `EventPublisher`
actually links to. The edge is then marked **`via`** with the interface it went through, so the reader can see the
indirection rather than being quietly shown something they did not write. The **Relations** table under the diagram
has a *Wired by* column that spells it out for every edge: the interface name, or the literal `class` when the
constructor named the concrete type.

The index is built in catalogue order and **first implementor wins**, deterministically — the catalogue is emitted
in scan order, so the same application always draws the same graph. An interface with several implementors is a
real ambiguity that the container resolves with `#[Primary]`/`#[Qualifier]`, and the graph says so by listing the
edge as `via` rather than pretending the choice was obvious.

## Layers, and why arrows read downward

Level assignment is a **longest-path** walk over the resolved edges: a node's depth is one more than the deepest
thing it depends on, and the levels are then flipped so that level 0 holds the things nothing depends on. The
result is that a node always sits below everything that depends on it, arrows flow consistently downward, and the
eye can follow a chain from a controller to the repository at the bottom of it.

Depth is memoised, and the walk carries its own visited set, so a **cycle terminates instead of recursing
forever** — and the edge that closed it is reported rather than swallowed.

## Cycles are reported as a fact about your application

When the walk finds one, a *Circular dependencies* panel appears above the diagram listing every closing edge, and
the **Cycles** stat turns red.

This is worth more than it looks. The container has no cycle detection of its own, so a cycle among eager
singletons does not produce a helpful error — it exhausts memory at boot. A page that names the two classes
involved turns "the app died with no message" into a five-second diagnosis. The page's own advice is the right
one: break one of the edges, usually by injecting an interface and letting the other side depend on that.

## The panels

| Panel | Shows | Notes |
|---|---|---|
| Stats | Beans, Relations, Layers, Cycles, Unresolved | Cycles renders as a red chip when non-zero |
| Circular dependencies | Every closing edge, `from` → `depends on` | Only rendered when there is at least one |
| Wiring | The layered SVG diagram | Filter box highlights a bean by name |
| Relations | Every edge as `Bean` / `Depends on` / `Wired by` | Always rendered, filterable — this is the fallback when the diagram is suppressed |
| Provided outside the container | Constructor types nothing in the container provides | Chips, with the full type as a tooltip |

Each node is a rounded box carrying the bean's short class name, its stereotype, and its in/out degree (`3↑ 1↓`);
its `<title>` carries the fully-qualified class, scope and both degrees, so hovering identifies it exactly. Edges
are cubic Bézier curves with an arrow marker; an edge that went through an interface carries a `<title>` reading
`via <Interface>`.

The diagram is plain inline SVG generated server-side — no JavaScript graph library, no layout engine, no network
request. It is the same "no npm step, no CDN" rule the [rest of the dashboard](admin.md#no-build-step) follows.

## Two honest limits

**Past 220 nodes the diagram is suppressed.** The ceiling is `firefly.admin.graph.max-nodes`, default `220`; above
it the Wiring panel says so and the Relations table below carries the same information as a filterable list. A
diagram past a couple of hundred nodes is a hairball, not something a person can read, and rendering one anyway
would be a worse answer than declining to. It is configurable because "unreadable" depends on the screen and the
application — raise it to draw a bigger graph anyway, or set it to `0` to always get the list.

**"Provided outside the container" is not a warning.** Those are constructor types satisfied by a Laravel container
binding rather than a scanned bean — the `Request`, the config repository, a database connection, a framework
contract. They are listed rather than silently dropped precisely because *"why is my bean not in the graph"* is the
question this page has to be able to answer. A type appearing there is usually correct; a type appearing there that
you expected to be a bean of yours means your scan did not see it, and `firefly.scan.paths` is the first thing to
check.

## Reading it against the Conditions page

The graph and [Conditions](admin.md#the-thirteen-pages) answer complementary questions, and the pair is the fastest
way to diagnose an auto-configuration surprise:

1. **Conditions** says *whether* a framework bean was registered or backed off, and on which condition.
2. **The graph** says what the bean that *did* win is wired to, and through which interface.

An `EventPublisher` edge pointing at `InMemoryEventPublisher` when you configured `firefly.eda.provider=rabbitmq`
is visible in one glance on the graph, and Conditions then tells you which `#[ConditionalOnProperty]` did not match.

## Known-latent

- **`#[Bean]` factory-method parameters are not drawn.** `BeansCatalog` already publishes them (under `produces`,
  recorded on each `BeanDescriptor`), but `BeanGraph` builds edges from constructor `dependencies` only. So a
  `#[Configuration]` class appears as a node with the edges *its own constructor* declares, and the wiring its
  `#[Bean]` methods perform is not yet drawn. This under-draws framework auto-configuration classes specifically,
  and not application `#[Service]`/`#[Repository]` beans, which wire through constructors.
- **`#[Primary]`/`#[Qualifier]` do not steer the interface index.** First implementor in scan order wins. The edge
  is marked `via` so the indirection is visible, but on an interface with several implementors the drawn target may
  not be the one the container resolves.
- **No layout beyond layering.** Nodes are centred within their level in the order they came out of the sort
  (`level`, then label); there is no crossing-minimisation pass, so a dense graph has crossing edges.

---

See also: [Admin Dashboard](admin.md) for the page's access model, [Dependency Injection](dependency-injection.md)
for what the stereotypes and scopes on each node mean, and [Auto-Configuration](starters.md) for the conditions
that decided which beans exist at all.
