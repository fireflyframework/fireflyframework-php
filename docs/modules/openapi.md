# OpenAPI

`firefly/openapi` generates a valid **OpenAPI 3.1** document from the manifests the framework already holds in
memory. There is no annotation dialect to learn and nothing to keep in sync by hand: `RouteManifest` supplies the
paths, verbs, statuses, route names and the per-parameter binding plan; `ConstraintManifest` supplies the
request-body schemas and their `required` lists; `firefly/kernel`'s `ErrorResponse` supplies the RFC 9457 error
component. Install the package and a LaraFly app has a spec — and therefore typed clients — for free.

Because every fact in the document is read from the same compiled artifacts the dispatcher dispatches from and the
validator validates with, **the spec cannot drift from the server**.

`firefly/firefly` requires it, so a skeleton project already serves `/openapi` and `/openapi.json`. Add it
directly if you took the packages à la carte:

```bash
composer require firefly/openapi
```

It is *not* part of the `firefly/firefly` metapackage — like `firefly/admin` and the broker adapters, it is an
opt-in dependency.

## What you get

| Surface | Default | Purpose |
|---|---|---|
| `GET /openapi.json` | on | The generated OpenAPI 3.1 document, served as `application/json` |
| `GET /openapi` | on | A browser API console — Swagger UI by default, from your own origin |
| `GET /openapi/assets/{file}` | on | The Swagger UI distribution files, served from this application |
| `php artisan firefly:openapi` | — | Writes the document to a file (`--output=`) or raw to stdout |

The media type on the spec route is `application/json`, deliberately not the more precise
`application/openapi+json;version=3.1`: that type is registered but poorly supported, and several of the generator
toolchains this package exists to feed refuse a document whose `Content-Type` they do not recognise. The document
says `"openapi": "3.1.0"` in its first member, which is how every consumer actually detects the version.

## The routes are not attribute routes

All three are mounted natively on the illuminate `Router` from `OpenApiRouteRegistrar`, a `BootPass` at
`WiringPasses` order **60** — the `ActuatorRouteRegistrar` idiom, chosen for two independent reasons.

First, **an attribute route cannot be configurable.** `#[GetMapping('/openapi.json')]` bakes its literal into a
compiled `RouteDescriptor` at `firefly:cache` time, so an operator could never move the spec off a path that
collides with one of their own, and could never take it off a public surface without deleting the package.

Second, an attribute route would enter the application's `RouteManifest` — and the generator reads that manifest,
so **the package would document itself.**

`firefly.openapi.enabled` (default `true`) is enforced *there*, on the routes, rather than on the beans: the
generator and its collaborators are inert without routes, so gating the routes is the whole of the switch. Turning
it off leaves the paths genuinely **unrouted**, so they 404 through the router's own `NotFoundHttpException`, which
`ProblemDetailsRenderer` renders as a proper `404` problem-details body rather than a 500.

The actions are resolved *inside* each route closure (`$container->make(...)`), never captured at boot — capturing
would freeze one `OpenApiGenerator` into the route for the process's lifetime, which is exactly the shape that
breaks under Octane when a later request's container is a different sandbox.

## What the generator maps

**Operations** come from each `RouteDescriptor`: the verb and path (Laravel's optional `{id?}` is normalised to
`{id}`, since a path parameter is *required* in OpenAPI), the `#[Mapping]`'s declared status, and the route name as
the `operationId` when one is set — otherwise a derived `lcfirst(<ControllerShortName minus "Controller">) +
ucfirst(<method>)`. A repeat claim is suffixed (`_2`, `_3`) rather than allowed to overwrite, because a duplicate
`operationId` is the one flaw that makes most client generators abort rather than degrade. Operations are tagged by
controller short name.

**Parameters** come from the binding plan — the same `kind` discriminator `ArgumentResolver` dispatches on at
request time. `#[PathVariable]`, `#[QueryParam]` and `#[RequestHeader]` become Parameter Objects; `#[UploadedFile]`
becomes a `multipart/form-data` part; a container-injected service is not part of the HTTP contract and never
appears.

