# Security

`firefly/security` is LaraFly's first-party security core — a Spring-Security-6-shaped principal model, authentication,
and deny-by-default authorization that **lights up** the seams shipped in earlier milestones (the CQRS
`Command`/`QueryAuthorizer`, the M8 `AuditorAware`, the M6 web filter chain) and adds a dispatch-time controller
guard. Secure-by-default, fail-closed, opt-in, zero boot reflection.

## Principal model

- `Authentication` — an immutable token (`authenticated()` / `unauthenticated()` factories); credentials are erased
  on success. `GrantedAuthority` / `SimpleGrantedAuthority` wrap authority strings.
- `SecurityContext` — an immutable snapshot; `anonymous()` is the zero-value.
- `SecurityContextHolder` — a request-scoped static holder backed by Laravel's `Context` (per-request, Octane-safe);
  the auth filters clear it on exit.

## Authentication

- Ports: `UserDetails` / `UserDetailsService` (default `InMemoryUserDetailsService::fromConfig`), `PasswordEncoder`
  (`BcryptPasswordEncoder`, `Argon2idPasswordEncoder`, `DelegatingPasswordEncoder` with `{id}` prefixes — constant-time).
- `AuthenticationProvider` / `ProviderManager` (first-supports-wins) / `DaoAuthenticationProvider` (maps bad-credentials/
  disabled/locked to 401 subtypes; timing- and content-safe against username enumeration).
- `JwtService` — HMAC encode/decode with a **mandatory `exp`** claim and a **weak-secret boot refusal**.
- `JwtAuthenticationFilter` (Bearer, local JWT) and `OAuth2ResourceServerFilter` (JWKS via the `JwksProvider` port,
  scopes → `SCOPE_*`) — both opt-in, both establish and clear the `SecurityContext` per request. The resource-server
  filter additionally validates `iss`/`aud` when configured (see the config table) — protection against a
  confused-deputy/token-redirection attack (RFC 9700), where a JWKS-signed token minted by the same issuer for a
  *different* audience would otherwise be accepted.

**JWT/OAuth2 are mutually exclusive.** Enabling both `firefly.security.jwt.enabled` and
`firefly.security.oauth2.resource_server.enabled` is refused at **boot** (`ConfigurationException`, fail-closed): the
local-JWT filter runs first (`#[Order(-90)]`) and unconditionally rejects any Bearer token it cannot decode with the
local HMAC secret, before the resource-server filter (`#[Order(-85)]`) — which validates against the issuer's
JWKS — ever gets a chance to run. Pick exactly one bearer mechanism, matching Spring's one-resource-server-mechanism
model.

## Authorization

- **URL (deny-by-default):** the `HttpSecurity` DSL builds an ordered rule list; `HttpSecurityFilter` evaluates
  first-match-wins and denies anything unmatched — 401 when anonymous, 403 when authenticated.
- **Method:** `#[PreAuthorize('…')]`, `#[Secured('…')]`, `#[RolesAllowed('…')]`. A single `MethodSecurityScanner`
  compiles them into a `var_export` manifest (the sole reflection site). Enforced at the CQRS bus (real
  `Command`/`QueryAuthorizer`), the controller dispatcher (`ControllerSecurityGuard`), and imperatively
  (`AuthorizationChecker`). The scanner rejects, at compile (cache) time, any `#[Secured]`/`#[RolesAllowed]`
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

## Web hardening

- `CsrfFilter` — stateless double-submit cookie (safe-method + path exemptions, constant-time compare).
- `SecurityHeadersFilter` — HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, and a conservative CSP.

## Configuration (`firefly.security.*`, snake_case)

`firefly.security.enabled` is the **master flag**. It gates the core security stack: the password encoder, user
store, role hierarchy, permission evaluator, expression evaluator, authentication manager, `AuthorizationChecker`,
the real CQRS `Command`/`QueryAuthorizer`, and the real `AuditorAware`. Among the per-surface flags below, **only
`firefly.security.http.enabled` additionally requires the master flag** — `HttpSecurityFilter`'s constructor consumes
three master-gated beans (`SecurityExpressionEvaluator`, `RoleHierarchy`, `PermissionEvaluator`), so the URL-rule
filter cannot construct at all unless both flags are on. `jwt`, `oauth2.resource_server`, `csrf`, and `headers` are
**independent** of the master flag — each is a `Config`-only (or single-extra-bean) filter gated solely by its own
flag, so it can be turned on without enabling the broader security stack. (Turning on `jwt`/`oauth2` alone
authenticates a principal but enforces no authorization unless `http`/master or the CQRS/method-security seams are
also enabled — pair it with `http.enabled` + master, or with method security, to actually gate anything.)

