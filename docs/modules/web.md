# Web Layer

`firefly/web` is LaraFly's HTTP layer: `#[RestController]` routing compiled to a `RouteManifest`,
parameter binding with `#[Valid]` interception, JSON-native content negotiation, and RFC-7807 error
rendering — all dispatched through native Laravel routes, inside the real HTTP-kernel middleware
pipeline.

![Request lifecycle](../assets/diagrams/request-lifecycle.svg)

## `#[RestController]`

`#[RestController]` is a `#[Component]` stereotype (it extends `Firefly\Container\Attributes\Component`),
so a controller is discovered by the same component scan as any other bean and gets constructor
dependency injection for free — no separate controller-registration mechanism:

```php
use Firefly\Web\Attributes\{RestController, RequestMapping, GetMapping, PostMapping, PathVariable, QueryParam, RequestHeader, RequestBody};
use Firefly\Validation\Valid;

#[RestController]
#[RequestMapping('/accounts')]
final class AccountsController
{
    public function __construct(private readonly AccountService $accounts) {}

    #[GetMapping('/{id}', name: 'accounts.show')]
    public function show(
        #[PathVariable] int $id,
        #[QueryParam(default: 'summary')] string $view,
        #[RequestHeader('X-Trace')] ?string $trace = null,
    ): array {
        return $this->accounts->summarize($id, $view);
    }

    #[PostMapping(status: 201)]
    public function create(#[Valid] #[RequestBody] CreateAccountRequest $body): array
    {
        return $this->accounts->open($body);
    }
}
```

The `RouteScanner` (routing metadata) and the component scan (DI wiring) are two separate passes over
the same class — a `#[RestController]` never has to declare its own route registration.

## `#[RequestMapping]` and the verb mappings

`#[RequestMapping(path: '...')]` is class-level and prepends a base path to every method mapping on the
controller. Each method carries exactly one verb attribute, all implementing the `Mapping` marker
interface so the scanner discovers them polymorphically (`IS_INSTANCEOF`) with no per-verb scanner code:

| Attribute | HTTP method |
|---|---|
| `#[GetMapping]` | GET |
| `#[PostMapping]` | POST |
| `#[PutMapping]` | PUT |
| `#[PatchMapping]` | PATCH |
| `#[DeleteMapping]` | DELETE |

Each verb attribute accepts `path` (relative, joined to the class-level base), `status` (the default
response status — 200 for the read verbs, customizable e.g. `status: 201` for `#[PostMapping]`), and
`name` (an optional Laravel route name).

## Parameter binding

Each controller-method parameter is bound by exactly one of these attributes, plus two binding
conventions that need no attribute at all:

| Attribute | Source | Notes |
|---|---|---|
| `#[PathVariable(name?)]` | route segment | always required; defaults to the parameter name |
| `#[QueryParam(name?, default?, required?)]` | query string | not required by default |
| `#[RequestBody]` | request body | reads/decodes via the negotiated `MessageConverter`; combine with `#[Valid]` to gate on `BeanValidator` |
| `#[RequestHeader(name?, default?)]` | HTTP header | not required |
| `#[UploadedFile(name?)]` | `multipart/form-data` file | binds a framework-neutral `Firefly\Web\Http\UploadedFile` (filename/mimeType/size + lazy `contents()`/`store()`) |

Two parameters need no attribute at all:

- A **typed class parameter** (e.g. a service interface) with no binding attribute resolves as a
  **container service** — `$container->make($type)` — so a controller method can pull an extra
  collaborator straight from the container without adding it to the constructor.
- A **scalar parameter** with no binding attribute defaults to a **query parameter** keyed by the
  parameter's own name, required unless the parameter itself is optional.

Path and query values are coerced to the parameter's declared scalar type (`int`/`float`/`bool`); an
uncoercible value or a missing required input raises `InvalidRequestException` (HTTP 400,
`MISSING_PARAMETER` / `TYPE_CONVERSION_ERROR`), rendered like any other `FireflyException` — see
[Error Handling](error-handling.md).

## `#[Valid]` → `BeanValidator` → 422

Adding `#[Valid]` to a `#[RequestBody]` parameter gates the DTO on the Bean-Validation constraint layer
**before** the DTO is hydrated: the raw decoded body is validated by `Firefly\Validation\Constraint\BeanValidator`
against the DTO class's compiled rules, and only on success is the DTO constructed (from the raw body,
not the validator's `validated()` subset, so an unconstrained property is never silently dropped). A
failure throws the kernel's `ValidationException` — HTTP 422, rendered as `application/problem+json`
with one `FieldError` per failed field.