**Request bodies** come from the `#[RequestBody]` DTO, as a `$ref` into `components/schemas` — one component per
DTO, reused everywhere, with nested `#[Valid]` DTOs given their own component rather than being inlined, so a
self-referential DTO terminates as a `$ref` cycle instead of recursing forever. The whole derivation —
declared types, compiled constraints, docblock prose — is
[its own section below](#how-a-request-dto-becomes-a-schema).

**Responses.** The success entry is keyed by the `#[Mapping]`'s declared status, and its body schema comes from the
controller method's declared **return type** — the only place the shape of a successful response is stated anywhere
in the framework, since `RouteDescriptor` records the status but not the payload. A `204` (or a `void`/`never`
return) gets no `content` at all, because emitting a content map for a status that carries no body is exactly what a
strict client generator turns into a phantom return type. A plain `array`/`iterable` return degrades to
`type: object` rather than being expanded from a `@return array{…}` docblock: parsing PHPDoc here would make the
generated document depend on comment text nothing else in the framework treats as binding.

Beside it, every operation carries the shared `#/components/responses/Problem` as its `default`, plus a `400` when
`ArgumentResolver` has something it can reject before the controller runs (a required binding, or one whose value
must be *converted* out of the string the wire always carries — a `string` parameter cannot fail a conversion, an
`int`/`float`/`bool`/enum can), and a `422` when a binding carries `#[Valid]`. `ProblemSchema` describes what
LaraFly *actually* returns — RFC 9457's members **plus** Firefly's `code`, `category`, `severity` and `errors` —
with the `category` and `severity` enumerations read straight off
`ErrorCategory::cases()`/`ErrorSeverity::cases()`, so a new kernel case appears in the spec on the next generation
with no edit in this package.

**`#[Controller]` HTML routes are excluded by default.** They are part of the HTTP surface but not JSON API
operations, and describing one as `application/json` hands a generator a typed client for a response that is a web
page. `firefly.openapi.include-html` documents them anyway, as `text/html`.

## How a request DTO becomes a schema

A `#[RequestBody]` DTO is turned into a `components/schemas` entry by `DtoSchemaFactory`, from **three sources that
each know a different part of it** — and no two of which can be derived from the other:

| Source | Knows | Does not know |
|---|---|---|
| `ConstraintManifest` — the compiled rules | which members are required, what shapes they must have | types: a rule list is untyped by construction |
| The **constructor signature**, by reflection | `?int`, a backed enum, a nested DTO, a default value | constraints: they live in attributes the manifest has already digested |
| The **docblock**, plus `#[ApiProperty]` | what the member *means*, an example, a more precise `format` | everything above |

Neither of the first two alone produces a usable schema. Types-only documents `#[NotBlank] string $name` as an
unbounded string; constraints-only documents `int $quantity` as a string.

### Why the manifest, and not the `#[Constraint]` attributes

Reading the attributes back off the DTO is the obvious route to `#[Email]` → `format: email`, and it would document
a validator that does not exist. `ConstraintManifest::rulesFor()` returns the exact
`list<string|ValidationRule>` the `BeanValidator` is handed at request time, and by the time it does, the scanner
has already:

* applied the **Jakarta null contract** — a `nullable` flag prepended to every property whose declared type admits
  null and which carries no `NullAware` rule;
* expanded `#[Size]` into a first-party rule **object** rather than Laravel's polymorphic `min:`/`max:` strings;
* flattened one `#[Valid]` level into dotted keys (`beneficiary.postcode`).

Generating from the attributes would re-derive all of that by hand and drift from it the first time
`packages/validation` changed a `toRules()` body. Generating from the manifest cannot drift, because the manifest
**is** the contract.

### The property list, and why there is no `additionalProperties: false`

The members documented are the **constructor's parameters, in declaration order** — exactly what `ArgumentResolver`
hydrates from. It picks the compiled binding's property list out of the decoded body and splats those keys as named
arguments; keys outside the list are **silently ignored, not rejected**. So no `additionalProperties: false` is
emitted: the server genuinely accepts extra members, and a spec claiming otherwise would make conforming clients
fail requests the server would have served.

A member the constructor does *not* take is still documented when the manifest carries rules for it, because
`BeanValidator` validates the raw decoded array — such a member is enforced on input even though nothing hydrates
it.

An empty `required` array is **omitted** rather than emitted: `required: []` is invalid under the OpenAPI 3.1
meta-schema (`minItems: 1`), and strict validators do enforce it.

### The type half

`TypeSchema` handles everything derivable from a type *name* alone. Three shapes get first-class treatment because
a JSON client has to decode them differently and all three are invisible to the constraint list:

| Declared type | Fragment |
|---|---|
| `string` / `int` / `float` / `bool` | `type: string` / `integer` / `number` / `boolean` |
| `array`, `iterable` | `type: array` |
| A **backed enum** | `enum: [...]` over the backing values, plus `type: integer` when every case backs an int, else `type: string` |
| `DateTimeInterface` (or any implementor) | `type: string`, `format: date-time` |
| `mixed`, `object`, `null`, untyped, or a class this process cannot autoload | `{}` — the "any JSON value" schema, **never** a guessed `type: string` |
| Any other class | *no fragment* — the caller mints a `$ref` instead |

The backed-enum row is the single highest-value thing the reflection buys: `Currency $currency` documents the exact
accepted set, where the constraint list — usually empty on an enum-typed property, because the type already
constrains it — would have documented an unbounded string.

### Requiredness is wider than the constraints say

`MemberType::required()` is deliberately broader than the constraint-derived answer:

```php
public function required(): bool
{
    return ! $this->hasDefault && ! $this->nullable && $this->type !== null;
}
```

A constructor parameter with no default whose type does not admit null **cannot be omitted**: `ArgumentResolver`
splats only the keys the body actually carried, so a missing one raises `ArgumentCountError` inside `new $dto(...)`
— a 500, *after* validation has already passed. Documenting it as optional would hand every generated client a
legal-looking request the server cannot serve. So the PHP signature is treated as the requirement it genuinely is,
alongside whatever `#[NotNull]`/`#[NotBlank]` say.

JSON Schema states requiredness on the **parent** object, never on the member, which is why the mapper returns a
`PropertySchema` — a schema *plus* that one boolean — rather than a schema alone.

### Constraints to keywords

`ConstraintSchemaMapper` walks the compiled rule list and layers keywords onto whatever the declared type already
produced. **First writer wins**, everywhere: the type fragment is seeded before any rule is seen, so
`#[Min(1)] int $quantity` keeps `type: integer` instead of being widened to `number` by the `numeric` rule string
`#[Min]` emits — which would wrongly document `1.5` as acceptable. The same ordering then applies among the rules
themselves, matching declaration order, which is the order the validator applies them in.

Each attribute below is shown with the compiled rule it actually produces, because that rule — not the attribute —
is what the mapper sees:

| Constraint | Compiles to | JSON Schema |
|---|---|---|
| `#[NotNull]` | `present` + `NotNull` rule | `required` **and** clears nullability — the one rule that answers both questions |
| `#[NotEmpty]` | `required` | member added to the parent's `required` |
| `#[NotBlank]` | `required`, `string`, `regex:/\S/` | `required` + `type: string` + `pattern: \S` |
| `#[Size(min, max)]` | `Size` rule object | `minLength`/`maxLength`, or `minItems`/`maxItems` when the type is `array` |
| `#[Min(n)]` / `#[Max(n)]` | `numeric` + `gte:n` / `lte:n` | `minimum` / `maximum` |
| `#[Positive]` / `#[PositiveOrZero]` | `numeric` + `gt:0` / `gte:0` | `exclusiveMinimum: 0` / `minimum: 0` |
| `#[Negative]` / `#[NegativeOrZero]` | `numeric` + `lt:0` / `lte:0` | `exclusiveMaximum: 0` / `maximum: 0` |
| `#[Digits(i, f)]` | `numeric` + a `regex:` bounding both parts | `type: number` + `pattern` |
| `#[Pattern(re)]` | `regex:re` | `pattern`, PCRE delimiters stripped |
| `#[Email]` | `email` | `type: string`, `format: email` |
| `#[UuidValue]` | `Uuid` rule | `type: string`, `format: uuid`, `pattern` |
| `#[Phone]` | `E164` rule | `type: string`, `format: phone`, `pattern: ^\+[1-9]\d{1,14}$` |
| `#[CurrencyCode]` | `Currency` rule | `type: string`, `format: currency`, `pattern: ^[A-Z]{3}$` |
| `#[CountryCode]` | `CountryCode` rule | `type: string`, `format: country-code`, `pattern: ^[A-Z]{2}$` |
| `#[LanguageTag]` | `LanguageTag` rule | `type: string`, `format: bcp47`, `pattern` |
| `#[PostalCode]` | `PostalCode` rule | `type: string`, `format: postal-code`, `pattern` |
| `#[Iban]` | `Iban` rule | `type: string`, `format: iban` — **no pattern**, plus `iban:checksum` in the extension |
| `#[Swift]` / `#[Bic]` | `Swift` / `Bic` rule | `type: string`, `format: swift` / `bic` — no pattern |
| `#[Cusip]` / `#[Isin]` | `Cusip` / `Isin` rule | `type: string`, `format: cusip` / `isin`, plus `…:check-digit` in the extension |
| `#[RoutingNumber]` | `RoutingNumber` rule | `type: string`, `format: aba-routing-number`, plus the check digit in the extension |
| `#[Luhn]` | `Luhn` rule | `format: luhn` **only** — no `type`, since Luhn says nothing about it — plus the check digit in the extension |
| `#[Percentage]` | `Percentage` rule | `type: number`, `minimum: 0`, `maximum: 100` |
| `#[Money]` | `PositiveMoney` rule | `type: number`, `exclusiveMinimum: 0`, `multipleOf: 0.01` |
| `#[DecimalScale(n)]` | `DecimalScale` rule | `multipleOf` — `0.01` for scale 2, `1` for scale 0 |
| `#[AssertTrue]` / `#[AssertFalse]` | `accepted` / `declined` | `type: boolean` + `const: true` / `false` |
| `#[Future]` / `#[Past]` | `date` + `after:now` / `before:now` | `type: string`, `format: date-time`; the temporal half lands in the extension |

`multipleOf` is computed as a division rather than `10 ** -$scale` so the value round-trips through `json_encode`
as `0.01` instead of `1.0E-2` — both are legal JSON numbers, but only the first reads as money in a rendered spec.

Raw Laravel strings reach the same table through the `#[Rules]` escape hatch, and a few only exist there:

| Rule string | JSON Schema |
|---|---|
| `nullable` | sets the nullable flag (see below) |
| `required`, `present`, `filled` | member added to the parent's `required` |
| `string`, `numeric`, `integer`/`int`, `boolean`, `array` | the corresponding `type` |
| `url`, `active_url` | `type: string`, `format: uri` |
| `ip` | `type: string`, `format: ipv4` |
| `date`, `date_format` | `type: string`, `format: date-time` |
| `gte:` / `lte:` / `gt:` / `lt:` | `minimum` / `maximum` / `exclusiveMinimum` / `exclusiveMaximum` |
| `min:` / `max:` / `between:a,b` / `size:` | **polymorphic** — see below |
| `in:a,b,c` | `enum` |
| `accepted` / `declined` | `type: boolean` + `const` |

Laravel's `min:`/`max:`/`between:`/`size:` are deliberately polymorphic — `Validator::getSize()` reads the *value*
for a numeric attribute and the *length/count* otherwise — so what they translate to depends on the type already
resolved for the property: `minimum`/`maximum` for a numeric one, `minLength`/`maxLength` for a string,
`minItems`/`maxItems` for an array. Firefly's own `#[Size]` no longer emits these (it compiles to a rule object
precisely because the polymorphism was a defect), but `#[Rules('min:3')]` passes the raw string straight through, so
the ambiguity is still reachable and is resolved here exactly as the validator resolves it.

