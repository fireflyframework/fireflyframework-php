<span class="eyebrow">Part I — Foundations · Chapter 4A</span>

# Documenting the API: OpenAPI 3.1 from the Manifests {.chtitle}

By the end of this chapter you will know how `firefly/openapi` turns the `RouteManifest` and `ConstraintManifest` Chapter 4 just built into a valid OpenAPI 3.1 document with **no annotation dialect of its own** — how `firefly:openapi` makes that document a build artifact a CI job can diff, how each binding `kind` in the compiled route plan becomes a Parameter Object or a Request Body, how a `#[NotBlank]` or a `#[Positive]` you already wrote becomes a `pattern` or an `exclusiveMinimum`, why every DTO is registered once and reached by `$ref` rather than inlined, why every operation carries the same `problem+json` error component Chapter 4's renderer produces, and why a `#[Controller]` HTML route is left out of the document by default.

!!! note "New term: specification extension"
    OpenAPI 3.1 lets a document carry members whose names begin with `x-`, called **specification extensions**. Conforming tools must ignore them, so an extension can record something the standard vocabulary cannot express without making the document invalid. `firefly/openapi` uses exactly one, `x-firefly-constraints`, and this chapter shows what lands in it and why nothing is ever dropped silently instead.

---

## Nothing to annotate

Every OpenAPI toolchain in PHP that predates this one asks you to write the document twice: once as code the server runs, and once as annotations, attributes or a YAML file that *describe* the code the server runs. The two drift the first time somebody adds a field in a hurry, and the drift is invisible — the document still validates, it just no longer matches the server.

`firefly/openapi` does not have that failure mode available to it, because it does not have a second source. Chapter 4 ended with `RouteManifest`: the compiled table of every `RouteDescriptor` the dispatcher serves from, carrying the verb, the path, the declared status, the route name, the controller class and method, and the per-parameter *binding plan*. Chapter 4 also introduced `ConstraintManifest`: the compiled rule list `BeanValidator` runs on a `#[Valid]` body. Those two artifacts, plus `firefly/kernel`'s `ErrorResponse`, are the entire input:

```bash
composer require firefly/openapi
```

That is the whole installation. Boot the app and `GET /openapi.json` is served; `GET /openapi` renders a reference console over it. Nothing was annotated, and nothing can drift, because every fact in the document is read from the same compiled artifact the dispatcher reads.

---

## `firefly:openapi`, and two routes that are not attribute routes

The document is also a file you can commit:

```bash
php artisan firefly:openapi --output=docs/openapi.json   # writes the file, prints a summary line
php artisan firefly:openapi > openapi.json               # writes the raw document to stdout
```

The command exists so the document can be a **build artifact** rather than only a live endpoint. Committing the generated file is what lets a CI job diff it and fail a pull request that changed the public API without saying so, and what lets a front-end repository regenerate its typed client from a checked-in spec without booting the PHP application at all. It is also the only way to get a document out of a deployment that keeps `firefly.openapi.enabled` off in production.

Stdout mode is written with Symfony's `OUTPUT_RAW` flag, and that detail matters more than it looks: console output normally goes through Symfony's formatter, which treats `<...>` as markup. A `description` mentioning a generic type — anything carrying an angle bracket that reached the document from a config value — would either be swallowed or would throw on an unknown tag. The point of stdout mode is to pipe straight into a client generator, so the bytes must be exactly the bytes of the document. That is also why the confirmation line is printed **only** in `--output` mode, where stdout is not the document.

The two HTTP routes are mounted natively on the Illuminate `Router` from a `BootPass`, not declared with `#[GetMapping]`:

```php
final class OpenApiRouteRegistrar implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 60;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;

        /** @var OpenApiProperties $properties */
        $properties = $container->make(OpenApiProperties::class);

        if (! $properties->enabled) {
            return;
        }

        /** @var Router $router */
        $router = $container->make('router');

        $router->get($properties->specPath, static fn (): mixed => $container->make(OpenApiSpecAction::class)())
            ->name('firefly.openapi.spec');

        if (! $properties->viewerEnabled) {
            return;
        }

        $router->get($properties->viewerPath, static fn (): mixed => $container->make(OpenApiViewerAction::class)())
            ->name('firefly.openapi.viewer');
    }
}
```

