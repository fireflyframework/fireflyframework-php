# Data Browser

The data browser is a Django-admin-style view over your application's own data, built on top of the LaraFly
[data layer](data.md) and shipped with the [admin dashboard](admin.md).

It is **off by default, and it does not inherit the dashboard's default.** Read
[The two gates](#the-two-gates) before you switch it on — that section is the point of this page.

!!! tip "Two switches, and neither follows `app.debug`"
    `firefly.admin.data.enabled` turns the browser on and `firefly.admin.data.writable` permits writes on top
    of it. Both default to **false** and neither is implied by `app.debug` or by `firefly.admin.enabled` —
    see [The two gates](#the-two-gates), which is the point of this page.
```bash
composer require firefly/admin      # already required by firefly/firefly; the browser is a part of the dashboard, not a package of its own
```

`DataBrowser` is the single entry point, and `DataBrowser::forContainer($container)` assembles one from the
application container in a line. Discovery, schema derivation, reads and the two writes all go through it, and
every one of them is behind the gates below.

## What it discovers

Nothing is registered, declared or configured. The browsable resources are **every bean whose scan-time
interface list contains `CrudRepository`** — which means a repository you wrote is browsable the moment the
container has it, and one you delete stops being browsable without anyone editing a list.

The source is `BeansCatalog`, the boot-time snapshot of the condition-filtered bean registry — the same rows
`/actuator/beans` serves. Each row already carries `class`, `stereotype` and the **full interface closure**
`ComponentScanner` recorded with `class_implements()` at scan time, so "is this bean a repository, and does it
also page?" is two `in_array()` calls over data the process already holds.

Re-deriving that by reflecting over every registered class at request time would be slower, would break the
framework's reflection-free boot contract for no gain, and — the decisive point — would find classes the
**container never registered**, so the menu would offer resources that cannot be resolved. The catalogue is the
definition of *what this application actually wired*, which is exactly the question a browser is asking.

Each discovered resource carries the capability flags every later path branches on:

| Flag | Means | Consequence |
|---|---|---|
| `paged` | The repository implements `PagingAndSortingRepository` | A page can be asked for by page number; the database does the offset, limit, `ORDER BY` and `COUNT` |
| `eloquent` | It is an `EloquentRepository` whose `$model` resolved to a real model class | Schema-derived columns, SQL-side search and sort, and any write at all |

A resource with neither is still listable — it is just expensive and read-only.

### Slugs are derived from the class, not from a counter

A slug is what addresses a resource — in a URL an operator bookmarks, in a link another page renders — so it
must not move because an unrelated repository was added. The base slug is the kebab-cased short name of the **entity** (falling back
to the repository's own name with the conventional `Eloquent` prefix and `Repository` suffix stripped), which
depends on nothing but that class.

A genuine collision — two `Wallet` entities in different namespaces — is resolved by **qualifying both sides**
with their full namespace rather than by suffixing one with `-2`. An index suffix depends on scan order, so the
loser's URL would change if the winner were ever removed; a namespace-qualified slug is a property of the class
alone. Labels are disambiguated the same way, because a menu with two entries both reading "Wallet" is not a
menu.

## The two gates

### `firefly.admin.data.enabled` defaults to **`false`**

The dashboard itself follows `app.debug`, and [the argument for that](admin.md#access-the-whole-security-boundary)
is sound *for what the dashboard shows*: beans, conditions, mappings and resolved configuration are facts about
the **application**, and an application already serving stack traces has already published facts of that kind.

This page shows facts about the application's **users**.

That is a categorically bigger disclosure, and the routine mistakes that expose it are the same ones that expose
nothing much today: a debug flag left on in a staging environment that shares a database with production, a
`.env` copied to a box that was supposed to be internal, a developer laptop tunnelled for a demo. Each becomes a
customer-record disclosure the moment a browser is wired to `app.debug`.

So the gate is **separate, explicit, and off**. `app.debug` cannot switch it on, and neither can
`firefly.admin.enabled`. Both of those must already be true **and** this key must be set:

```php
'firefly' => [
    'admin' => [
        'enabled' => true,          // the dashboard itself
        'data' => [
            'enabled' => true,      // ...and, separately, the data browser
        ],
    ],
],
```

Disabled means **empty, everywhere**. The resource registry returns nothing when the key is off, even though
every operation is gated again downstream. The redundancy is deliberate: the registry is public API a view could
hold directly, and a discovery list that leaked the names of an application's entities while the browser was
switched off would already be a disclosure.

### Writes need `firefly.admin.data.writable` **on top of that**

Also `false`, also its own key, and **ineffective on its own** — a write requires both.

Reading the wrong row is a disclosure; deleting it is data loss with no undo, from a form, over a session that
may be nothing more than "debug was on". Turning on the browser is a decision about **visibility**; turning on
writes is a decision about **custody**. Collapsing the two into one key means the operator who wanted to look at
a table also armed the delete button.

A write attempted with only one gate set is **refused with a stated reason**, not silently ignored.

## Create exists for Eloquent, and is refused for everything else

The browser once had no `create()` at all, and the argument for that was half right.

**An aggregate's constructor is where its invariants live** — an `Order` that must have at least one line, a
`Wallet` whose balance starts at zero in the currency it was opened in, a value object that rejects a malformed
IBAN — and a form built from a column list knows none of them. For a repository over a hand-written domain object
there are only two ways to build the row and both are wrong: call the constructor, which needs arguments the form
cannot supply in the right types or the right order; or write the columns straight to the table, which produces a
row the domain model considers impossible and which every later read then has to cope with. The second is what a
"just insert the columns" implementation actually does, and it is *worse than having no button*, because it looks
like it worked. **That case is still refused, by name.**

It was never true for an **Eloquent model**. Eloquent constructs one empty and fills it by attribute — which is
exactly what `update()` has always done to a row that exists. Create was refusing on a risk update was already
taking, and the inconsistency cost every application a CRUD surface that stopped at RUD.

So: `create()` is offered when the resource is Eloquent-backed, under the same two switches, the same coercion and
the same unknown-field refusal. The identifier and any masked column are **omitted from the form** rather than
disabled in it — a field the browser would refuse to write should not appear to accept — so a crafted POST cannot
choose a primary key or plant a value the page would only ever show as `******`.

## Reads: four paths, and one of them is a foot-gun

| # | Path | How |
|---|---|---|
| 1 | **Paged, unsearched** | `findPaged(Pageable)` — the repository does the offset, limit, `ORDER BY` and `COUNT`. The only path whose cost is independent of table size. |
| 2 | **Paged, searched, Eloquent** | `findBySpecificationPaged(Specification, Pageable)` — filter, page and count all happen in SQL |
| 3 | **Unpaged** | `findAll()`, then sort and slice **in PHP** |
| 4 | **Unpaged, searched** | Path 3 plus an in-PHP substring filter; no SQL is involved in the matching at all |

Path 2 goes through `EloquentRepository`'s public **specification seam**, which applies the predicate to the
repository's *own* `query()` builder. Going around it with `Model::query()` would have been shorter and would
have silently dropped any constraint a repository added by overriding `query()` — which on a repository that
scopes to a tenant is a cross-tenant disclosure.

!!! warning "Path 3 is a foot-gun, and it is load-bearing to say so"
    `findAll()` on a plain `CrudRepository` issues `SELECT *` with no `LIMIT`, hydrates every row of the table
    into PHP objects, and only then does the browser throw away all but 25 of them. On a table of ten thousand
    rows that is a slow page; on a table of ten million it is an out-of-memory that kills the worker — and it
    happens on the **first click**, not gradually. There is no way to do better through the `CrudRepository`
    interface: it has no limit, no offset and no count-with-predicate. The honest options were "refuse to browse
    repositories that cannot page" or "browse them and say what it costs"; this is the second. **A repository
    that will be browsed against a large table should implement `PagingAndSortingRepository`**, at which point
    it takes path 1.

**Every listing is ordered, even when nobody asked.** With no `ORDER BY`, a paged query's row order is whatever
the storage engine finds convenient, and it is allowed to differ between the query for page 1 and the query for
page 2 — so a row can appear on both pages while another appears on neither, and the operator sees a table
missing records that are actually there. With no requested sort, the identifier is used: stable, and always
indexed.

### Search is bound, never interpolated

On the SQL paths the term is passed as a **binding** to `where(column, 'like', ?)`. It is never concatenated
into a fragment, never handed to `whereRaw`, and therefore cannot become SQL no matter what it contains.

The **column names are not caller data at all**: they come from the derived schema, built from the driver's own
column list or from a class's declared properties, and a caller-supplied sort column is checked for membership
in that list before use — an unknown one is dropped, not quoted.

`%` and `_` inside the term are deliberately left as **wildcards** rather than escaped. `LIKE` has no portable
escape character (sqlite has none by default, MySQL uses backslash, ANSI needs an explicit `ESCAPE` clause), so
escaping "portably" means breaking search on some driver — and an operator who types `%` into an admin search
box wants a wildcard.

Search covers the **first twelve searchable columns** in schema order: OR-ing a `LIKE` across every text column
of a wide table produces a query no index can help with, and past a dozen columns the page is slow enough that
an operator will assume it hung.

## Columns are a property of the resource, never of a row

The tempting implementation is `$model->getAttributes()` on the first row, using its keys as the columns. It is
wrong in three ways that all bite in production: an **empty table** yields no columns at all (so the page
renders as broken rather than as empty), a row hydrated with a `select` of two columns yields two columns for
the whole resource, and an accessor-heavy model yields whatever `$appends` decided rather than what the table
holds.

So columns are derived **once**, from a source that describes the resource:

| `source` | Derivation | Meaning |
|---|---|---|
| `schema` | The live database via the schema builder | Authoritative — every column, real nullability |
| `entity` | The entity class's public and promoted properties | Whatever the class chose to expose |
| `none` | Nothing could be derived | No connection, no model, no typed entity |

The source is recorded on the schema and shown, because *"why is this column missing"* is a question the page
has to be able to answer.

**The schema is authoritative; the casts refine it.** `Schema::getColumns()` reports what the driver knows, and
the driver frequently does not know what the application meant — sqlite stores a `json()` column as `text` and a
`boolean()` as `tinyint`, so a type map built from `type_name` alone shows a JSON blob as a string and a flag as
a number. The model's own `$casts` carry the semantic type the schema cannot express, and where the two disagree
**the cast wins**, because the cast is what the application will hand the view.

### The display type vocabulary is closed, and deliberately small

`string`, `int`, `float`, `bool`, `datetime`, `json`. It is a **rendering hint, not a schema echo**: the view has
to decide "right-align this", "draw a chip", "format this as a timestamp", "clip this blob", and there are only
those decisions plus a default. Anything outside the vocabulary degrades to `string` rather than reaching the view.

!!! note "`float` never reformats the value"
    A `decimal(10,2)` column arrives from PDO as the string `"10.10"`, and that is not an accident of the driver
    — it is how the value survives a round trip without binary floating point eating the last cent. Every
    non-integer number used to be typed `string` for that reason, which meant a money column read as a string in
    the explorer, was offered to a `LIKE` search, and let the editor save `"abc"` into it.

    `float` is a hint about **alignment and validation**, not about formatting. The cell prints the value
    verbatim, so `"10.10"` renders as `10.10`; it is right-aligned with tabular numerals so digits line up down
    the column. And a write is **validated** with `is_numeric` but **stored as the string**: casting to a PHP
    float to store it would reintroduce exactly the precision loss a `decimal` column exists to avoid —
    `12345678901234567890.12` does not survive a `float`, and the driver can bind the digits verbatim.

### The identifier is derived, and allowed to be null

Everything past the listing keys on it: the detail view addresses a row by it, delete addresses a row by it, and
update addresses a row by it *while refusing to write it*. A browser that guessed wrong would render a link to a
row it cannot fetch — or, far worse, issue a delete whose `WHERE` clause matched more than one row.

So it is derived explicitly: Eloquent's own `getKeyName()` (which respects a model that renamed it), or a
conventional identifier property on a plain entity. When it cannot be determined the resource is browsable as a
**list and nothing else**, and every `find`/`delete`/`update` is refused with a reason rather than improvised.

Records are projected against the schema's column list and inherit **its order**, with a column the row did not
supply present as `null` rather than missing. `getAttributes()` returns keys in whatever order the driver
returned them, which differs between drivers and can differ between two rows of the same table after a migration
adds a column — and a detail page whose fields move between rows is unreadable.

## Relations

An entity's relations are discovered by **calling** the methods that declare one, because that is the only way to
learn which columns they join on: a method's name says nothing and its return type says only the kind.

Which makes "what is safe to call" the load-bearing question, and the answer is the **declared return type**. Only
a public, non-static, no-argument method whose return type is an Eloquent `Relation` subclass is ever called — a
method announcing `: HasMany` is a relation definition by construction, the same signal Laravel's own IDE tooling
and `with()` validation rely on, and one an accessor cannot claim without lying about its signature. Anything
without that annotation is left alone. The call itself executes no query: Eloquent defers until `get()`.

```php
class OrderEntity extends Model
{
    /** @return HasMany<OrderLineEntity, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLineEntity::class, 'order_id');
    }
}
```

| Relation | On the record page | Where it goes |
|---|---|---|
| `BelongsTo` | **Open →** | the one parent record |
| `HasOne`/`HasMany` | **Browse →** | the child listing, filtered to this row's key |
| `BelongsToMany`, `HasManyThrough`, the morph family | listed, not linked | no single column to filter on |
| `MorphTo` | listed, not linked | the other end is decided per row by a type column |

A relation whose other end is **not a browsable resource** — no repository declares it, or its resource is excluded
— is still shown, because it tells a reader the shape of the model, but is not rendered as a link: distinguishing
the two in the model rather than in the template is what stops a view minting a URL that 404s.

## Filtering, sorting and paging

The listing is a **URL**. Every filtered, sorted, paged view is something an operator can bookmark, paste into a
ticket or hand to someone else, which is most of what a data explorer is for — and every link on the page carries
the whole state, because a sort that dropped the filter would widen the listing back to every row, which reads as
rows appearing from nowhere.

Eight comparisons, over the columns the resource publishes **minus the masked ones**:

| Operator | Meaning |
|---|---|
| `eq`, `ne` | `=`, `!=` |
| `contains`, `starts` | `LIKE`, with the wildcards added around an **escaped** value |
| `gt`, `lt` | `>`, `<` |
| `null`, `notnull` | `IS NULL`, `IS NOT NULL` |

Two spellings in the URL: `?fk=order_id&fv=7` is a single equality and is what every relation link produces —
short enough to read in a status bar — while `?fc[]=…&fo[]=…&fv[]=…` is what the filter bar builds. Both are
validated identically.

**A column the schema does not publish for filtering, and an operator outside that set, are dropped** rather
than passed to the driver. Both arrive in a URL an operator can hand-edit, and a query that reached the driver
with an arbitrary identifier in it is a column-name oracle at best. Dropping rather than erroring is deliberate
too: an error that distinguished "no such column" from "no rows" would answer the same question more slowly.

!!! danger "A sensitive column is not filterable, and this was learned the hard way"
    Filtering was first shipped over *every* column, which quietly re-opened the channel masking exists to
    close. A masked column renders as `******`, but a filter over it answers a yes/no question about the real
    value — and a yes/no question you can ask repeatedly is an **extraction oracle**. An adversarial review of
    the branch proved it against the fixture: twenty-one filtered requests recovered `correct horse battery`
    from a column the listing showed only as asterisks, and `>`/`<` do it faster still by binary search. The
    filterable set is now `searchable()`'s rule applied to every type — everything except the masked columns —
    and `tests/Data/DataFilterSafetyTest.php` runs the original attack as a regression test.

!!! danger "Escaping a `LIKE` without an `ESCAPE` clause is worse than not escaping"
    The same review found the other half. `contains` and `starts with` backslash-escape the user's `%` and
    `_` so they cannot act as wildcards — but a plain `LIKE ?` leaves the driver with no escape character
    declared, so the backslash is matched *literally*. Suppressing the wildcards worked; finding anything
    containing an underscore stopped working, and it failed **silently**: a search for `ada_love` returned
    zero rows against a table holding `ada_lovelace@example.test`. The predicate now emits an explicit
    `ESCAPE` clause, with the column wrapped by the grammar rather than interpolated, and the search box —
    which had no escaping at all, so a bare `%` matched every row — goes through the same helper.

Filters **AND** with each other and with the search box, so narrowing a relation's listing cannot escape it. Every
comparison binds its value, including the `LIKE` ones.

The same eight comparisons are implemented for the [in-PHP fallback path](#reads-four-paths-and-one-of-them-is-a-foot-gun),
because a repository that cannot page must be filtered by the same rules as one that can — two implementations
would drift, and the drift would show as one filter meaning different things on different resources.

## Secrets

Sensitivity is decided **by name, in one place**: the actuator's own `SensitiveValueMasker`, the same rule that
masks `/env` and `/configprops`, reused rather than mirrored — a second copy of a masking list is how a masking
list rots.

A model's own **`$hidden` is treated as a second sensitivity source.** The name rule catches `password`,
`api_token` and their relatives but cannot know that this application considers `recovery_phrase` a secret. A
model that already hid a field from its JSON representation has stated that intent in the only place it could,
so the browser honours it rather than publishing in HTML what the model refuses to publish in JSON.

A sensitive column is masked in the listing, masked in the detail view, **excluded from search**, and **refused
as an update target**:

- Excluded from search because a box that answers *"yes, some row's `api_token` starts with `sk_live_9`"* is an
  oracle, and an operator can walk it one character at a time.
- Refused as an update target because its *displayed* value is `******` — round-tripping a rendered form would
  write the mask over the real credential, which is a data-loss bug the masking itself created.

The **identifier** is refused as an update target too, for a different reason: re-keying a row from a generic
form is not an edit, it is a different row. Foreign keys pointing at the old value do not follow, and the browser
has no way to know which ones exist.

Both refusals are enforced twice — a predicate the view uses to render the field read-only, and again in the
write path, so a hand-crafted POST cannot reach what the form would not offer.

## Writes

`update()` and `delete()`, both returning a typed `DataWriteResult` with **four distinguishable outcomes**
rather than a bool:

| Outcome | Means | What the operator should do |
|---|---|---|
| `Done` | It happened | See the new state |
| `Refused` | A gate, or something the browser will never do | Change configuration — or stop asking |
| `NotFound` | The row or the resource is gone | Navigate away; a retry will not help |
| `Failed` | The database said no | Look at the log |

A bare `false` collapses four situations a person needs to tell apart, and rendering "delete failed" for all four
sends an operator to debug a database that is working perfectly because a config key is off.

**An update only writes what actually changed.** A submitted form round-trips every field; the ones whose value
did not change — plus the identifier and any masked secret — are dropped, and the result lists the columns
actually written. A submitted field that is not a column of the resource refuses the whole update rather than
being ignored.

**A delete is verified after the fact** with `existsById()` rather than trusted, because
`CrudRepository::deleteById()` returns `void`: a repository whose delete was a no-op — a soft-delete scope that
excluded the row, an override that swallowed it — would otherwise report success, and the operator would watch
the row reappear on the next page load.

**A non-Eloquent resource is refused for writes**, with a reason. There is no table to address and no
`setAttribute` to call.

## Nothing throws at the caller, and no error text is an exception message

Reads answer with a listing that carries a reason, or a null record; writes answer with one of the four outcomes.
A view rendering an admin page must not have to be exception-safe to stay on its feet — and, more sharply, an
exception that escaped would be rendered by the framework's error page.

That matters because of what a database exception *contains*. Laravel's `QueryException` stringifies the failing
SQL **and its bindings** into `getMessage()`. Echoing that to the browser would publish the schema and, far
worse, the values that were bound — which on a search over a users table is the operator's own query, and on a
detail lookup is a primary key.

So every reason a page can render is a **fixed sentence composed in this layer**, plus at most the exception's
class name. The message stays in the exception, where a log can have it.

## Configuration (`firefly.admin.data.*`)

| Key | Default | Meaning |
|---|---|---|
| `firefly.admin.data.enabled` | **`false`** | Enable the browser at all — the `/firefly/data` pages, the entity map, and the `DataBrowser` API. Does **not** follow `app.debug` or `firefly.admin.enabled` — see [The two gates](#the-two-gates). |
| `firefly.admin.data.writable` | **`false`** | Allow `update` and `delete`. Requires `enabled` as well; ineffective alone. |
| `firefly.admin.data.page-size` | `25` | Default rows per page. Clamped into `[1, max-page-size]`. |
| `firefly.admin.data.max-page-size` | `200` | Ceiling applied to any caller-supplied page size. Itself capped at **1000**, because `?perPage=1000000` on a resource that cannot page is a request to materialise the table into PHP memory. |
| `firefly.admin.data.exclude` | `''` | CSV of resource slugs to refuse. A **hard refusal, not a menu preference**: the resource is hidden *and* every operation on it is refused. Hiding `user` because the table holds PII achieves nothing if the row URL still answers. |
| `firefly.admin.data.relations` | `true` | Discover relations, so records link to what they reference and the [entity map](admin.md#the-entity-map) has edges. Discovery **calls** the model methods that declare one — see [Relations](#relations) — so it is a key rather than a constant. |

The page-size cap is applied to whatever the caller asks for, so the query layer never sees a size it did not
agree to.

## Reflection is confined to one class

Discovery reads the compiled catalogue; schema derivation reads Laravel's schema builder; queries read the
container. Exactly one class reflects, and only for two facts no manifest carries:

1. **Which model a repository manages.** `EloquentRepository` declares `protected string $model` and the
   concrete repository sets it as a property *default*. It is protected, there is no accessor, and the value
   never reaches a descriptor — `ComponentScanner` records a class's dependencies and interfaces, not its
   property initialisers. It is read via `getDefaultProperties()`, which does **not** construct the repository:
   discovery must stay cheap and must not be able to fail because a repository constructor wanted a live
   connection.
2. **What shape a non-Eloquent entity has.** A plain `CrudRepository` over value objects has no table to ask, so
   the only honest column list is the entity's declared fields — public properties and promoted constructor
   parameters. Promoted parameters are why accessibility has to be bypassed: `Firefly\Domain\Entity` promotes
   `protected int|string|null $id`, so a public-only scan would miss the identifier of every entity built on the
   framework's own DDD base class.

Confining both to one class is what keeps the rest honest — the registry, the schema factory, the query engine
and the browser contain no `Reflection*` reference at all, so the cost and the risk are auditable by grep. Every
entry point is guarded and memoised: reflection on a class the autoloader cannot complete throws, and a
resource list that dies because one repository is broken is useless, so a failure degrades **that one resource**
instead.

## Known-latent

- **No create for a non-Eloquent repository**, permanently — see
  [above](#create-exists-for-eloquent-and-is-refused-for-everything-else).
- **Writes are Eloquent-only.** A plain `CrudRepository` over value objects is browsable and read-only, for
  the same reason.
- **A pivot or a polymorphic relation is listed, not walkable.** `BelongsToMany`, `HasManyThrough` and the
  morph family have no single column the browser can filter on — a `MorphTo`'s other end is decided per row
  by a type column — so they appear on a record page and are absent from the [entity map](admin.md#the-entity-map),
  because a line with no join to name would be decoration.
- **`firefly/admin` still ships no authentication of its own.** The data browser inherits the dashboard's
  access model exactly, which means the [route-level protection](admin.md#access-the-whole-security-boundary)
  is your responsibility — and matters more here than anywhere else in the dashboard.

---

See also: [Admin Dashboard](admin.md) for the access model this page sits inside, [Data & Repositories](data.md)
for `CrudRepository`/`PagingAndSortingRepository` and the specification seam, and
[Relational Data](data-relational.md) for `EloquentRepository`.