| Key | Default | Meaning |
|---|---|---|
| `firefly.security.enabled` | `false` | Master flag — enables the core stack (password encoder, user store, role hierarchy, permission evaluator, expression evaluator, authentication manager, `AuthorizationChecker`, CQRS authorizers, `AuditorAware`). Required by the `http` surface flag below (its filter depends on master-gated beans); `jwt`/`oauth2.resource_server`/`csrf`/`headers` do not require it. |
| `firefly.security.users` | _(unset)_ | `InMemoryUserDetailsService` map (`{username: {password, authorities, enabled, locked}}`). |
| `firefly.security.role_hierarchy` | `[]` | Single-arrow implication rules, e.g. `["ROLE_ADMIN > ROLE_USER"]` (one implication per entry — not chainable in one string). |
| `firefly.security.jwt.enabled` | `false` | Enables `JwtAuthenticationFilter` + `JwtService` (refuses a weak secret at boot). Independent of the master flag. Mutually exclusive with `oauth2.resource_server.enabled` (refused at boot). |
| `firefly.security.jwt.secret` | _(required when jwt.enabled)_ | HMAC signing secret (≥ 32 bytes, no placeholders). |
| `firefly.security.jwt.authorities_claim` | `authorities` | Claim carrying the authority list. |
| `firefly.security.oauth2.resource_server.enabled` | `false` | Enables the JWKS resource-server filter. Independent of the master flag. Mutually exclusive with `jwt.enabled` (refused at boot). |
| `firefly.security.oauth2.resource_server.jwks_uri` | _(required when enabled)_ | Issuer JWKS URI (cached). |
| `firefly.security.oauth2.resource_server.issuer` | _(unset)_ | Expected `iss` claim. When set, a token whose `iss` doesn't match is rejected (confused-deputy protection, RFC 9700). |
| `firefly.security.oauth2.resource_server.audience` | _(unset)_ | Expected `aud` claim (checked against a string or array `aud`, per RFC 7519). When set, a token whose `aud` doesn't include it is rejected. |
| `firefly.security.http.enabled` | `false` | Enables the deny-by-default `HttpSecurityFilter`. **Requires the master flag** (see above). |
| `firefly.security.http.rules` | `[]` | Ordered `{pattern, access}` URL rules. |
| `firefly.security.csrf.enabled` | `false` | Enables the double-submit CSRF filter. Independent of the master flag. |
| `firefly.security.csrf.except` | `[]` | Path globs exempt from CSRF. |
| `firefly.security.headers.enabled` | `false` | Enables the security-headers filter (all values overridable). Independent of the master flag. |

## Laravel comparison

| Concern | Plain Laravel | LaraFly (`firefly/security`) |
|---|---|---|
| Principal | `Auth::user()` (Eloquent `Authenticatable`) | first-party immutable `Authentication`/`SecurityContext` |
| Authorization | Gates/Policies, `authorize()` | deny-by-default URL rules + `#[PreAuthorize]`/`#[Secured]` at the bus + dispatch |
| JWT | a package + manual middleware | `JwtService` (mandatory `exp`, weak-secret refusal) + opt-in filters |
| Method rules | `$this->authorize()` in controllers | attribute-discovered, compiled manifest, no-eval expression engine |
| CSRF / headers | `VerifyCsrfToken` + a headers package | double-submit `CsrfFilter` + `SecurityHeadersFilter`, config-driven |

## Known-latent

The OAuth2 authorization-server, OAuth2 client/login, and real IdP adapters (Keycloak/Cognito/Entra/internal-db, MFA)
are deferred to their own future SP-cycle packages (matching the Java 20-repo topology). Generalising `#[PreAuthorize]`
to **any** bean method (a second interceptor composed into the M8 transaction proxy) is a flagged P0 spike, not in
M11 — method security here is enforced only at the CQRS bus, the controller dispatcher, and the imperative
`AuthorizationChecker`. As with the other capability modules, **app-level manifest compilation via `firefly:cache`
lands in M15**; until then an application (or its test suite) supplies its compiled `SecurityMethodManifest` inline
(run `MethodSecurityScanner::scan()` + bind the result). Under Octane the `SecurityContextHolder` is per-request state
cleared by every auth filter on exit.