This is the same shape — and the same `BootPass` idiom — Chapter 11 will show you for the actuator's own routes, and it is chosen for two independent reasons. First, **an attribute route cannot be configurable**: `#[GetMapping('/openapi.json')]` bakes its literal into a compiled `RouteDescriptor` at `firefly:cache` time, so an operator could never move the spec off a path that collides with one of their own, and could never take it off a public surface without deleting the package. Second, an attribute route would enter the application's `RouteManifest` — and the generator reads that manifest, so **the package would document itself**. Registering natively keeps both problems out of existence: the two paths come from config at boot, and they never appear in the spec they serve.

`firefly.openapi.enabled` (default `true`) is enforced *here*, on the routes, rather than on the beans — the generator and its collaborators are inert without routes, so gating the routes is the whole of the switch. Turning it off leaves both paths genuinely unrouted, so they 404 through the router's own `NotFoundHttpException`, which Chapter 4's `ProblemDetailsRenderer` then renders as a proper `404` problem-details body rather than a `500`.

!!! note "The actions are resolved per request, inside the closure"
    Building an `OpenApiSpecAction` at boot and capturing it in the route would freeze one `OpenApiGenerator` into the route for the process's lifetime — exactly the shape that breaks under Octane, where a later request's container is a different sandbox. `$container->make(...)` *inside* the closure is the rule, here and in every other framework package that mounts a native route.

---

## From `RouteManifest` to Operation Objects

`OpenApiGenerator::generate()` is the entry point — it memoises a single private `build()` pass (`$this->document ??= $this->build()`), and `toJson()` wraps it for a file or an HTTP body. That pass walks the manifest once, skipping excluded routes, and hands each survivor to `OperationFactory`. Everything about the result is deterministic on purpose:

```php
final class OpenApiGenerator
{
    private const array VERB_ORDER = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    private function sortVerbs(array $item): array
    {
        $sorted = [];

        foreach (self::VERB_ORDER as $verb) {
            if (array_key_exists($verb, $item)) {
                $sorted[$verb] = $item[$verb];
                unset($item[$verb]);
            }
        }

        ksort($item);

        return [...$sorted, ...$item];
    }
}
```

Paths are sorted, verbs within a path are sorted into the canonical order the OpenAPI specification itself lists them in, and the schema registry sorts components by name. This is not tidiness for its own sake. Route discovery order depends on filesystem iteration, so an *unsorted* document would reshuffle itself between machines and turn every regeneration into an unreviewable diff — which is exactly what makes teams stop committing the generated file, which is what makes it go stale.

Inside an operation, the binding plan does the real work. `OperationFactory` dispatches on the same `kind` discriminator `ArgumentResolver` uses at request time:

```php
final class OperationFactory
{
    public function create(RouteDescriptor $route, string $operationId, SchemaRegistry $registry): array
    {
        $parameters = [];
        $body = null;
        $files = [];
        $validated = false;
        $rejectable = false;

        foreach ($route->bindings as $binding) {
            $validated = $validated || $binding['valid'];

            switch ($binding['kind']) {
                case 'path':
                    $parameters[] = $this->parameter($binding, 'path', true);
                    $rejectable = $rejectable || $this->coercible($binding);
                    break;
                case 'query':
                    $parameters[] = $this->parameter($binding, 'query', $binding['required']);
                    $rejectable = $rejectable || $binding['required'] || $this->coercible($binding);
                    break;
                case 'header':
                    $parameters[] = $this->parameter($binding, 'header', $binding['required']);
                    $rejectable = $rejectable || $binding['required'] || $this->coercible($binding);
                    break;
                case 'file':
                    $files[] = $binding;
                    $rejectable = true;
                    break;
                case 'body':
                    $body = $binding;
                    $rejectable = true;
                    break;
            }
        }

        $operation = [
            'operationId' => $operationId,
            'summary' => $this->summary($route),
            'description' => 'Handled by '.$route->controllerClass.'::'.$route->methodName.'().',
            'tags' => [$this->tag($route)],
        ];

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($body !== null) {
            $operation['requestBody'] = $this->requestBody($body, $registry);
        } elseif ($files !== []) {
            $operation['requestBody'] = $this->multipartBody($files);
        }

        $operation['responses'] = $this->responses($route, $rejectable, $validated);

        return $operation;
    }
}
```

Reading the plan rather than re-reading the method signature is what makes the mapping unambiguous. `#[PathVariable]`, `#[QueryParam]` and `#[RequestHeader]` become Parameter Objects; `#[UploadedFile]` becomes a `multipart/form-data` part typed `format: binary`; `#[RequestBody]` becomes the Request Body Object; and the sixth `kind`, `service` — the no-attribute container-injected collaborator Chapter 4 introduced — is not part of the HTTP contract at all and never appears in the document. Deriving that list independently would have to re-decide every one of those cases and could disagree with the dispatcher; reading the plan cannot.