An argument that is not numeric is not a bound at all — `gte:other_field` is a field reference JSON Schema cannot
express — so it is recorded in the extension rather than coerced to `0`.

### Nullability, patterns, and the extension

**Nullability** is spelled the 3.1 way. OpenAPI 3.1 *is* JSON Schema 2020-12, which dropped 3.0's `nullable: true`
in favour of a type union: `type: [string, "null"]`. A schema with no `type` at all already admits null and is left
alone; an `enum` additionally gains a `null` member, because widening `type` alone would leave `null` failing the
enumeration.

**Patterns** are translated from PCRE (delimiters plus flags, the form every `regex:` rule carries) to the bare
ECMA-262 body the `pattern` keyword expects. `D` and `u` are dropped as genuine no-ops — ECMA `$` without `m`
already anchors at end-of-input, and JSON Schema patterns are already Unicode. Any *other* flag, `i` above all,
cannot be carried across, so the pattern is still emitted (it is the closest true statement available) **and** the
original rule is recorded in the extension, so a reader can see the published pattern is stricter than the server's.
An unparseable pattern is recorded and otherwise ignored — a malformed `pattern` keyword breaks every consumer of
the document, which is far worse than an absent one.

JSON Schema has exactly **one** `pattern` slot per schema object, and `#[NotBlank]` + `#[Pattern]` on the same
property genuinely produces two. One pattern becomes `pattern`; several become an `allOf` of single-pattern
subschemas. Collapsing them by keeping the last would silently drop the non-blank guarantee.

