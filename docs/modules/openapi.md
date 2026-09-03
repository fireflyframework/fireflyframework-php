# OpenAPI

`firefly/openapi` generates a valid **OpenAPI 3.1** document from the manifests the framework already holds in
memory. There is no annotation dialect to learn and nothing to keep in sync by hand: `RouteManifest` supplies the
paths, verbs, statuses, route names and the per-parameter binding plan; `ConstraintManifest` supplies the
request-body schemas and their `required` lists; `firefly/kernel`'s `ErrorResponse` supplies the RFC 9457 error
component. Install the package and a LaraFly app has a spec — and therefore typed clients — for free.

Because every fact in the document is read from the same compiled artifacts the dispatcher dispatches from and the
validator validates with, **the spec cannot drift from the server**.

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
self-referential DTO terminates as a `$ref` cycle instead of recursing forever.

**Property schemas** merge the DTO's declared constructor types with its compiled constraints, because neither
alone is enough: types-only documents `#[NotBlank] string $name` as an unbounded string, constraints-only documents
`int $quantity` as a string. Backed enums, `DateTimeInterface` and nullability come from the type; the keywords come
from the manifest.

| Constraint | JSON Schema |
|---|---|
| `#[NotNull]`, `#[NotBlank]`, `#[NotEmpty]` | member added to the parent's `required` |
| `#[NotBlank]` | `type: string` + `pattern: \S` |
| `#[Size(min, max)]` | `minLength`/`maxLength`, or `minItems`/`maxItems` on an array |
| `#[Min]` / `#[Max]` | `minimum` / `maximum` |
| `#[Positive]`, `#[Negative]`, `…OrZero` | `exclusiveMinimum` / `minimum` / … |
| `#[Email]` | `format: email` |
| `#[Pattern]` | `pattern` (PCRE delimiters and no-op flags stripped) |
| `#[UuidValue]`, `#[Phone]`, `#[Iban]`, `#[Bic]`, `#[Isin]`, … | `format` + a `pattern` where the rule matches the raw value |
| `#[Percentage]` | `type: number`, `minimum: 0`, `maximum: 100` |
| `#[DecimalScale(n)]`, `#[Money]` | `multipleOf` |
| `#[AssertTrue]` / `#[AssertFalse]` | `type: boolean` + `const` |

A nullable member is spelled the 3.1 way — a `type` union including `"null"`, not 3.0's `nullable` keyword.

**Nothing is dropped silently.** Constraints JSON Schema cannot express (`#[Future]`'s "after now", a Luhn
checksum, a third-party `ValidationRule`) and ones it can only approximate (a PCRE pattern carrying flags ECMA-262
has no syntax for) are recorded under the `x-firefly-constraints` specification extension. Conforming tools ignore
an `x-` member; a human or a custom generator can read it.

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

## Determinism, and the `{}`-vs-`[]` trap

Paths are sorted, verbs within a Path Item are sorted into the canonical OpenAPI order, and `SchemaRegistry` sorts
components by name. Route discovery order depends on filesystem iteration, so an unsorted document would reshuffle
itself between machines and turn every regeneration into an unreviewable diff — which is what makes teams stop
committing the generated file, which is what makes it go stale.

`generate()` returns plain PHP arrays (pleasant to assert against); `toJson()` is the canonical serialisation and
the one that must produce any file or HTTP body. PHP cannot tell an empty map from an empty list, so
`json_encode([])` is `[]` — and `"paths": []` or an unconstrained property serialised as `[]` are both type errors
against the 3.1 meta-schema that make a strict validator reject an otherwise perfect document. `toJson()`
re-encodes every empty array as `{}`, which is unconditionally safe here because nothing in this document ever
emits an empty *list*: `required`, `tags`, `parameters`, `servers`, `allOf` and the constraint extension are each
omitted entirely rather than emitted empty.

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
| `firefly.openapi.servers` | `[]` | Bare URL strings and/or OpenAPI Server Objects. An entry that is neither — or an object with no `url` — is **dropped**, because it would be invalid under the 3.1 schema and would poison an otherwise-good document. Omitted from the document when empty. |
| `firefly.openapi.exclude` | `''` | CSV of path **prefixes** left out of the document. Removes them from the spec only; it does not unroute them. |
| `firefly.openapi.include-html` | `false` | Document `#[Controller]` HTML routes as `text/html` operations. |

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