Four smaller decisions finish an operation:

- **Path template.** Laravel's optional-parameter spelling `{id?}` has no OpenAPI equivalent — a path parameter is required there, full stop — so the marker is stripped and the parameter stays required. Emitting two Path Items instead would describe a router surface that does not exist and would double every such operation in a generated client.
- **`operationId`.** The route's `name` when it has one, otherwise derived as the controller's short name minus a trailing `Controller`, lower-cased, plus the method name: `Lumen\Web\WalletController::balance()` becomes `walletBalance`. Because `operationId` must be unique across the whole document — and a duplicate is the one flaw that makes most client generators abort rather than degrade — a repeat claim is suffixed (`walletBalance_2`) rather than allowed to overwrite.
- **`tags`.** The same short name, so every `WalletController` operation groups under "Wallet" in a viewer.
- **`summary`.** Split from the method name: `getBalance` reads as "Get balance". A method name is the only human-authored label a route carries — a `#[Mapping]`'s `name` is a Laravel route name, not prose — so it is the honest source.

---

## Request bodies: one component per DTO, reached by `$ref`

Chapter 4's `OpenWalletRequest` is the running example again:

```php
final class OpenWalletRequest
{
    public function __construct(
        #[NotBlank]
        public readonly string $owner_id,
        #[NotBlank]
        #[CurrencyCode]
        public readonly string $currency,
    ) {}
}
```

`POST /api/v1/wallets` binds it with `#[Valid] #[RequestBody]`, and the generated operation refers to it rather than repeating it:

```json
{
  "requestBody": {
    "required": true,
    "content": {
      "application/json": {
        "schema": { "$ref": "#/components/schemas/OpenWalletRequest" }
      }
    }
  }
}
```

`SchemaRegistry` is what makes that `$ref` possible, and it solves two problems a naive "inline the schema at every use site" generator has. The first is **duplication**: a DTO used by six operations would be emitted six times, and every generated client would mint six structurally identical anonymous types with six different names. Registering once and referring by pointer is what makes `openapi-generator`, `orval` and `kiota` produce *one* named type per DTO — which is the whole point of generating the document in the first place. The second is **recursion**: a DTO with a `#[Valid] ?self $parent` member cannot be inlined at all, because the expansion does not terminate. The registry therefore reserves the component name *before* invoking the builder, so a nested call for the same class finds the name already taken and returns the pointer immediately, closing the cycle. A `$ref` cycle is legal and useful in a document where a flattened rule list would be infinite.

Component names are the class's short name, because that is what a human reads in a viewer and what a generator turns into a type name. Two DTOs sharing a short name across namespaces — `Order\Dto\Address` and `Billing\Dto\Address` — would collide and one would silently overwrite the other, so the **second** claimant of a name falls back to its dotted fully-qualified name: ugly, unambiguous, and rare. First claimant wins, so adding a second `Address` elsewhere in the app never renames the one that was already published.

Two properties of the emitted schema are worth stating explicitly because both are decisions rather than omissions. The member list is the **constructor's parameter list in declaration order**, because that is exactly what `ArgumentResolver` hydrates from — but a constraint keyed to a member with no constructor parameter is still documented, because `BeanValidator` validates the raw decoded array and so enforces it on input regardless. And **no `additionalProperties: false` is ever emitted**: the server genuinely ignores keys outside the constructor's list, so a spec claiming otherwise would make conforming clients reject requests the server would have served.

---

## Constraints become schema keywords

Neither half of a DTO is enough on its own. The `ConstraintManifest` knows the *validation* contract but nothing about types, because a rule list is untyped by construction. The constructor knows the *type* contract — `?int`, a backed enum, a nested DTO, a default — but nothing about the constraints. Types-only would document `#[NotBlank] string $name` as an unbounded string; constraints-only would document `int $quantity` as a string. `DtoSchemaFactory` merges them, and `ConstraintSchemaMapper` maps the second half.

It maps the **compiled manifest**, never the `#[Constraint]` attributes. Reading the attributes back off the DTO would be the obvious route to "`#[Email]` → `format: email`", and it would document a validator that does not exist: the manifest has already applied `ConstraintScanner`'s Jakarta null contract, already expanded `#[Size]` into a first-party rule *object* rather than Laravel's polymorphic `min:`/`max:` strings, and already flattened one `#[Valid]` level into dotted keys. Generating from the attributes would re-derive all of that by hand and drift from it the first time a `toRules()` body changed. Generating from the manifest cannot drift, because the manifest *is* the contract.