**Nothing is dropped silently.** Constraints JSON Schema cannot express (`after:now` — it cannot say "in the
future"; a bare Luhn checksum; a third-party `ValidationRule`, recorded by class name because that is the only
thing knowable about it without executing it) and ones it can only approximate are recorded under the
`x-firefly-constraints` specification extension. Extensions are explicitly permitted by OpenAPI 3.1 and ignored by
every conforming tool, so the document stays valid while the full truth survives for a human or a custom generator
to read.

!!! note "Where a `format` is invented, and where a `pattern` is withheld"
    `format` in JSON Schema 2020-12 is an **open vocabulary** — unknown values are annotations, not errors. IBAN,
    BIC, ISIN, CUSIP and E.164 have no registered format name, so self-describing ones are emitted (`iban`, `bic`,
    …). The `pattern` is emitted only where the rule matches its PCRE against the **raw** value. Where the rule
    normalises first — `Iban` strips spaces and upper-cases; `Bic`/`Swift`/`Cusip`/`Isin` upper-case;
    `Luhn`/`RoutingNumber` strip separators — the pattern is deliberately withheld, because publishing the
    post-normalisation pattern would reject payloads the server accepts. Under-specifying is the lesser error.

### Nested DTOs are `$ref` components, never inlined

A member whose declared type is a class that `TypeSchema` does not resolve becomes its own component and a `$ref`.
`SchemaRegistry` exists for the two problems an inlining generator has:

**Duplication.** A DTO used by six operations would be emitted six times, and every generated client would mint six
structurally identical anonymous types with six different names. Registering once and referring by `$ref` is what
makes `openapi-generator`/`orval`/`kiota` produce **one named type per DTO**, which is the whole point of
generating the document.

**Recursion.** `SelfReferential { #[Valid] ?SelfReferential $parent; }` cannot be inlined at all — the expansion
does not terminate. So `ref()` **reserves the component name before invoking the builder**, and a nested call for
the same class finds the name taken and returns the reference immediately, closing the cycle.

Component **names** are the class's short name, because that is what a human reads in a viewer and what a generator
turns into a type name. Two DTOs sharing a short name across namespaces (`Order\Dto\Address` and
`Billing\Dto\Address`) would collide, so the **second** claimant falls back to its dotted fully-qualified name —
ugly, unambiguous, and rare. First claimant wins, so adding a second `Address` elsewhere never renames the one
already published.

A **nullable** nested DTO is spelled as the union it actually is:

```json
{ "anyOf": [ { "$ref": "#/components/schemas/Address" }, { "type": "null" } ] }
```

not as a `$ref` with a sibling `type`. In 2020-12 a *validation* keyword beside a reference is applied **with** it,
so `type: "null"` would have to pass as well as the reference and could never hold. Annotations are the opposite
case — a `description` beside a `$ref` is legal — which is why the prose below is applied to either shape.