Constraints are per-property attributes on the DTO's constructor-promoted parameters (or plain typed
properties): `#[NotBlank]`, `#[NotEmpty]`, `#[NotNull]`, `#[AssertTrue]`/`#[AssertFalse]`, `#[Size]`,
`#[Min]`/`#[Max]`, `#[Digits]`, `#[Pattern]`, `#[Past]`/`#[Future]`, `#[Email]`, plus 16 financial-domain
wrappers (`#[Iban]`, `#[Bic]`, `#[Swift]`, `#[Isin]`, `#[Cusip]`, `#[RoutingNumber]`, `#[Luhn]`,
`#[CurrencyCode]`, `#[CountryCode]`, `#[LanguageTag]`, `#[UuidValue]`, `#[Phone]`, `#[PostalCode]`,
`#[Percentage]`, `#[Money]`, `#[DecimalScale]`) and a `#[Rules]` escape hatch for any raw Illuminate rule
string or `ValidationRule` object. `#[Valid]` on a nested DTO property cascades one object level deep
(dot-prefixed nested keys), with a cycle guard against self-referential DTOs. See
[Validation](validation.md) for the full constraint catalogue and the underlying `Rule` objects.

```php
final class CreateAccountRequest
{
    public function __construct(
        #[NotBlank]
        #[Iban]
        public readonly string $iban,
        #[NotBlank]
        public readonly string $owner,
    ) {}
}
```

## Content negotiation

Content negotiation is JSON-native: the only shipped `MessageConverter` is `JsonMessageConverter`
(`application/json` and any `+json` suffix type). A controller return value that is not already a
`Response`/`Responsable` is written by the converter chosen from the request's `Accept` header — parsed
for q-values with a header-order tiebreak — falling back to the first (JSON) converter when nothing
matches or `Accept` is absent. Request bodies are read the same way, keyed off `Content-Type`.

`MessageConverterRegistry` is an ordinary container binding (guarded `#[ConditionalOnMissingBean]`-style
via `if (! $app->bound(...))`), so an application can register additional `MessageConverter`s — XML
support is deferred to this seam rather than shipped in M6 (tracked for SP-6).

## The compiled `RouteManifest`

`RouteScanner` — the one reflection site in `packages/web/src` — walks `#[RestController]` classes and
compiles each verb-mapped method into a `RouteDescriptor` (HTTP method, joined path, controller/method,
default status, optional route name, and a pure-array parameter-binding plan). `RouteManifestCompiler`
`var_export`s the descriptors into a require-able PHP file; `RouteManifest::load()` reads it back
reflection-free. At boot, `RouteWiringPass` (phase `WiringPasses`) registers one native Laravel route per
descriptor and hands each one a dispatch closure (`ControllerDispatcher`) — so route listing, URL
generation, and Laravel's own route-caching machinery all apply to LaraFly routes unmodified.

!!! note "Known-latent: manifest compilation, config ordering, and negotiation scope"
    - **App manifests compile inline today.** `RouteManifest`/`ConstraintManifest` are meant to be
      produced ahead of time by `firefly/cli`'s `firefly:cache` command (M14/M15). Until that command
      ships, an application must compile its own `RouteScanner`/`ConstraintManifestCompiler` output and
      bind the resulting `RouteManifest`/`ConstraintManifest` instances itself (exactly what the
      package's own capstone test does); `WebServiceProvider` only binds empty defaults so the package
      boots standalone.
    - **`route:cache` interplay.** Because dispatch runs through ordinary native Laravel routes, Laravel's
      own `route:cache` works unmodified once those routes are registered — there is no separate LaraFly
      route cache to keep in sync with it.
    - **Component-scan path ordering.** Auto-configuration discovers `#[Component]` beans — including
      `WebFilter`s — from a component scan whose paths are read at **provider-register time**. Any
      application config that sets `firefly.scan.paths` must therefore already be loaded *before*
      providers register. This is exactly the ordering Laravel itself guarantees (`LoadConfiguration`
      runs before `RegisterProviders`), so a config file is the correct place for this key — but a
      programmatic override made after providers have registered is too late to affect the scan.
    - **Deep/array `#[Valid]` cascade is bounded.** Nested-DTO validation cascades exactly one object
      level per `#[Valid]` property; it does not recurse into arrays/collections of DTOs, and a
      self-referential `#[Valid]` chain expands only that one level (guarded against infinite descent).
    - **XML content negotiation is deferred** to the `MessageConverter` seam described above (SP-6);
      only JSON ships in M6.

---

Apache-2.0 © Firefly Software Solutions Inc.