Here is the real mapping, constraint by constraint, with the compiled rules in the middle column so you can see that the mapper is reading rules and not attributes:

| Constraint | Compiles to | JSON Schema |
|---|---|---|
| `#[NotBlank]` | `required`, `string`, `regex:/\S/` | member added to `required`; `type: string`; `pattern: \S` |
| `#[NotEmpty]` | `required` | member added to `required` |
| `#[NotNull]` | `present` + a `NotNull` rule object | member added to `required` **and** `null` removed from the type union |
| `#[Min(n)]` / `#[Max(n)]` | `numeric`, `gte:n` / `lte:n` | `minimum` / `maximum` |
| `#[Positive]` / `#[Negative]` | `numeric`, `gt:0` / `lt:0` | `exclusiveMinimum: 0` / `exclusiveMaximum: 0` |
| `#[PositiveOrZero]` / `#[NegativeOrZero]` | `numeric`, `gte:0` / `lte:0` | `minimum: 0` / `maximum: 0` |
| `#[Email]` | `email` | `type: string`, `format: email` |
| `#[Pattern(re)]` | `regex:re` | `pattern`, with the PCRE delimiters and the no-op `D`/`u` flags stripped |
| `#[Digits(i, f)]` | `numeric`, an anchored `regex:` | `type: number` + `pattern` |
| `#[AssertTrue]` / `#[AssertFalse]` | `accepted` / `declined` | `type: boolean` + `const: true` / `const: false` |
| `#[Future]` / `#[Past]` | `date`, `after:now` / `before:now` | `type: string`, `format: date-time` (the `after:now` half is recorded, not mapped) |
| `#[Size(min, max)]` | a `Size` rule object | `minLength`/`maxLength`, or `minItems`/`maxItems` when the type is an array |
| `#[UuidValue]` | a `Uuid` rule object | `format: uuid` + the UUID `pattern` |
| `#[Phone]` | an `E164` rule object | `format: phone` + `pattern: ^\+[1-9]\d{1,14}$` |
| `#[CurrencyCode]` / `#[CountryCode]` | `Currency` / `CountryCode` rule objects | `format: currency` + `^[A-Z]{3}$` / `format: country-code` + `^[A-Z]{2}$` |
| `#[LanguageTag]` / `#[PostalCode]` | rule objects | `format: bcp47` / `format: postal-code`, each with its pattern |
| `#[Iban]` / `#[Swift]` / `#[Bic]` | rule objects | `format: iban` / `swift` / `bic`, **pattern withheld** |
| `#[Cusip]` / `#[Isin]` / `#[Luhn]` / `#[RoutingNumber]` | rule objects | `format` only; the check digit is recorded, not mapped |
| `#[Percentage]` | a `Percentage` rule object | `type: number`, `minimum: 0`, `maximum: 100` |
| `#[Money]` | a `PositiveMoney` rule object | `type: number`, `exclusiveMinimum: 0`, `multipleOf: 0.01` |
| `#[DecimalScale(n)]` | a `DecimalScale` rule object | `multipleOf` — scale 2 becomes `0.01` |

Three rows in that table repay a closer look.

**The pattern is withheld where the rule normalises first.** `Iban` strips spaces and upper-cases before it matches; `Bic`, `Swift`, `Cusip` and `Isin` upper-case; `Luhn` and `RoutingNumber` strip separators. Publishing the post-normalisation pattern would reject payloads the server happily accepts, which is worse than under-specifying — so the `format` is emitted and the pattern is not.

**`format` is an open vocabulary.** In JSON Schema 2020-12 an unknown `format` is an annotation, not an error. IBAN, BIC, ISIN, CUSIP and E.164 have no registered format name, so self-describing ones (`iban`, `bic`, …) are emitted rather than nothing.

**Nullability is spelled the 3.1 way.** OpenAPI 3.1 *is* JSON Schema 2020-12, which dropped 3.0's `nullable: true` keyword in favour of a type union. A nullable member is therefore `"type": ["string", "null"]`, and an `enum` additionally gains a `null` member — widening the type alone would leave `null` failing the enumeration, making the property undocumentable-as-null in practice.

The one rule that decides the whole merge is **first writer wins**, applied first to the declared type and then to the rules in declaration order — the same order the validator applies them in:

```php
final class MapperState
{
    public function keyword(string $keyword, mixed $value): void
    {
        if (! array_key_exists($keyword, $this->schema)) {
            $this->schema[$keyword] = $value;
        }
    }
}
```

Seeding the declared PHP type *before* any rule is seen is why `#[Min(1)] int $quantity` stays `type: integer` instead of being widened to `number` by the `numeric` rule string `#[Min]` emits — a widening that would wrongly document `1.5` as acceptable.

Here is that whole pipeline on one real DTO. This is the generator's own test fixture, chosen because it deliberately spans every mapping route the generator has — a length-bounded string, a Jakarta null-contract nullable, an int with numeric bounds, a scaled decimal, a backed enum, a nested `#[Valid]` DTO, a PCRE pattern and a rule object:

```php
final class CreateOrderRequest
{
    public function __construct(
        #[NotBlank] #[Size(max: 64)] public readonly string $reference,
        #[NotNull] #[Email] public readonly string $email,
        #[Min(1)] #[Max(999)] public readonly int $quantity,
        #[Positive] #[DecimalScale(2)] public readonly float $amount,
        public readonly Currency $currency,
        #[Valid] public readonly AddressPayload $shipTo,
        #[Pattern('/^[A-Z]{3}-\d{4}$/D')] public readonly ?string $coupon = null,
        #[UuidValue] public readonly ?string $idempotencyKey = null,
    ) {}
}
```

…and this is the component it generates, verbatim:

```json
{
  "type": "object",
  "title": "CreateOrderRequest",
  "properties": {
    "reference": { "type": "string", "maxLength": 64, "pattern": "\\S" },
    "email": { "type": "string", "format": "email" },
    "quantity": { "type": "integer", "minimum": 1, "maximum": 999 },
    "amount": { "type": "number", "exclusiveMinimum": 0, "multipleOf": 0.01 },
    "currency": { "type": "string", "enum": ["EUR", "USD"] },
    "shipTo": { "$ref": "#/components/schemas/AddressPayload" },
    "coupon": { "type": ["string", "null"], "pattern": "^[A-Z]{3}-\\d{4}$" },
    "idempotencyKey": {
      "type": ["string", "null"],
      "format": "uuid",
      "pattern": "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$"
    }
  },
  "required": ["reference", "email", "quantity", "amount", "currency", "shipTo"]
}
```

Every member of that object is traceable to something in the class above: the enum came from the backed enum's own cases, the `$ref` from `#[Valid]`, the two nullable unions from `?string`, the `multipleOf` from `#[DecimalScale(2)]`, and `required` from the presence rules — `coupon` and `idempotencyKey` are absent from it because nothing asserts their presence.

Lumen's own DTOs show the same machinery at a smaller scale, and one of them shows a case the table above cannot: a property carrying **two** patterns.

```json
{
  "owner_id": { "type": "string", "pattern": "\\S" },
  "currency": {
    "type": "string",
    "format": "currency",
    "allOf": [{ "pattern": "\\S" }, { "pattern": "^[A-Z]{3}$" }]
  }
}
```

`#[NotBlank] #[CurrencyCode] string $currency` genuinely produces two patterns — `\S` from the non-blank rule and `^[A-Z]{3}$` from the currency rule — and JSON Schema has exactly one `pattern` slot per schema object. Collapsing them by keeping the last would silently drop the non-blank guarantee, so several patterns become an `allOf` of single-pattern subschemas instead. One pattern stays a plain `pattern`; the `allOf` appears only when it has to.

---

## Nothing is dropped silently

Some constraints have no JSON Schema equivalent at all, and a few map only approximately. Dropping those quietly would produce a document that promises *less* validation than the server performs — a client would send a payload the spec calls valid and get a `422` back. The mapper's fall-through says what happens instead:

```php
final class ConstraintSchemaMapper
{
    private function applyObject(MapperState $state, ValidationRule $rule): void
    {
        switch (true) {
            // ... every recognised first-party rule object is matched above.
            default:
                // A third-party ValidationRule. Its class name is the only thing about it that is knowable
                // without executing it, so that is what the extension records.
                $state->unmapped($rule::class);
        }
    }
}
```