Where the nested class has its own manifest entry (the normal case: the compiler compiles every class under the
app's scan roots, not just body DTOs) its own rules are used. Where it does not, the parent's dotted
`#[Valid]`-cascaded keys are **unflattened back into it**, so a nested schema is still constrained rather than a
bare `type: object`.

### `list<X>` element types come from the constructor docblock

PHP's `array` says nothing about what is in it, so `#[Valid] public readonly array $lines = []` documented itself
as a bare `type: array` with no `items` — which a client generator faithfully turns into `Array<any>`, a typed
client with an untyped hole in exactly the member that most needed a type.

The element class is not missing information, though. It is written in the **constructor docblock**, and
`packages/web` already reads it: `RouteScanner::dtoShapes()` resolves it at `firefly:cache` time and compiles it
into the body binding's `dtos` table so `ArgumentResolver` can hydrate the nested payload without reflecting.
That table is a `class => member => {class, list}` map covering **every class reachable from the body DTO, at any
depth**, and it is the first thing the generator consults — because it is not a copy of the answer, it *is* the
answer the hydrator uses. A document generated from it cannot describe a shape the server would refuse to build.

Three spellings are recognised, and they all mean the same payload:

```php
/**
 * @param  list<OrderLineRequest>  $lines       The lines to order, at least one.
 * @param  OrderLineRequest[]      $legacy      The same thing, the older way.
 * @param  array<int, Fulfilment>  $channels    A keyed array works too; the key type is ignored.
 */
public function __construct(
    #[Valid] public readonly array $lines = [],
    public readonly array $legacy = [],
    public readonly array $channels = [],
) {}
```

A short name is resolved the way PHP would resolve it: an already-qualified name as-is, then the declaring class's
own namespace, then the file's `use` imports. A name that does not resolve to a real class is **dropped entirely**
rather than emitted as a dangling `$ref` — the same choice `RouteScanner` makes when it leaves such a member out
of the hydration table.

Only a parameter **declared `array`** may take an element type from a comment. A class-typed member is a nested
DTO already resolved from its declared type, and `iterable` is excluded because the scanner excludes it: giving
`items` to a member the hydrator does not bind as a list would describe a request the server cannot accept.

What the element becomes depends on what it is:

| Element | `items` |
|---|---|
| A DTO | `{"$ref": "#/components/schemas/OrderLineRequest"}` — its own component, like any nested DTO |
| A backed enum, a `DateTimeInterface`, a scalar | **inlined** — an enum is not a reusable component, and minting one per enum would hand every generated client a named type where an inline union is what the payload is |
| Something `TypeSchema` cannot resolve | no `items` at all, rather than an empty `{}` — both say "any element", and the absent one avoids a later `[]`-vs-`{}` decision |

A list of DTOs recurses safely for the same reason a plain nested DTO does: `SchemaRegistry` reserves the
component name *before* the builder runs, so `CategoryNode { list<CategoryNode> $children }` closes its own cycle
on the component being built instead of expanding forever.

`items` is seeded into the **base** fragment rather than layered on afterwards, so the constraint mapper's
first-writer-wins ordering sees a complete declared-type fragment — and so a `#[Size]` on the member still
resolves against the `type: array` sitting beside it and becomes `minItems`/`maxItems` rather than
`minLength`/`maxLength`.

!!! note "Rules for a list element come from the element's own manifest entry"
    Never from the parent's dotted `#[Valid]` keys. `ConstraintScanner` cascades a `#[Valid]` only through a
    **class-typed** member, so a parent's dotted keys can never describe a list element in the first place — and
    unflattening a Laravel-style `lines.*.sku` into an element schema would invent a member literally named
    `*.sku`. The element is constrained because the compiler compiled *its* class too, not because its parent
    mentioned it.

!!! warning "There is a second, reflective path — and it is only ever a fallback"
    Three reachable shapes carry no compiled table: a DTO named by `#[ApiResponse(type:)]` (a response has no
    binding plan at all), a DTO handed straight to `DtoSchemaFactory::ref()` by something other than a request
    body, and a route manifest compiled before the scanner emitted the `dtos` key — a supported state, since that
    key is written only when a body DTO actually nests. In all three the element type is still sitting in the
    docblock, and the choice is between reading it and shipping `Array<any>` again. The scanner's resolution is
    private to `packages/web` and reachable only through a compiled binding, so it is **mirrored** rule for rule.
    Two implementations of one rule is a real cost; the alternative was a generator whose output silently
    depended on whether a route happened to reach the class. The mirror is deliberately *not* consulted when the
    table has a row for the class: a row is complete, so a member missing from it is a member the hydrator will
    not treat as a list, and second-guessing that with reflection is how the two paths would drift.

### The prose half

The schema's `description` is the DTO's class docblock. A member's is resolved in this precedence:

1. `#[ApiProperty(description:)]` — the author said it explicitly;
2. the member's **own** docblock;
3. the constructor's `@param` line for it.

That order is the one people expect from reading a file top to bottom — the closer a statement sits to the member,
the more specific it is. The `@param` fallback matters more than it looks: a promoted constructor property is where
most LaraFly DTOs put everything, and `@param` is the only place PHPDoc lets you describe one without inventing a
property docblock for a parameter.

**Nothing is invented.** A member with no description in any of the three sources gets **no `description` key**,
rather than a humanised restatement of its own name — `"quantity": {"description": "Quantity"}` is noise that costs
a reader a second to dismiss and costs the file a line per property forever. The schema-level fallback is the one
exception: a DTO with no class docblock gets `Request payload bound from App\Dto\X.`, which is a *locator* telling
you which PHP file to open, not documentation — which is exactly why any real docblock beats it.

`#[ApiProperty]`'s `format` **overwrites** a constraint-derived one, on the grounds that an author naming a format
is making the more precise statement. Examples are emitted as the **plural array** form, `examples: [...]`: 3.1
aligned the Schema Object with JSON Schema 2020-12, whose keyword is `examples`, and explicitly deprecated the
singular `example` inherited from 3.0.

A constructor **default** is copied into `default` only when it is a JSON value — a scalar, `null`, or a list of
scalars. An object or enum default (a promoted `new Money(0)`, say) has no JSON spelling a client could send back,
and emitting a serialised approximation would be a `default` the server never applies.

## Determinism, and the `{}`-vs-`[]` trap

Paths are sorted, verbs within a Path Item are sorted into the canonical OpenAPI order, and `SchemaRegistry` sorts
components by name. Route discovery order depends on filesystem iteration, so an unsorted document would reshuffle
itself between machines and turn every regeneration into an unreviewable diff — which is what makes teams stop
committing the generated file, which is what makes it go stale.

`generate()` returns plain PHP arrays (pleasant to assert against); `toJson()` is the canonical serialisation and
the one that must produce any file or HTTP body. PHP cannot tell an empty map from an empty list, so
`json_encode([])` is `[]` — and `"paths": []` or an unconstrained property serialised as `[]` are both type errors
against the 3.1 meta-schema that make a strict validator reject an otherwise perfect document. `toJson()`
therefore re-encodes empty arrays as `{}`.

That rewrite used to be **unconditional**, justified by a claim that quietly stopped being true — "nothing in this
document ever emits an empty *list*". A constructor default does. `array $lines = []` is documented as
`default: []`, the rewrite turned it into `"default": {}`, and the document then told every client that omitting
`lines` yields an empty **object** for a member the same schema declares `type: array` two lines above. A generated
client either fails to compile against its own type or ships a wrong default.

The fix draws the line the rewrite always meant to draw, between **structure** and **data**. `default`, `const` and
`example` hold one instance value; `enum` and `examples` hold a list of them. Those are values the schema
*describes*, not part of the document's own shape, so an empty one is typed by the sibling `type`: `type: array`
(or the 3.1 nullable spelling `type: [array, "null"]`) makes it a JSON array, and anything else falls back to the
structural `{}`.

Requiring the schema to have *said* `array`, rather than trusting the PHP value, is what keeps the exception
narrow. Those keywords are also perfectly legal DTO member names, so `properties: {"default": {}}` is a reachable
node, and a rule of "an empty array under one of these keys is always a list" would turn that member's own empty
schema into an invalid `[]`. The cost is one genuinely ambiguous case — a `mixed` member with an array default,
which declares no type for anything to decide from. Nothing recurses into a *non-empty* instance either:
`json_encode`'s own list-vs-map rule is already right for it, and rewriting a caller's example payload would
corrupt their empty arrays.

Everything structural still holds: `required`, `tags`, `parameters`, `servers`, `allOf` and the constraint
extension are each omitted entirely rather than emitted empty.

## `php artisan firefly:openapi`

```bash
php artisan firefly:openapi --output=docs/openapi.json   # writes the file, prints a summary line
php artisan firefly:openapi > openapi.json               # writes the raw document to stdout
```

The command exists so the document can be a **build artifact** rather than only a live endpoint. Committing the
generated file is what lets a CI job diff it and fail a pull request that changed the public API without saying so,
and what lets a front-end repository regenerate its typed client from a checked-in spec without booting the PHP
application at all. It is also the only way to get a document out of a deployment that keeps
`firefly.openapi.enabled` off in production.

Stdout is written with Symfony's `OUTPUT_RAW`, and that detail is load-bearing: console output normally goes
through Symfony's formatter, which treats `<…>` as markup, so any angle bracket reaching the document from a
docblock or a config value would either be swallowed or throw on an unknown tag. The point of stdout mode is
`firefly:openapi | <generator>`, so the bytes must be exactly the bytes of the document. It is also why the
confirmation line prints **only** in `--output` mode, where stdout is not the document.

Parent directories of `--output=` are created; a failure to create or write reports an error and returns a non-zero
exit code.

## The viewer, and the three styles

`GET /openapi` renders a browser console. `firefly.openapi.viewer.style` selects which one, and only one of the
three makes a request to a third party.

| `style` | Ships from | Third-party request at page view? | Notes |
|---|---|---|---|
| `swagger` **(default)** | your own origin, out of the `swagger-api/swagger-ui` composer package | **no** | The official Swagger UI, byte-for-byte |
| `builtin` | inline in the response | **no** | Hand-written, no third-party JavaScript at all |
| `cdn` | `cdn.jsdelivr.net` | **yes, on every view** | Swagger UI at a pinned version; no SRI claimed |

An unrecognised value falls back to `swagger` rather than rendering a blank page.

### Why `swagger` from your own origin is the default

Every off-the-shelf viewer — Swagger UI, Redoc, Elements — is a bundled JavaScript application, which historically
left a PHP package two options: vendor a multi-megabyte bundle into its own git history, or fetch it from a CDN on
every page view. The second is a supply-chain dependency and a data-protection question, and it renders **nothing
at all** in the air-gapped and strict-CSP environments where an internal API console is most wanted.

`swagger-api/swagger-ui` publishes the `dist` on Packagist under Apache-2.0, so there is a third option and this
package takes it: composer fetches and pins the official distribution, and `SwaggerAssetAction` serves it from the
application's own origin. No CDN, no npm, no bundle in this repository's history, and the UI is exactly the one
Swagger publishes — full feature set, deep linking, try-it-out, OAuth2 redirect.

`swagger-api/swagger-ui` is a hard `require` of `firefly/openapi`, so the files are already on disk. If they are
somehow absent — a stripped `vendor/`, a phar, a non-composer runtime — `ViewerPage` falls back to `builtin` rather
than rendering a console whose assets 404.

Asset serving is a **whitelist**, not a sanitiser: only seven basenames are servable
(`swagger-ui.css`, `swagger-ui-bundle.js`, `swagger-ui-standalone-preset.js`, `oauth2-redirect.html`,
`favicon-16x16.png`, `favicon-32x32.png`, `index.css`), each resolved path is `realpath()`-checked to be inside the
dist directory, and the route itself constrains `{file}` to `[A-Za-z0-9._-]+` so it cannot even express a
traversal. A whitelist cannot be defeated by an encoding trick a sanitiser missed. Anything else is a plain 404
(`text/plain`, deliberately not problem+json — the caller is a browser fetching a stylesheet, not an API client).
Assets are immutable for a pinned version, so they are sent `public, max-age=31536000, immutable` with an auto
ETag; composer changes the bytes only when the pinned version changes.

### What `cdn` costs

```php
'openapi' => ['viewer' => ['style' => 'cdn']],
```

Every page view then loads Swagger UI from `cdn.jsdelivr.net`. The version is pinned exactly; **no Subresource
Integrity hash is claimed**, deliberately — a hash the framework cannot verify at release time is security theatre,
and a wrong one simply breaks the page. In exchange for a third-party request, a CSP that must allow `cdn.jsdelivr.net`,
and a console that renders nothing in an air-gapped deployment, you get… the same Swagger UI `swagger` already gave
you from your own origin. The style is kept for parity with what most tutorials show, and because some deployments
prefer their bytes to come from a cache they already trust.

`firefly.openapi.viewer.cdn` is the older boolean spelling of this. It still **forces** the CDN page and wins over
`style`, so an application that set it before `style` existed keeps the behaviour it configured; prefer `style` in
new configuration.

### What `builtin` is for

A hand-written, dependency-free reference: one inline `<script>`, a few hundred bytes of CSS, one `fetch` of the
spec route, and a dark/light palette that follows `prefers-color-scheme`. It does the two things a reader actually
needs and raw JSON does not give them — groups operations by tag with verbs and paths visible at a glance, and
resolves `$ref` pointers client-side so a reader sees a DTO's members rather than a pointer into
`#/components/schemas`. Try-it-out, OAuth flows and code samples are deliberately absent; that is what `swagger`
is for. Choose it when the deployment wants no third-party JavaScript in the response at all.

The viewer fetches the spec from the sibling route rather than having the document inlined, so an edit-and-reload
cycle shows up on a browser refresh, and so the two routes can be exposed independently — a deployment may want the
machine-readable document public and the console off, or the reverse. The spec URL is resolved through the
`UrlGenerator` rather than concatenated, because an app mounted under a subdirectory or behind `APP_URL` would
otherwise get a link that 404s from every page but the root.

## Configuration (`firefly.openapi.*`)

| Key | Default | Meaning |
|---|---|---|
| `firefly.openapi.enabled` | `true` | Master gate. Off means all three routes are genuinely **unrouted**, not blank. |
| `firefly.openapi.path` | `'/openapi.json'` | The spec route. Registered with the leading slash stripped, because Illuminate's `Router` does that itself. |
| `firefly.openapi.viewer.enabled` | `true` | Mount the console and its assets. The spec route stays mounted either way. |
| `firefly.openapi.viewer.path` | `'/openapi'` | The console route; assets are mounted under `{path}/assets/{file}`. |
| `firefly.openapi.viewer.style` | `'swagger'` | `swagger` \| `builtin` \| `cdn`. Unrecognised values fall back to `swagger`. |
| `firefly.openapi.viewer.cdn` | `false` | Legacy boolean. `true` forces the CDN page and **overrides `style`**. |
| `firefly.openapi.title` | `'API'` | Info Object `title`. |
| `firefly.openapi.version` | `'0.0.0'` | Info Object `version`. |
| `firefly.openapi.description` | `''` | Info Object `description`; omitted from the document when empty. |
| `firefly.openapi.summary` | `''` | Info Object `summary` — the 3.1 short-form line beside `description`. Trimmed; an empty value is "not configured" and is never emitted as an empty member. |
| `firefly.openapi.terms-of-service` | `''` | Info Object `termsOfService`. Same trim-and-omit rule. |
| `firefly.openapi.contact.name` \| `.url` \| `.email` | `''` | Info Object `contact` members. The object is emitted only if at least one is set, carrying only the ones that are. |
| `firefly.openapi.license.name` | `''` | Info Object `license`. **`name` is the gate** — with it empty, no `license` is emitted at all, because the 3.1 License Object requires it. |
| `firefly.openapi.license.identifier` \| `.url` | `''` | The other two License members. They are mutually exclusive in 3.1, so `identifier` wins where both are set and `url` is dropped rather than emitting an invalid object. |
| `firefly.openapi.servers` | `[]` | Bare URL strings and/or OpenAPI Server Objects. An entry that is neither — or an object with no `url` — is **dropped**, because it would be invalid under the 3.1 schema and would poison an otherwise-good document. Omitted from the document when empty. |
| `firefly.openapi.exclude` | `''` | CSV of path **prefixes** left out of the document. Removes them from the spec only; it does not unroute them. |
| `firefly.openapi.include-html` | `false` | Document `#[Controller]` HTML routes as `text/html` operations. |

The optional Info Object members live on `DocumentInfo` rather than on `OpenApiProperties`, and its constructor
argument is last and nullable, so every existing three-argument `OpenApiGenerator` construction — the auto-
configuration's `#[Bean]`, an application's own override bean, the fixtures — keeps producing exactly the document
it produced before. `applyTo()` then **rebuilds** the Info Object's key order rather than appending, into the order
the specification itself lists: `title, summary, description, termsOfService, contact, license, version`. Nothing
consumes that order semantically; a human diffing a committed `openapi.json` does, and `title, version,
description, summary` reads as an afterthought where the spec's own order reads as a table. Any non-spec member an
override bean put into `info` — a `x-` specification extension, say — survives, after the spec ones.

`OpenApiProperties` is read **once**, at `BootPhase::FlushDefinitions`, into an immutable value object — the same
lifetime `ExposureModel` has in `firefly/actuator`, and for the same reason: the registrar mounts routes from
`specPath`/`viewerPath` at `WiringPasses`, so a post-boot `config()->set()` on those keys could not move an
already-mounted route anyway.

## Securing the surface

The three routes are ordinary routes, and `firefly/security`'s `HttpSecurityFilter` is a **global** middleware
pushed onto Laravel's HTTP-kernel stack, so it runs for them exactly as it runs for your controllers. Locking the
documentation down is therefore pure configuration, with no code edge — the same story as
[Actuator](actuator.md):

```php
'firefly' => [
    'security' => [
        'enabled' => true,
        'http' => [
            'enabled' => true,
            'rules' => [
                ['pattern' => 'openapi', 'access' => 'hasRole:DEVELOPER'],
                ['pattern' => 'openapi/*', 'access' => 'hasRole:DEVELOPER'],
                ['pattern' => 'openapi.json', 'access' => 'hasRole:DEVELOPER'],
            ],
        ],
    ],
],
```

Note the three patterns: `openapi` alone does not match `openapi/assets/swagger-ui.css`, and `openapi.json` is a
separate literal. A rule that covers the console but not its assets produces an authenticated page whose stylesheet
401s.

The alternative, for a deployment that wants no documentation surface in production at all, is
`firefly.openapi.enabled => false` plus a `firefly:openapi --output=` step in CI.

## Overriding a piece of the pipeline

Every collaborator is a `#[Bean]` behind `#[ConditionalOnMissingBean]`, so replacing one is a short
`#[Configuration]` in the application and never a fork:

```php
#[Configuration]
final class ApiDocsConfiguration
{
    #[Bean]
    public function constraintSchemaMapper(): ConstraintSchemaMapper
    {
        return new HouseConstraintSchemaMapper; // teaches the generator your own ValidationRules
    }
}
```

`OpenApiProperties`, `ConstraintSchemaMapper`, `DtoSchemaFactory`, `OperationFactory`, `OpenApiGenerator` and
`ViewerPage` are all overridable this way. The pipeline is six beans rather than one god object precisely because
swapping the *whole* generator is rarely what anyone wants, whereas replacing just the constraint mapper (to teach
it a house `ValidationRule`) or just `ViewerPage` (to ship a corporate console) is exactly what they want.

## A note on reflection

LaraFly's rule is that nothing on the cached **request** path reflects. This package honours it. `DtoSchemaFactory`
reflects a DTO's constructor to learn its property types, but that work runs when `firefly:openapi` generates a
file, or on a hit to the spec route — whose result the generator **memoises for the life of the process** — and
never while dispatching an application request. It is the same category of work as `RouteScanner` and
`ConstraintScanner`, both of which reflect at compile time only.

Teaching `RouteScanner` to emit per-property types into every `RouteDescriptor` was rejected: it would grow the
compiled route manifest of *every* application for the benefit of one optional package.

## Laravel comparison

| Concern | Plain Laravel | LaraFly (`firefly/openapi`) |
|---|---|---|
| Where the spec comes from | a second description — `zircote/swagger-php`'s `@OA\` blocks, attribute classes, or a hand-kept YAML file | the same `RouteManifest` the dispatcher dispatches from and the same `ConstraintManifest` the validator validates with |
| Drift | invisible: the document still validates, it just no longer matches the server | structurally impossible — there is no second source |
| Request-body schemas | re-declared beside the FormRequest that enforces them | derived from the compiled constraints |
| Error responses | documented by hand, if at all | one shared `Problem` component describing what `ProblemDetailsRenderer` actually returns |
| A browser console | a third-party package, usually CDN-backed | official Swagger UI from your own origin, no npm, no CDN |
| The nearest analogue | `php artisan route:list` — accurate for the same reason, and unable to say anything about a body | springdoc-openapi, outside PHP |

## Known-latent

- **A success body typed `array` documents as `type: object`.** The success schema comes from the declared return
  type, and LaraFly controllers commonly return `array`. Return a DTO (or a backed scalar) where the response
  shape matters to a generated client; a `@return array{…}` docblock is deliberately not read.
- **`x-firefly-constraints` is the escape hatch, not a vocabulary.** Anything JSON Schema cannot state lands there
  verbatim; no attempt is made to translate a checksum rule or a temporal predicate into an approximation that
  would be wrong.
- **`webhooks`, `security` schemes and `callbacks`** are not emitted — `firefly/security`'s configuration is not
  reachable from this package without a code edge that `deptrac.yaml` deliberately does not permit.

---

See also: [Web Layer](web.md) for `RouteManifest` and the binding plan, [Validation](validation.md) for
`ConstraintManifest`, [Error Handling](error-handling.md) for the problem-details shape, and
[Admin Dashboard](admin.md) for the other browser surface LaraFly ships.
