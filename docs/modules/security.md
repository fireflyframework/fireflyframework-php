# Security

`firefly/security` is LaraFly's first-party security core — a Spring-Security-6-shaped principal model persisted in
the session, authentication (form login with a shipped sign-in page, HTTP Basic, remember-me, logout, JWT and OAuth2
bearer, a negotiating entry point, memory or Eloquent users), and deny-by-default authorization (URL rules, and
method rules at the CQRS bus, the controller dispatcher and on any stereotyped bean through the proxy chain shared
with `#[Transactional]`) that **lights up** the seams shipped in earlier milestones (the CQRS
`Command`/`QueryAuthorizer`, the M8 `AuditorAware`, the M6 web filter chain, the M8 transaction proxy). Every
mechanism publishes Spring's event family and every key defaults to off. Secure-by-default, fail-closed, opt-in,
zero boot reflection.

## Principal model

- `Authentication` — an immutable token (`authenticated()` / `unauthenticated()` factories); credentials are erased
  on success. `GrantedAuthority` / `SimpleGrantedAuthority` wrap authority strings.
- `CredentialsContainer` — a principal that hands back a copy of itself without its credential (Spring's, derived
  rather than mutated). The shipped `User` implements it; `Authentication::eraseCredentials()` applies it to the
  principal, and the session repository stores that copy, so an encoded password never reaches the session store.
- `SecurityContext` — an immutable snapshot; `anonymous()` is the zero-value.
- `SecurityContextHolder` — a request-scoped static holder backed by Laravel's `Context` (per-request, Octane-safe);
  the auth filters clear it on exit.

## Authentication

- Ports: `UserDetails` / `UserDetailsService`, `PasswordEncoder` (`BcryptPasswordEncoder`, `Argon2idPasswordEncoder`,
  `DelegatingPasswordEncoder` with `{id}` prefixes — constant-time).
- User stores (`firefly.security.users.driver`): **`memory`** (the `InMemoryUserDetailsService` map, unchanged) and
  **`eloquent`** (`EloquentUserDetailsService` over any Eloquent model — `email`/`password`/`enabled`/`locked`/
  `authorities`, every column configurable, `authorities` a JSON list or `relation.attribute`; a model class that
  does not exist or is not an Eloquent model refuses to boot, and a configured column the loaded row does not carry
  is refused on the lookup rather than read as `NULL`). Both map a row to the immutable `User` value object, never
  to the model.
- `AuthenticationProvider` / `ProviderManager` (first-supports-wins) / `DaoAuthenticationProvider` (maps bad-credentials/
  disabled/locked to 401 subtypes; timing- and content-safe against username enumeration). **Every interactive
  mechanism below authenticates through it**, so the equalisation is inherited whole.
- `JwtService` — HMAC encode/decode with a **mandatory `exp`** claim and a **weak-secret boot refusal**; the same rule
  (`JwtService::assertStrongSecret()`) guards the remember-me key.
- Bearer: `JwtAuthenticationFilter` (`-90`, local JWT) and `OAuth2ResourceServerFilter` (`-85`, JWKS via the
  `JwksProvider` port, scopes → `SCOPE_*`; validates `iss`/`aud` when configured — protection against a
  confused-deputy/token-redirection attack, RFC 9700, where a JWKS-signed token minted by the same issuer for a
  *different* audience would otherwise be accepted). Both opt-in, both establish and clear the `SecurityContext`
  per request. Neither replaces a principal an outer filter — the session persistence filter, a test's acting
  principal — already established.

**JWT/OAuth2 are mutually exclusive.** Enabling both `firefly.security.jwt.enabled` and
`firefly.security.oauth2.resource_server.enabled` is refused at **boot** (`ConfigurationException`, fail-closed): the
local-JWT filter runs first (`#[Order(-90)]`) and unconditionally rejects any Bearer token it cannot decode with the
local HMAC secret, before the resource-server filter (`#[Order(-85)]`) — which validates against the issuer's
JWKS — ever gets a chance to run. Pick exactly one bearer mechanism, matching Spring's one-resource-server-mechanism
model.

### Sessions

Firefly filters are **global** kernel middleware; Laravel starts the session in the `web` route group, later. When
session security is on (`firefly.security.session.enabled`, or implied by form login, remember-me or
`http_basic.session`), `SessionSecurityBootstrap` — a boot pass at `WiringPasses` order 90, after every route
registrar and before the filter chain — pushes `EncryptCookies`, `AddQueuedCookiesToResponse` and `StartSession`
onto the **global** stack ahead of the filter chain, removes them from the `web` group and excludes them
(`Route::withoutMiddleware`) on the matched route from a `RouteMatched` listener, at dispatch time: a second
`EncryptCookies` pass decrypts already-decrypted cookies, nulls them, and `StartSession` then mints a fresh
session — the login would be lost on any route that carried the group. Excluding on the matched instance rather
than on the boot-time collection is what makes it hold under `route:cache` (a compiled collection builds a fresh
`Route` on every match) and bakes nothing into the cache. A session driver is required; boot refuses without one.