Everything unrecognised — `after:now` and `before:now`, `exists:` and `unique:`, a bare Luhn or CUSIP check digit, a bound that names another field (`gte:other_field`) rather than a number, and any third-party `ValidationRule` — is recorded under `x-firefly-constraints`. So is a pattern the mapper could only approximate: `D` and `u` are dropped as genuine no-ops, but any other flag — `i` above all, which ECMA-262 has no inline syntax for inside a pattern string — cannot be carried across, so the pattern is still emitted (it is the closest true statement available) *and* the original rule is recorded, so a reader can see that the published pattern is stricter than the server's.

Three properties, three outcomes:

```json
{
  "deliverAfter": { "type": "string", "format": "date-time", "x-firefly-constraints": ["after:now"] },
  "slug":         { "type": "string", "pattern": "^[a-z]+$", "x-firefly-constraints": ["regex:/^[a-z]+$/i"] },
  "account":      { "type": "string", "format": "iban", "x-firefly-constraints": ["iban:checksum"] }
}
```

Conforming tools ignore all three extensions and see a valid document. A human, or a generator you write yourself, can read the full truth.

---

## Responses: one problem component, and an error set that is derived

Every operation ends at the same error component, and that component describes what LaraFly *actually* returns rather than what RFC 9457 describes in the abstract:

```php
final class ProblemSchema
{
    public const string MEDIA_TYPE = 'application/problem+json';

    public static function response(): array
    {
        return [
            'description' => 'Error response in RFC 9457 problem+json form.',
            'content' => [self::MEDIA_TYPE => ['schema' => ['$ref' => self::REF]]],
        ];
    }
}
```

The distinction matters because the two shapes differ. Chapter 4's `ErrorResponse::toArray()` emits `status`, `title`, `code`, `category` and `severity` unconditionally, then `detail`/`type`/`instance`/`traceId`/`timestamp` only when non-null, then `errors` only when non-empty. So `code`, `category`, `severity` and `errors` are Firefly members on top of the RFC's five; `type` is optional here where the RFC gives it a default; and `instance` carries a request *path* rather than a URI reference. Documenting the RFC's shape instead of this one would hand every generated client a decoder that silently drops the three members a caller actually branches on.

The two enumerations are read straight off the kernel's own enums, so a case added in `firefly/kernel` appears in the spec on the next generation with no edit anywhere in `firefly/openapi`:

```json
{
  "category": {
    "type": "string",
    "enum": ["business", "validation", "security", "infrastructure",
             "external", "framework", "plugin", "internal"]
  },
  "severity": { "type": "string", "enum": ["info", "warning", "error", "critical"] }
}
```

Which statuses an operation lists is **derived, not guessed**. Compare two real Lumen operations. `GET /api/v1/wallets/{id}/balance` takes one `string` path variable and no body:

```json
{
  "200": { "description": "Successful response.",
           "content": { "application/json": { "schema": { "type": "object" } } } },
  "default": { "$ref": "#/components/responses/Problem" }
}
```

`POST /api/v1/wallets/{id}/deposit` takes the same path variable plus a `#[Valid] #[RequestBody] AmountRequest`:

```json
{
  "200": { "description": "Successful response.",
           "content": { "application/json": { "schema": { "type": "object" } } } },
  "400": { "$ref": "#/components/responses/Problem" },
  "422": { "$ref": "#/components/responses/Problem" },
  "default": { "$ref": "#/components/responses/Problem" }
}
```

`400` appears exactly when the operation has something `ArgumentResolver` can reject *before* the controller runs — a body to decode and bind, an upload to validate, a required query or header the client may omit, or a non-`string` parameter that has to be coerced out of the wire's string. It is deliberately absent from `balance`: nothing about that request can fail binding, because a missing path segment does not match the route at all, and a documented `400` an endpoint cannot produce is noise a generated client turns into a dead error branch. `422` appears exactly when some binding carries `#[Valid]`, because that is the only way `BeanValidator` runs and so the only way Chapter 4's `ValidationException` can be thrown. And `default` covers everything the handler itself may raise — a `404` from a `ResourceNotFoundException`, a `409` from a `ConflictException`, a `403` from a denied `#[PreAuthorize]` — which cannot be enumerated from the route manifest without reading the controller's body, and which all render through the same `ProblemDetailsRenderer` anyway.

The success body comes from the controller method's declared **return type**, the only place the shape of a successful response is stated anywhere in the framework. A `204`, or a `void`/`never` return, gets no content at all, because emitting a content map for a status that carries no body is exactly what a strict client generator turns into a phantom return type. LaraFly's common `array` return degrades to `type: object` rather than being expanded from a `@return array{...}` docblock: parsing PHPDoc here would make the generated document depend on comment text nothing else in the framework treats as binding.

