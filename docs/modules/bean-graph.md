# Bean Explorer

The [admin dashboard](admin.md) exposes the bean explorer at `/firefly/graph`. It starts with search,
entry points, heavily depended-on beans, module coupling and wiring cycles. Selecting a bean draws a
bounded neighbourhood; the application size never disables exploration. All navigation uses GET forms
and ordinary links, including when JavaScript is disabled.

## Four states, one URL

| URL | What it shows |
|---|---|
| `/firefly/graph` | Search, starters, module overview and cycles |
| `/firefly/graph?q=OrderService` | Paginated matches on identity, factory detail and stereotype |
| `/firefly/graph?bean=App%5COrders%5COrderService&depth=2&dir=both` | Focus bean, hop columns, root chains, conditions and direct relations |
| `/firefly/graph?module=App%5COrders` | Module beans, exclusive reachability and boundary coupling |

`/firefly/beans` is the complete paginated catalogue, sourced from the same graph. It includes scanned
components, `#[Bean]` products and bound `#[ConfigProperties]` DTOs, and opens with dependent count
descending. Every identity links to its focus view. Interface search on component rows remains available.

## Reading a neighbourhood

Dependents sit to the left; dependencies sit to the right. Columns show graph distance from the selected
bean. PHP computes the positions at a fixed 176×38 pixels per node, 204-pixel column pitch and 46-pixel
row pitch. Two hops in both directions occupy 992 pixels horizontally, at most 728 pixels high with the
default 16-row bound. Smaller neighbourhoods use fewer rows. Depth three expands to 1400 pixels and
scrolls inside the drawing; headings scroll with their columns. There is no fit, camera, zoom or pan mode.

Every box is a native HTML anchor over decorative SVG edges, with keyboard focus and a full accessible
name. Labels remain at least 11 pixels. Module sigils and text identify modules independently of colour;
decorative stripes use 38% lightness in light mode and 62% in dark mode. Text uses the dashboard's readable
ink colours instead of those hues.

Each frontier receives candidates round robin, so one hub cannot consume all of a column's budget.
`+N more` links open the source bean's complete, paginated direct-neighbour tables. The two lists preserve
the selected bean, depth, direction and each other's filters. They show the `via` interface and whether
the relation injects or produces. Same-column, skip-hop and cyclic relations remain in these lists.

Root chains are shortest-first and deterministic. The details join every positive and negative condition
for the bean and its producing configuration; a class can legitimately have both outcomes under different
conditions. An absent Conditions endpoint simply omits those details.

## Modules and cycles

Modules are the first two namespace segments (or the only namespace segment, or `(global)`). The bounded
module overview presents each module's outgoing coupling. Module and coupling tables remain paginated
when the map exceeds its configured ceiling. Fewer than three modules use the module list directly.

Each module edge reports **Weight** (resolved dependency relations), **Beans** (distinct targets), **Via**
(distinct interfaces) and **Concrete** (direct relations). Factory `produces` edges are excluded by
default; a GET link includes them. The module view marks beans reachable only from that module and beans
with no dependents (`unused outside`). Exclusive reachability is a graph observation, not proof that
removing a module is safe: external Laravel bindings and runtime lookups are outside this catalogue.

Iterative Tarjan analysis finds complete strongly connected components, including self-injection. The
condensed graph supplies levels without recursive PHP calls; a 5000-node chain does not exhaust the call
stack. Module coupling has its own SCC analysis. A component denotes a cycle in the published wiring
graph; because factory production is represented too, it does not necessarily imply a runtime constructor
cycle. Bean names in a component are members, not a claimed path ordering.

## What the catalogue can establish

No beans are instantiated and no reflection runs to build this page. Component dependencies and factory
products come from the scanned or compiled catalogue. Bound configuration DTOs come from `configprops`;
explicitly unbound DTOs are omitted. Factory products carry the declaring method as their detail.

Competing factories retain distinct `Declaring::method()` identities even when declared in separate
configuration classes. The projection does not contain enough primary/qualifier metadata to choose the
container's winner. Ambiguous factory return types and interfaces therefore remain unresolved instead
of drawing an arbitrary target. Factory scope is displayed as **Not reported** when the projection does
not provide it. An unresolved type can also be supplied by a Laravel binding or absent from the scan;
the explorer cannot distinguish those cases from the published metadata alone.

## Configuration and migration

| Key | Default | Bounds / meaning |
|---|---|---|
| `firefly.admin.graph.focus.depth` | `2` | 1–4 hops; `depth` can override per request |
| `firefly.admin.graph.focus.max-rows` | `16` | 4–60 nodes per column |
| `firefly.admin.graph.focus.max-nodes` | `72` | 8–300 nodes in the drawing |
| `firefly.admin.graph.focus.max-paths` | `3` | 0–10 entry-point chains; 0 disables chains |
| `firefly.admin.graph.focus.page-size` | `50` | 10–500 relation rows, also capped by shared table settings |
| `firefly.admin.graph.starters` | `12` | 1–50 entries per starter list |
| `firefly.admin.graph.modules.max-nodes` | `40` | 0–200 modules; 0 disables the module map |
| `firefly.admin.beans.page-size` | `50` | 10–500 catalogue rows, also capped by shared table settings |
| `firefly.admin.graph.max-nodes` | `220` | Deprecated compatibility value; no longer controls drawing |

The old whole-graph view has been removed. `AdminSettings::$graphMaxNodes` still parses and clamps the
legacy key, but no page reads it. **Setting it to 0 no longer forces a list**, and raising it no longer draws
the whole graph. Use the complete catalogue or the relation lists for a tabular view, and the new focus
budgets to adjust drawing limits. Shared `firefly.admin.table.*` settings still control density, paging
choices and scrollport behaviour. No npm build, CDN or graph library is required.

See also [Dependency Injection](dependency-injection.md) and [Auto-Configuration](starters.md).