`SecurityContextPersistenceFilter` (`-94`) loads the `SecurityContext` from the `SecurityContextRepository` (the
session, under one key) into `SecurityContextHolder` at request start — unless something outside already
established one — and on exit saves a context that changed during the request and **always clears the holder**.
The mechanisms save themselves at the moment of success, because the inner filters clear the holder in their own
`finally` before the persistence filter's exit runs. What is stored never carries a credential: a
`CredentialsContainer` principal (the shipped `User`) is written without its encoded password. Every interactive
sign-in regenerates the session id (`session.fixation_protection`).

### Form login

`firefly.security.form_login.enabled` mounts `GET /login` (the framework's own server-rendered page, in the error
page's design — or your Blade `view`, which receives `$login`, a `LoginPageModel`; a `GET` route of your own at that
path is left in place) and `FormLoginFilter` (`-92`) handles `POST /login` before routing, as Spring's
`UsernamePasswordAuthenticationFilter` does: the session CSRF token first (`SessionCsrf`, independent of
`CsrfFilter` — a login CSRF is an attack), then `AuthenticationManager`, then — on success — a regenerated session
id, the context stored, `AuthenticationSuccessEvent` + `InteractiveAuthenticationSuccessEvent(form)`, the
remember-me cookie when asked, and a redirect to the request the entry point saved (`SavedRequest`) or
`default_success_url`. A failure publishes `AuthenticationFailure*Event` (username and source ip, never the
password) and redirects to `failure_url` (`/login?error`, the page's error state). The login page is always
permitted by `HttpSecurityFilter`, or the redirect would loop.

### HTTP Basic

`firefly.security.http_basic.enabled`: `HttpBasicFilter` (`-91`) authenticates a present `Authorization: Basic`
header on **every** request — stateless — unless `http_basic.session` stores a success in the session like a form
login. A wrong password, an unknown user and a header that does not parse all get the
`BasicAuthenticationEntryPoint`'s 401 with `WWW-Authenticate: Basic realm="…", charset="UTF-8"` (the Basic entry
point, whatever `http.entry_point` says — a request that presented Basic credentials has chosen its mechanism); no
header is the anonymous path.

### Remember-me

`firefly.security.remember_me.enabled`: `TokenBasedRememberMeServices` (Spring's) signs a cookie
`username:expiry:HMAC-SHA256(username:expiry:password-hash, key)` when the form's `parameter` is ticked (or
`always_remember`), and `RememberMeAuthenticationFilter` (`-83`) re-authenticates a request whose session holds no
principal — as an interactive sign-in (new session id, context stored, `InteractiveAuthenticationSuccessEvent(remember-me)`),
so the cookie is consulted once per session, not once per request. The password hash in the signature means a
password change invalidates every cookie; a disabled or locked account is refused even on a genuine cookie; the key
is held to the JWT secret rule and refused at boot when weak. Logout expires the cookie.

### Logout

`firefly.security.logout.*` (on with form login): `LogoutFilter` (`-93`) handles `POST /logout` — POST only,
CSRF-checked; a `GET` falls through to whatever route is there — expiring the remember-me cookie and every
`delete_cookies` name, invalidating the session (or only removing the context), publishing `LogoutSuccessEvent`,
and redirecting to `logout_success_url` (`/login?logout`).

### Events

Every mechanism reports through `AuthenticationEventPublisher` over the context `ApplicationEventPublisher`, so an
`#[AsEventListener]` method receives them and `Event::fake()` sees them: `AuthenticationSuccessEvent`,
`InteractiveAuthenticationSuccessEvent` (`form`|`basic`|`remember-me`), `AuthenticationFailureBadCredentialsEvent`
(an unknown user is reported as this on purpose), `AuthenticationFailureLockedEvent`,
`AuthenticationFailureDisabledEvent`, `LogoutSuccessEvent`, `AuthorizationDeniedEvent` (a URL rule or a method rule
refusing an **authenticated** principal — `subject` is `GET /admin/users` or `App\Reports::totals`).

## Authorization

- **URL (deny-by-default):** the `HttpSecurity` DSL builds an ordered rule list; `HttpSecurityFilter` evaluates
  first-match-wins and denies anything unmatched — 401 when anonymous, 403 when authenticated.
