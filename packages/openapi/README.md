# firefly/openapi

OpenAPI 3.1 generation for LaraFly, from the manifests the framework already holds in memory. There is no
annotation dialect to learn and nothing to keep in sync by hand: `RouteManifest` supplies the paths, verbs,
statuses, route names and parameter bindings, `ConstraintManifest` supplies the request-body schemas and their
`required` lists, and `packages/kernel`'s `ErrorResponse` supplies the RFC 9457 error component. Install the
package and a LaraFly app has a spec — and therefore typed clients — for free.

Because every fact in the document is read from the same compiled artifacts the dispatcher reads, the spec
cannot drift from the server.

## What you get

| Surface | Default | Purpose |
| --- | --- | --- |
| `GET /openapi.json` | on | The generated OpenAPI 3.1 document. |
| `GET /openapi` | on | A dependency-free API reference console. |
| `php artisan firefly:openapi` | — | Writes the document to a file (`--output=`) or to stdout. |

Both routes are mounted natively on the Illuminate router from a `BootPass`, at a **configurable** path. That
is deliberate: an attribute route (`#[GetMapping('/openapi.json')]`) bakes its literal into a compiled
`RouteDescriptor`, so it could never be moved or taken off a public surface by configuration. It also means
this package's own two routes never enter the `RouteManifest`, so the generator never documents itself.

## What the generator maps

**Operations** come from each `RouteDescriptor`: the verb and path (Laravel's optional `{id?}` is normalised
to `{id}`, since a path parameter is required in OpenAPI), the `#[Mapping]`'s declared status, and the route
name as the `operationId` when one is set. Operations are tagged by controller short name, and paths,
verbs and components are all sorted so a regenerated document diffs cleanly.

**Parameters** come from the binding plan — the same `kind` discriminator `ArgumentResolver` dispatches on at
request time. `#[PathVariable]`, `#[QueryParam]` and `#[RequestHeader]` become Parameter Objects;
`#[UploadedFile]` becomes a `multipart/form-data` part; a container-injected service is not part of the HTTP
contract and never appears.

**Request bodies** come from the `#[RequestBody]` DTO, as a `$ref` into `components/schemas` — one component
per DTO, reused everywhere, with nested `#[Valid]` DTOs given their own component rather than being inlined
(a self-referential DTO therefore terminates, as a `$ref` cycle).

**Property schemas** merge the DTO's declared types with its compiled constraints, because neither alone is
enough: types-only documents `#[NotBlank] string $name` as an unbounded string, constraints-only documents
`int $quantity` as a string. Backed enums, `DateTimeInterface` and nullability come from the type; everything
else comes from the manifest:

| Constraint | JSON Schema |
| --- | --- |
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

A nullable member is spelled the 3.1 way — a `type` union with `"null"`, not 3.0's `nullable` keyword —
following the Jakarta null contract `ConstraintScanner` already applies.

**Nothing is dropped silently.** Constraints JSON Schema cannot express (`#[Future]`'s "after now", a Luhn
checksum, a third-party `ValidationRule`) and ones it can only approximate (a PCRE pattern carrying flags
ECMA-262 has no syntax for) are recorded under the `x-firefly-constraints` specification extension.
Conforming tools ignore it; a human or a custom generator can read it.

**Responses.** Every operation carries the shared `#/components/responses/Problem` as its `default`, plus a
`400` when `ArgumentResolver` has something it can reject before the controller runs, and a `422` when a
binding carries `#[Valid]`. The problem schema describes what LaraFly actually returns — RFC 9457's members
*plus* Firefly's `code`, `category`, `severity` and `errors`, with the category and severity enumerations read
straight off the kernel enums.

## The viewer

`/openapi` serves the **official Swagger UI** — the real distribution, not a lookalike — from your own
application's origin. **No npm build at install time and no third-party request at page view.**

That combination used to look impossible. Shipping an off-the-shelf viewer seemed to leave only two options:
vendor a multi-megabyte bundle into a PHP package's git history, or fetch it from a CDN on every page view.
The second is a supply-chain dependency and a data-protection question, and it does not render at all in the
air-gapped and strict-CSP environments where an internal API console is most wanted.

The way out is that Swagger already publishes its `dist` on Packagist, under Apache-2.0. `firefly/openapi`
requires `swagger-api/swagger-ui`, so composer fetches and pins it like any other dependency, and this
package serves the files from a route of its own. Only the seven basenames the page references are servable,
each `realpath()`-checked inside the dist directory, and they are sent immutable with a long max-age —
composer only changes them when the pinned version changes.

Three styles, chosen with `firefly.openapi.viewer.style`:

| Style | What you get |
| --- | --- |
| `swagger` *(default)* | The official Swagger UI, served locally. Deep linking, try-it-out, OAuth2, the lot. |
| `builtin` | A hand-written reference: one `<script>`, a few hundred bytes of CSS, zero third-party code. Operations by tag, resolved `$ref` schemas, constraint keywords, and a request console. |
| `cdn` | Swagger UI from `cdn.jsdelivr.net`. The only style that makes a third-party request at page view; the version is pinned exactly. |

`swagger` falls back to `builtin` when `swagger-api/swagger-ui` is not installed — a default that cannot
render is worse than a different default.

The older `'viewer' => ['cdn' => true]` spelling still forces the CDN page, so an application that set it
before `style` existed keeps the behaviour it configured.

## Configuration

```php
// config/firefly.php
'openapi' => [
    'enabled' => true,              // master gate: off means both routes are genuinely unrouted
    'path' => '/openapi.json',      // spec route
    'viewer' => [
        'enabled' => true,
        'path' => '/openapi',
        'style' => 'swagger',       // swagger (official UI, served locally) | builtin | cdn
        'cdn' => false,             // legacy spelling; true still forces the cdn style
    ],
    'title' => 'API',
    'version' => '0.0.0',
    'description' => '',
    'servers' => ['https://api.example.test'],  // bare URLs or OpenAPI Server Objects
    'exclude' => '/internal,/admin',            // CSV of path prefixes to leave out
],
```

Secure a public deployment the way you secure any other route — `firefly/security`'s `HttpSecurity` config
covers `/openapi*` with no code edge — or set `enabled` to `false` and generate the document in CI with
`firefly:openapi` instead.

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
`ViewerPage` are all overridable this way.

## A note on reflection

LaraFly's rule is that nothing on the cached **request** path reflects. This package honours it: the DTO
constructor reflection that supplies property types runs when `firefly:openapi` generates a file, or on a hit
to the spec route — whose result the generator memoises for the life of the process — and never while
dispatching an application request. It is the same category of work as `RouteScanner` and `ConstraintScanner`,
both of which reflect at compile time only.

Apache-2.0 © Firefly Software Solutions Inc.