---

## `#[Controller]` HTML routes are not operations

Chapter 4 introduced `#[RestController]` alongside its HTML sibling `#[Controller]`, and only the first is a JSON API. The generator honours that distinction by default:

```php
final class OpenApiGenerator
{
    private function excluded(RouteDescriptor $route): bool
    {
        if ($route->html && ! $this->properties->includeHtml) {
            return true;
        }

        foreach ($this->properties->excludePathPrefixes as $prefix) {
            if (str_starts_with($route->path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
```

A `#[Controller]` route renders a page. It is part of the application's HTTP surface, but it is not a JSON operation, and describing one as `application/json` would have a generator emit a typed client for a response that is a web page — the framework's own welcome page was in the spec exactly that way before this rule existed. Set `firefly.openapi.include-html` to `true` and the route is documented anyway, but honestly: the operation is then produced with `text/html` content and a `type: string` schema rather than a JSON schema that would be a lie a client generator faithfully acts on.

The second half of that method is the blunt instrument for everything else: `firefly.openapi.exclude` is a CSV of path prefixes — `'/internal,/admin'` — for routes that are JSON but are nobody's public API.

---

## The viewer, and the CDN flag

`GET /openapi` renders a reference console: a single self-contained HTML page with **no npm build at install time and no network access at request time**. It groups operations by tag and resolves `$ref` pointers client-side, so a reader sees a DTO's members rather than a pointer into `#/components/schemas`.

Every off-the-shelf viewer — Swagger UI, Redoc, Elements — is a bundled JavaScript application, which leaves exactly two ways to ship one: vendor a multi-megabyte bundle into a PHP package, or fetch it from a CDN on every page view. The second is a supply-chain dependency and a data-protection question, and it does not render at all in the air-gapped, strict-CSP environments where an internal API console is most wanted. Hence a hand-written, dependency-free default.

Swagger UI is available for teams that want the full feature set, at an exactly pinned version:

```php
// config/firefly.php
return [
    'openapi' => ['viewer' => ['cdn' => true]],
];
```

!!! warning "Turning the CDN flag on means the browser fetches code from a third party"
    `firefly.openapi.viewer.cdn` defaults to `false`. With it on, every page view loads Swagger UI from `cdn.jsdelivr.net`. No Subresource Integrity hash is claimed, deliberately: a hash the framework cannot verify at release time is security theatre, and a wrong one would simply break the page. The honest statement is the one in the package README — this is a request to a third party on every view.

---

## Configuration, and securing the surface

Everything the document and its routes need lives under one config key:

```php
<?php

declare(strict_types=1);

return [
    'openapi' => [
        'enabled' => true,              // master gate: off means both routes are genuinely unrouted
        'path' => '/openapi.json',      // spec route
        'viewer' => [
            'enabled' => true,
            'path' => '/openapi',
            'cdn' => false,             // opt in to Swagger UI over a CDN — see above
        ],
        'title' => 'Lumen Wallet API',
        'version' => '1.0.0',
        'description' => '',
        'servers' => ['https://api.example.test'],  // bare URLs or OpenAPI Server Objects
        'exclude' => '/internal,/admin',            // CSV of path prefixes to leave out
    ],
];
```

`servers` accepts both spellings a real config file uses — a list of bare URL strings, and OpenAPI's own object form with a `description` — and an entry that is neither is dropped rather than emitted, because a Server Object with no `url` is invalid under the 3.1 schema and would poison an otherwise-good document.

A public deployment is secured the way any other route is. Chapter 10's `HttpSecurity` rules — ahead, in Part III — cover the spec and viewer paths with no code edge at all, because `HttpSecurityFilter` is a global middleware and runs for natively-registered routes exactly as it runs for your controllers:

```php
<?php

declare(strict_types=1);

return [
    'security' => [
        'enabled' => true,
        'http' => [
            'enabled' => true,
            'rules' => [
                ['pattern' => 'openapi', 'access' => 'hasRole:DEVELOPER'],
                ['pattern' => 'openapi.json', 'access' => 'hasRole:DEVELOPER'],
            ],
        ],
    ],
];
```

The alternative, for a deployment that wants no documentation surface at all in production, is `enabled => false` plus a `firefly:openapi --output=` step in CI.

---

## Replacing one piece of the pipeline