- **Method — everywhere:** `#[PreAuthorize('…')]`, `#[PostAuthorize('…')]` (evaluated after the call with
  `#returnObject` bound — `hasPermission(#returnObject, 'READ')`), `#[Secured('…')]`, `#[RolesAllowed('…')]`,
  `#[PreFilter('…', filterTarget: 'ids')]` and `#[PostFilter('…')]` (over iterables, `#filterObject` bound to each
  element; arrays keep keys, Enumerables are filtered in kind, a non-iterable is refused). A single
  `MethodSecurityScanner` compiles them into a `var_export` manifest (the sole reflection site). Enforced at the CQRS
  bus (pre rules), the controller dispatcher (`ControllerSecurityGuard::check()` before, `afterInvocation()` after)
  and — with `firefly.security.method.enabled`, default on — on **any stereotyped bean** (`#[Service]`,
  `#[Component]`, `#[Repository]`) through the proxy chain shared with `#[Transactional]`:
  `MethodSecurityAdviceSource` contributes the rules to the proxy plan, `MethodSecurityInterceptor` runs at advice
  order 100, ahead of the transactional link at 1000, so a refusal never opens a transaction (see
  [transactional.md](transactional.md#the-proxy-model)). Controllers and pre-only CQRS handlers are left to their
  seams; a `final` bean that needs a proxy is refused at scan time. All three sites share `MethodSecurityEvaluator`:
  a refusal is a 401 when anonymous and a 403 worded by `MethodSecurityRefusal` when authenticated. Imperatively,
  `AuthorizationChecker`. Method security is **additive, not a second deny-by-default gate** — see
  [Method security fails open on an empty manifest](#method-security-fails-open-on-an-empty-manifest). The
  scanner rejects, at compile (cache) time, any `#[Secured]`/`#[RolesAllowed]`
  role/authority value containing a single quote — even one that would otherwise compile into *grammar-valid*
  expression text (e.g. a value that splices in `or permitAll()`) — so a malicious or malformed attribute value can
  never widen access silently; it fails the `firefly:cache`-equivalent scan step loudly instead.
- **Expressions** run through a hand-rolled **whitelist tokenizer + recursive-descent parser — never `eval`**:
  `hasRole`, `hasAnyRole`, `hasAuthority`, `hasAnyAuthority`, `hasPermission`, `isAuthenticated`, `permitAll`,
  `denyAll`, plus `#param` references. `RoleHierarchy` expands implied roles; `PermissionEvaluator` (deny-all default)
  backs `hasPermission`.

  `RoleHierarchy` rules are **single-arrow only** — one implication per entry, e.g. `"ROLE_ADMIN > ROLE_USER"`. A
  chained `"ROLE_ADMIN > ROLE_STAFF > ROLE_USER"` in a single entry is **not** supported; express a multi-level
  hierarchy as separate single-arrow entries (`["ROLE_ADMIN > ROLE_STAFF", "ROLE_STAFF > ROLE_USER"]`).

  `HttpSecurity::hasRole()`/`hasAuthority()` (and the equivalent `firefly.security.http.rules` config access specs)
  likewise reject a role/authority value containing a single quote, for the same expression-injection reason as
  method security above — a legitimate role/authority string never needs one.

### What a refused principal reads

A method-security denial is a `403 ACCESS_DENIED` whose `detail` is `You do not have permission to do this.`
and whose problem document carries the authorities the rule asked for as an RFC 9457 extension member —
`"requiredAuthorities": ["ROLE_ADMIN", "orders:write"]`, read off the expression's `hasRole`/`hasAnyRole`/
`hasAuthority`/`hasAnyAuthority` literals with roles normalised to `ROLE_`. The guarded **class and method go
to the log**, at warning, with the principal and its authorities — they used to be the wire sentence
("Access is denied for [App\Ctrl::admin].") and a PHP class name is not something a person should read on a
panel. A rule can carry its own product code and sentence:

```php
#[PreAuthorize("hasAnyRole('MANAGER', 'TENANT_ADMIN')", code: 'RUN_ROLE_REQUIRED', message: 'Only a manager may start a run.')]
public function start(): void {}
```

so the role rule and the words for breaking it live on the same line, beside the method they guard, instead
of in a service the controller has to call first. An anonymous caller still gets `401` `Authentication is
required.`; `#[Secured]` and `#[RolesAllowed]` have no slot for words and use the framework's sentence.

### Method security fails open on an empty manifest

Both enforcement sites (`MethodSecurityMessageEnforcer::enforce()` and the controller guard) read "no rule
recorded for this method" as **ALLOW**. That is correct — method security adds rules on top of URL security,
it is not a second gate — but it makes an *empty* `SecurityMethodManifest` indistinguishable from an
application that declares no rules at all. An empty manifest silently disables every `#[PreAuthorize]`,
`#[Secured]` and `#[RolesAllowed]` in the app, with nothing logged.

The manifest is therefore resolved like every other compiled manifest: **the artifact `firefly:cache` wrote if
it exists, otherwise an in-process scan of `firefly.scan.paths`, otherwise empty**. An uncached app enforces
the same rules a cached one does. (Before that fallback existed, only `firefly/cli` — a `require-dev` package
that was not in the `firefly/firefly` metapackage — ever bound the compiled rules, so a production install
could run entirely unguarded.)

`firefly.security.method.strict` (default `false`) closes the remaining hole: with it on, a boot that finds no
compiled artifact **refuses to start** rather than falling back to the scan. Set it in any image that runs
`firefly:cache` — it converts "someone forgot the compile step" from silently unguarded handlers into a
startup failure, and it is the only defence against a build that ships without the manifest.

Rules on a `#[Service]` or any other stereotyped bean have a second artifact: the proxy plan. `firefly:cache`
plans every class through Data's transactional advice source *and* Security's `MethodSecurityAdviceSource`,
writes `proxy-plan.php`, and generates one `{Target}__FireflyTransactionalProxy` per planned class with the
security link baked in — so a bean whose only rule is `#[PreAuthorize]` is proxied exactly like a
`#[Transactional]` one. A cache written before that file existed holds `security-methods.php` listing every
rule while the proxy plan bridged from `transactional.php` knows nothing about method security: the rows
compile, nothing enforces the bean-level ones, and `strict` cannot see it because the manifest it checks for
*is* present. `SecurityWiringPass` therefore **refuses to boot** over `security-methods.php` with no
`proxy-plan.php` beside it (under the master flag and `method.enabled`), naming the remedy: run
`php artisan firefly:cache` again. The scan itself refuses, at compile time and on every entry point, a rule
no seam could apply — a `#[PreFilter]` on a controller action, a `#[PostAuthorize]`/`#[PreFilter]`/
`#[PostFilter]` on a class with no stereotype, and a `final` bean whose rules only a proxy could enforce —
rather than compile a row that would be evaluated and discarded.

### Expression evaluation is re-entrant

`SecurityExpressionEvaluator` is a singleton whose recursive-descent parse state lives on the instance, and
`hasPermission()` is the one dispatch path that calls **application** code — a user-supplied
`PermissionEvaluator`, which is a documented extension point and may evaluate an expression of its own on the
same singleton. The inner call used to overwrite the outer parse state; on return the outer parse resumed
against the inner token stream, saw EOF, and returned the inner result — silently discarding every term after
`hasPermission(...)`. `hasPermission(#id, 'read') and hasRole('ADMIN')` returned **true** for a principal
holding no authorities at all: a fail-open, not a fail-closed. The parse state is now saved and restored
around `evaluate()`/`parse()` in a `finally`, so a throwing inner evaluator cannot strand torn state either.

### The config access vocabulary is fixed, and fail-closed

The fluent `HttpSecurity` DSL (`permitAll()`, `denyAll()`, `authenticated()`, `hasRole()`, `hasAuthority()`)
compiles to the same expression grammar method security uses. The **config** spelling in
`firefly.security.http.rules` does not accept that grammar — `HttpSecurity::fromConfig()` maps a fixed set of
tokens:

| `access` value | Compiled expression |
|---|---|
| `permitAll` | `permitAll()` |
| `denyAll` | `denyAll()` |
| `authenticated` | `isAuthenticated()` |
| `hasRole:<ROLE>` | `hasRole('<ROLE>')` |
| `hasAuthority:<AUTHORITY>` | `hasAuthority('<AUTHORITY>')` |

**Anything else compiles to `denyAll()`.** So `hasRole('ADMIN')` — the expression spelling — is not a valid
config access spec, and a rule written that way locks the path down instead of opening it. That direction is
deliberate: an unrecognised spec must fail closed. Interpolated role/authority values containing a single
quote are rejected outright (expression injection).

## Web

### The entry point

`HttpSecurityFilter` (`-70`) keeps deny-by-default. An **unauthenticated** denial is handed to the
`AuthenticationEntryPoint` chosen by `firefly.security.http.entry_point`: `auto` sends a browser (the request names
`text/html`, is not an XMLHttpRequest and is not under `firefly.web.error-page.json-paths` — the error page's own
negotiation, `ErrorPageRenderer::prefersHtml()`, independent of `firefly.web.error-page.enabled`) to the login page
with the request saved when form login is on, answers `401` + `WWW-Authenticate: Basic` when HTTP Basic is on, and
otherwise throws the 401 for firefly/web to render as before (problem+json, or the HTML 401 page); `login`,
`challenge` and `problem` force one. An **authenticated** denial stays the 403 page/problem and publishes
`AuthorizationDeniedEvent`.

### Principal injection

A controller action can take `Authentication $auth` (401 when anonymous) or `?Authentication $auth` (null),
`?UserDetails $user` (the principal when it is one, else null — a JWT's principal is its `sub` string),
`#[AuthenticationPrincipal] mixed $principal` (`?string $sub` for that JWT case) and
`#[CurrentSecurityContext] SecurityContext $context` (the anonymous context when nobody is signed in). An
attributed principal is handed over only when it *is* what the parameter declares and is null otherwise, so the
same action can serve a form login's `User` and a bearer's `sub` without a `TypeError`; a null for a non-nullable
parameter is a 401. `SecurityArgumentResolver` is registered into firefly/web's `HandlerMethodArgumentResolvers` —
the port any package can add a resolver to — whether or not the master flag is on, so the annotations are inert
(null, anonymous, an honest 401) rather than misread as query parameters while security is off.

### Hardening

- `CsrfFilter` (`-80`) — Laravel's session token whenever the request has a started session (session security
  on), read through `SessionCsrf` from the same three sources Laravel's own `PreventRequestForgery` reads:
  `_token`, `X-CSRF-TOKEN`, or `X-XSRF-TOKEN` carrying the encrypted `XSRF-TOKEN` cookie a Laravel SPA client
  echoes back; the stateless double-submit cookie otherwise (safe-method + path exemptions, constant-time compare).
- `SecurityHeadersFilter` (`-95`) — HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, and a conservative CSP.

### Filter order

| Order | Filter |
|---|---|
| -95 | `SecurityHeadersFilter` |
| -94 | `SecurityContextPersistenceFilter` |
| -93 | `LogoutFilter` |
| -92 | `FormLoginFilter` |
| -91 | `HttpBasicFilter` |
| -90 | `JwtAuthenticationFilter` |
| -85 | `OAuth2ResourceServerFilter` |
| -83 | `RememberMeAuthenticationFilter` |
| -80 | `CsrfFilter` |
| -70 | `HttpSecurityFilter` |

Every filter clears `SecurityContextHolder` on exit in a `finally`, so nothing bleeds into the next request under
Octane; the persistence filter is the outermost and the last to clear.

## Configuration (`firefly.security.*`, snake_case)

`firefly.security.enabled` is the **master flag**. It gates the core security stack: the password encoder, user
store, role hierarchy, permission evaluator, expression evaluator, authentication manager, `AuthorizationChecker`,
the real CQRS `Command`/`QueryAuthorizer`, the event publisher, and the real `AuditorAware`. Among the per-surface
flags below, **`firefly.security.http.enabled` additionally requires the master flag** — `HttpSecurityFilter`'s
constructor consumes three master-gated beans (`SecurityExpressionEvaluator`, `RoleHierarchy`, `PermissionEvaluator`),
so the URL-rule filter cannot construct at all unless both flags are on. `jwt`, `oauth2.resource_server`, `csrf`, and
`headers` are **independent** of the master flag — each is a `Config`-only (or single-extra-bean) filter gated solely
by its own flag, so it can be turned on without enabling the broader security stack. (Turning on `jwt`/`oauth2` alone
authenticates a principal but enforces no authorization unless `http`/master or the CQRS/method-security seams are
also enabled — pair it with `http.enabled` + master, or with method security, to actually gate anything.) Every
mechanism added by the Spring-parity wave — `session`, `form_login`, `http_basic`, `logout`, `remember_me`, the
entry point, `method.enabled` and the `users` driver — is gated by the master flag as well as its own key, because
each depends on master-gated beans.

| Key | Default | Meaning |
|---|---|---|
| `firefly.security.enabled` | `false` | Master flag — enables the core stack (password encoder, user store, role hierarchy, permission evaluator, expression evaluator, authentication manager, `AuthorizationChecker`, CQRS authorizers, the event publisher, `AuditorAware`). Required by `http`, `method.enabled`, `session`, `form_login`, `http_basic`, `logout`, `remember_me`, the entry point and the `users` driver (each consumes master-gated beans); `jwt`/`oauth2.resource_server`/`csrf`/`headers` do not require it. |
| `firefly.security.method.strict` | `false` | Refuse to boot when no compiled method-security manifest exists, instead of falling back to the in-process scan. See [above](#method-security-fails-open-on-an-empty-manifest). Independent of the master flag — the manifest binding is registered whether or not security is enabled. |
| `firefly.security.method.enabled` | `true` | With the master flag on, enforces method-security attributes on **any** stereotyped bean through the proxy chain (security advice before the transactional one), on both boot paths: `firefly:cache` compiles the plan into `proxy-plan.php` and a proxy per planned class, and the uncached boot scans the same advice sources. A cache written before `proxy-plan.php` existed (`security-methods.php` with no plan beside it) is **refused at boot** — recompile — because such a cache lists every rule and enforces none of the bean-level ones. Off keeps the dispatcher and bus enforcing theirs; the proxy link becomes a pass-through, and the stale-cache refusal stands down with it. Read live. |
| `firefly.security.users` | _(unset)_ | The user store. With no `driver` (or `driver: memory`) the block is the `InMemoryUserDetailsService` map (`{username: {password, authorities, enabled, locked}}`; `password` is the **encoded** string, typically `{id}`-prefixed; `authorities` defaults to `[]`, `enabled` to `true`, `locked` to `false`). The reserved keys below are never read as usernames. |
| `firefly.security.users.driver` | `memory` | `memory` or `eloquent`. |
| `firefly.security.users.model` | `''` | The Eloquent model (`eloquent` only); a class that does not exist or is not an Eloquent model refuses to boot. |
| `firefly.security.users.username_column` / `password_column` | `email` / `password` | The columns the lookup and the encoded password come from. A configured column (`password_column`, `enabled_column`, `locked_column`, `authorities`) that the loaded row does not carry is a `ConfigurationException` on the lookup naming the setting, never read as `NULL` — a `locked_column` typo would otherwise unlock every account silently. The three optional columns opt out with `''`, never by being absent. An absent `username_column` is the lookup's `WHERE`: a query error, or no row, never a user. |
| `firefly.security.users.enabled_column` / `locked_column` | `''` / `''` | Boolean columns; empty means every account is enabled / none is locked. |
| `firefly.security.users.authorities` | `authorities` | A column holding a JSON list (or an `array` cast), or `relation.attribute` (`roles.name`: the attribute plucked from every related model, or from the one model of a `belongsTo`). Entries that are not non-empty strings are dropped; a relation the model does not define is refused like an absent column. `''` means the model carries no authorities: every account authenticates with none (`[]`, as the memory driver's optional `authorities` does). Laravel's stock `users` table has no such column, so `App\Models\User` needs `authorities: ''` — or a column/relation added — because the default names a column the driver refuses on the lookup. |
| `firefly.security.role_hierarchy` | `[]` | Single-arrow implication rules, e.g. `["ROLE_ADMIN > ROLE_USER"]` (one implication per entry — not chainable in one string). |
| `firefly.security.session.enabled` | `false` | Carry the `SecurityContext` in the Laravel session (`SecurityContextPersistenceFilter`, `-94`; `SessionSecurityBootstrap` runs Laravel's cookie/session middleware globally ahead of the filters and excludes it on each route as it is matched, so `route:cache` needs nothing). Implied by `form_login`, `remember_me` and `http_basic.session`. What is carried is a context saved through `SecurityContextRepository` — by the interactive mechanisms at the moment of success, or by application code — and never a credential: a principal that implements `CredentialsContainer` (the shipped `User`) is stored without its encoded password. A bearer principal (`jwt`, `oauth2.resource_server`) is re-verified per request and never stored. A context a controller merely sets on the holder is saved on exit only when no filter cleared the holder first: never while `jwt` is on (that filter clears the holder unconditionally on exit); under `oauth2.resource_server`, which clears only a bearer context it established itself, whenever the request presented no bearer. Requires a session driver (boot refuses otherwise). |
| `firefly.security.session.fixation_protection` | `true` | Regenerate the session id on every interactive sign-in. |
| `firefly.security.form_login.enabled` | `false` | Form login (`FormLoginFilter`, `-92`, plus the login page route). Implies `session` and `logout`. |
| `firefly.security.form_login.login_page` | `/login` | The page a browser is redirected to; always permitted by `HttpSecurityFilter`. The framework mounts its own page there (`firefly.security.login`) **only when no `GET` route of yours already answers that path** — a `#[GetMapping('/login')]`, a routes-file route, with or without a domain. A page the application registers is left in place and the framework page is not mounted (Spring's rule: a custom login page belongs to the application), and the rest of the mechanism is unchanged: the entry point redirects there, the URL rules let it through, and `FormLoginFilter` answers the `POST` to `login_processing_url` before routing — your form only has to post the session token as `_token`. To customise the framework page without owning the route, use `view`. The framework page's `<form action>` is root-relative (the request's base path plus the path of `login_processing_url`), so it posts to the origin the browser fetched the page from even behind a TLS-terminating proxy the application does not trust. |
| `firefly.security.form_login.login_processing_url` | `/login` | The POST the filter authenticates. |
| `firefly.security.form_login.username_parameter` / `password_parameter` | `username` / `password` | Form field names. |
| `firefly.security.form_login.default_success_url` | `/` | Where a login goes when no request was saved. |
| `firefly.security.form_login.always_use_default_success_url` | `false` | Ignore the saved request. |
| `firefly.security.form_login.failure_url` | `/login?error` | Where a failed login is redirected. |
| `firefly.security.form_login.view` | `''` | A Blade view rendered instead of the framework page; receives `$login` (`LoginPageModel`: `title`, `action`, `usernameParameter`, `passwordParameter`, `csrfToken`, `error`, `loggedOut`, `rememberMeParameter`). A view that does not exist or that throws while rendering falls back to the framework page — a person who cannot sign in cannot fix the view — and the fallback is **logged at warning naming the view**, since a 200 with the framework form is otherwise indistinguishable from an unconfigured application. |
| `firefly.security.http_basic.enabled` | `false` | HTTP Basic (`HttpBasicFilter`, `-91`). |
| `firefly.security.http_basic.realm` | `LaraFly` | The `WWW-Authenticate` realm. |
| `firefly.security.http_basic.session` | `false` | Store a successful Basic authentication in the session (implies `session`). |
| `firefly.security.logout.enabled` | follows `form_login.enabled` | `LogoutFilter` (`-93`), POST only, CSRF-checked. |
| `firefly.security.logout.logout_url` / `logout_success_url` | `/logout` / `/login?logout` | The POST address and the redirect after it. |
| `firefly.security.logout.invalidate_session` / `clear_authentication` | `true` / `true` | Invalidate the whole session, or only remove the context. |
| `firefly.security.logout.delete_cookies` | `[]` | Extra cookie names expired on logout (the remember-me cookie always is). |
| `firefly.security.remember_me.enabled` | `false` | Signed remember-me cookie (`RememberMeAuthenticationFilter`, `-83`). Implies `session`. |
| `firefly.security.remember_me.key` | _(required when enabled)_ | HMAC key, ≥ 32 bytes, no placeholders — refused at boot like the JWT secret. |
| `firefly.security.remember_me.parameter` / `cookie_name` | `remember-me` / `remember-me` | The form checkbox name and the cookie name. |
| `firefly.security.remember_me.token_validity_seconds` | `1209600` | Cookie lifetime (14 days). |
| `firefly.security.remember_me.always_remember` | `false` | Set the cookie on every login, checkbox or not. |
| `firefly.security.jwt.enabled` | `false` | Enables `JwtAuthenticationFilter` + `JwtService` (refuses a weak secret at boot). Independent of the master flag. Mutually exclusive with `oauth2.resource_server.enabled` (refused at boot). |
| `firefly.security.jwt.secret` | _(required when jwt.enabled)_ | HMAC signing secret (≥ 32 bytes, no placeholders). |
| `firefly.security.jwt.algorithm` | `HS256` | HMAC algorithm passed to `JwtService`. |
| `firefly.security.jwt.leeway` | `0` | Clock-skew leeway in seconds when validating `exp`. |
| `firefly.security.jwt.authorities_claim` | `authorities` | Claim carrying the authority list. |
| `firefly.security.oauth2.resource_server.enabled` | `false` | Enables the JWKS resource-server filter. Independent of the master flag. Mutually exclusive with `jwt.enabled` (refused at boot). |
| `firefly.security.oauth2.resource_server.jwks_uri` | _(required when enabled)_ | Issuer JWKS URI (cached). |
| `firefly.security.oauth2.resource_server.cache_ttl` | `3600` | Seconds the fetched JWKS is cached for. |
| `firefly.security.oauth2.resource_server.jwks_source` | `auto` | `remote` fetches `jwks_uri` over HTTP; `local` answers from a `JwksDocumentSource` bean the application binds (the key set it signs its own tokens with) and refuses to boot without one; `auto` picks `local` when such a bean exists **and** `jwks_uri` names this application (`JwksUri::isOwn`: its `/.well-known/jwks.json` at `app.url`, or at a loopback address on `firefly.server.port`), `remote` otherwise. A server must never fetch its own keys from itself over HTTP — on a single-process dev server that nested request deadlocks the pool. |
| `firefly.security.oauth2.resource_server.jwks_connect_timeout` | `5` | Seconds to connect to the JWKS URI. Laravel's default (30) equals PHP's execution limit and turns a slow issuer into a fatal error. |
| `firefly.security.oauth2.resource_server.jwks_timeout` | `5` | Seconds to wait for the JWKS response. Any fetch failure — 5xx, refused, timed out, not JSON — is a `503 JWKS_UNAVAILABLE` (`JwksUnavailableException`), never a `401`: the token was not examined. |
| `firefly.security.oauth2.resource_server.issuer` | `''` | Expected `iss` claim. When set, a token whose `iss` doesn't match is rejected (confused-deputy protection, RFC 9700); empty skips the check. |
| `firefly.security.oauth2.resource_server.audience` | `''` | Expected `aud` claim (checked against a string or array `aud`, per RFC 7519). When set, a token whose `aud` doesn't include it is rejected; empty skips the check. |
| `firefly.security.oauth2.resource_server.authorities_claim` | `roles` | Claim carrying the authority list (distinct from the local-JWT default). |
| `firefly.security.http.enabled` | `false` | Enables the deny-by-default `HttpSecurityFilter`. **Requires the master flag** (see above). |
| `firefly.security.http.rules` | `[]` | Ordered `{pattern, access}` URL rules — see [the access vocabulary](#the-config-access-vocabulary-is-fixed-and-fail-closed). With the filter on, an empty list denies **everything** — deny-by-default is the point. |
| `firefly.security.http.entry_point` | `auto` | What an anonymous request to a protected URL gets: `auto` negotiates (browser + form login → login redirect with the request saved; else HTTP Basic on → `401` + `WWW-Authenticate`; else the 401 problem/page), `login`/`challenge`/`problem` force one. `login` without form login is refused at boot. A **browser** is a request that names `text/html` (or `application/xhtml+xml`), is not an `XMLHttpRequest` and is not under `firefly.web.error-page.json-paths` — the error page's own negotiation (`ErrorPageRenderer::prefersHtml`), and independent of `firefly.web.error-page.enabled`: that flag decides how a 401 is drawn, never whether a person is sent to sign in. |
| `firefly.security.csrf.enabled` | `false` | Enables `CsrfFilter` (`-80`). Independent of the master flag. Without a session it is the stateless double-submit check: the `XSRF-TOKEN` cookie echoed in `X-XSRF-TOKEN` (or `_token`). With a started session (`session.enabled`, or any mechanism that implies it) it verifies Laravel's session token instead, accepting exactly what Laravel's `PreventRequestForgery` accepts, in the same order: the `_token` field, the `X-CSRF-TOKEN` header, or the `X-XSRF-TOKEN` header carrying the **encrypted** `XSRF-TOKEN` cookie as the browser holds it and Axios sends it (decrypted through the application `Encrypter`; one that does not decrypt is a mismatch). The double-submit cookie value is ignored on that path. Any mismatch is a `403`. |
| `firefly.security.csrf.except` | `[]` | Path globs exempt from CSRF. |
| `firefly.security.headers.enabled` | `false` | Enables the security-headers filter. Independent of the master flag. |
| `firefly.security.headers.hsts` | `max-age=31536000; includeSubDomains` | `Strict-Transport-Security`. |
| `firefly.security.headers.frame_options` | `DENY` | `X-Frame-Options`. |
| `firefly.security.headers.content_type_options` | `nosniff` | `X-Content-Type-Options`. |
| `firefly.security.headers.referrer_policy` | `no-referrer` | `Referrer-Policy`. |
| `firefly.security.headers.csp` | `default-src 'self'` | `Content-Security-Policy`. |

## Testing

`firefly/testing` adds three things to `FireflyTestCase`:

```php
$this->actingAsPrincipal('ada', ['ROLE_USER', 'orders:read']);   // direct calls AND every HTTP request
$this->withoutSecurity();                                         // filters, dispatcher guard, bus authorizers and proxy link off

#[WithMockUser(name: 'admin', roles: ['ADMIN'], authorities: ['orders:write'])]   // on a class or a method
```

`actingAsPrincipal()` sets the holder now and prepends `ActingPrincipalMiddleware` to the kernel, so URL rules, the
dispatcher guard and proxied beans all see the principal; `withoutSecurity()` flips the flags every filter, the bus
authorizers and the proxy link read live and rebinds the dispatcher guard to the no-op default, so beans already
built change their gates without a rebuild; `#[WithMockUser]` is Spring's, honoured in `setUp()`, the method-level
one beating the class-level one. `Firefly\Testing\Double\RecordingAuthenticationEvents` is an
`ApplicationEventPublisher` that answers `successes()`, `interactive()`, `failures()`, `logouts()` and `denials()` —
bind it before boot from `defineFireflyEnvironment()`. Bound that way it replaces the port, so a listener registered
against the dispatcher hears nothing; a suite whose pipeline needs one to fire hands the framework's publisher in —
`new RecordingAuthenticationEvents(new DispatcherEventPublisher($app))` — and every event is recorded, then published
for real. The package's own suites are the reference:
`packages/security/tests/Support/SecurityCapstoneTestCase.php` boots the real providers under Testbench with a file
session driver, and every flow (login page, wrong password, right password → saved request, logout, remember-me
after the session is gone, Basic on an API path, entry-point negotiation, PostAuthorize on a service, principal
injection, the Eloquent driver) runs through the real HTTP pipeline.

## Laravel comparison

| Concern | Plain Laravel | LaraFly (`firefly/security`) |
|---|---|---|
| Principal | `Auth::user()` (Eloquent `Authenticatable`) | first-party immutable `Authentication`/`SecurityContext`, session-persisted, injected into actions |
| Sign-in | `Auth::attempt()`, Breeze/Fortify scaffolding | form login with a shipped page, HTTP Basic, remember-me, logout — all configuration |
| Authorization | Gates/Policies, `authorize()` | deny-by-default URL rules + `#[PreAuthorize]`/`#[PostAuthorize]`/filters on any bean, the bus and the dispatcher |
| Users | the `users` table | `memory` or `eloquent` driver behind one `UserDetailsService` port |
| JWT | a package + manual middleware | `JwtService` (mandatory `exp`, weak-secret refusal) + opt-in filters |
| Method rules | `$this->authorize()` in controllers | attribute-discovered, compiled manifest, no-eval expression engine, one proxy chain with `#[Transactional]` |
| CSRF / headers | `VerifyCsrfToken` + a headers package | session-token or double-submit `CsrfFilter` + `SecurityHeadersFilter`, config-driven |
| Testing | `actingAs($user)` | `actingAsPrincipal()`, `withoutSecurity()`, `#[WithMockUser]`, recording event doubles |

## Known-latent

The OAuth2 authorization server, OAuth2 client/login, real IdP adapters and MFA are the next waves. A `#[PreFilter]` on
a controller action cannot be applied by the dispatcher (it cannot rewrite the arguments it resolved), so the scan
refuses it outright rather than evaluate and discard — put it on the service the action calls; likewise a
`#[PostAuthorize]`/`#[PreFilter]`/`#[PostFilter]` on a class with no stereotype is refused, because no proxy and no
dispatch seam would ever enforce it. The CQRS bus enforces pre rules only, so `#[PostAuthorize]`/`#[PostFilter]` on a
handler are enforced only when the handler is proxied (non-final). `SessionSecurityBootstrap` excludes the session
middleware from the matched route as it is dispatched, so a route registered after boot is covered too; what it
cannot see is a middleware *alias* or group of the application's own that re-adds one of the three classes under
another name. The `SecurityMethodManifest` needs no hand-wiring: it is resolved from the `firefly:cache` artifact,
else an in-process scan, else empty (with `firefly.security.method.strict` available to refuse the last case).