Every collaborator in the package — `OpenApiProperties`, `ConstraintSchemaMapper`, `DtoSchemaFactory`, `OperationFactory`, `OpenApiGenerator` and `ViewerPage` — is a `#[Bean]` behind `#[ConditionalOnMissingBean]`, the Chapter 2 mechanism. Teaching the generator about your own `ValidationRule` is therefore a short `#[Configuration]` in your application and never a fork:

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

!!! note "The reflection here is real, and it is not on the request path"
    `DtoSchemaFactory` reflects a DTO's constructor to learn its property types, which looks like a violation of the rule Chapter 13 will state in full: nothing on the cached request path reflects. It is not one. That reflection runs when `firefly:openapi` generates a file, or on a hit to the spec route — whose result the generator memoises for the life of the process — and never while dispatching an application request. It is the same category of work as `RouteScanner` and `ConstraintScanner`, both of which reflect at compile time only. The alternative, teaching `RouteScanner` to emit per-property types into every `RouteDescriptor`, was rejected because it would grow the compiled route manifest of *every* app for the benefit of one optional package.

!!! laravel "Laravel parity"
    Stock Laravel ships no OpenAPI support. The usual answers are a third-party package driven by its own annotation dialect (`zircote/swagger-php`'s `@OA\` blocks, or `vyuldashev/laravel-openapi`'s attribute classes) or a hand-maintained YAML file — both of which are a *second* description of the API, sitting beside the routes and the FormRequests that actually enforce it, and both of which drift. `firefly/openapi` has no dialect to learn because it has no second description: it reads the same `RouteManifest` the dispatcher dispatches from and the same `ConstraintManifest` the validator validates with. The closest analogue outside PHP is springdoc-openapi, and the closest thing in Laravel itself is `php artisan route:list` — accurate for the same reason, and for the same reason unable to tell you anything about a request body.

---

## What you learned {.recap}

| Concept | What it does |
|---|---|
| `OpenApiGenerator` | Assembles an OpenAPI 3.1 document from `RouteManifest` + `ConstraintManifest`; memoised per instance, deterministically ordered so regenerations diff cleanly |
| `firefly:openapi` | Writes the document to `--output=` or raw to stdout, making the spec a committable build artifact a CI job can diff |
| `OpenApiRouteRegistrar` | Mounts `/openapi.json` and `/openapi` natively from a `BootPass` — a configurable path an attribute route could never have, and no self-documentation |
| `OperationFactory` | Maps each binding `kind` — `path`/`query`/`header`/`file`/`body` — to its OpenAPI shape; `service` bindings never appear |
| `SchemaRegistry` | One component per DTO, reached by `$ref`: no duplicate generated types, and a reserved name closes a recursive `$ref` cycle |
| `DtoSchemaFactory` | Merges declared constructor types with compiled constraints; emits no `additionalProperties: false`, because the server ignores extra keys |
| `ConstraintSchemaMapper` | Maps the compiled rule list — not the attributes — to keywords; first writer wins, so `#[Min(1)] int` stays `integer` |
| `x-firefly-constraints` | Records what JSON Schema cannot state (`after:now`, a checksum, a flagged PCRE, a third-party rule) instead of dropping it |
| `ProblemSchema` | The one shared `application/problem+json` response; documents Firefly's `code`/`category`/`severity`/`errors`, with the enums read off the kernel's own cases |
| Derived error set | `400` only when something is rejectable before the controller runs, `422` only under `#[Valid]`, `default` always |
| `$route->html` | `#[Controller]` HTML routes are excluded by default; `firefly.openapi.include-html` documents them as `text/html`, never as JSON |

---

## Try it yourself {.exercises}

1. **Generate Lumen's document and read it.** Run `php artisan firefly:openapi --output=openapi.json` in the sample, then open `/openapi` in a browser. Find `walletBalance` and confirm it has no `400` response, then find `walletDeposit` and confirm it has both a `400` and a `422` — and satisfy yourself, from this chapter's rules, why the two differ.
2. **Make the spec a CI gate.** Commit the generated file, then add a job that regenerates it and runs `git diff --exit-code` over it. Change a DTO — add a `#[Size(max: 32)]` to `OpenWalletRequest::$owner_id` — and watch the job fail with a diff that names the exact schema keyword that changed.
3. **Watch a constraint fall through to the extension.** Add `#[Future]` to a `string` property on a request DTO, regenerate, and find the property's `x-firefly-constraints` array carrying `after:now` beside a perfectly ordinary `format: date-time`. Then add `#[Pattern('/^[a-z]+$/i')]` to another property and compare: the pattern *is* published, and the original rule is recorded beside it because the `i` flag could not survive the translation.
