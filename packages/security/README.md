# firefly/security

LaraFly's security core: a first-party, immutable `SecurityContext` / `Authentication` / `GrantedAuthority`
principal model held in a request-scoped `SecurityContextHolder` (backed by Laravel's `Context`, cleared per
request), authentication ports with an `InMemoryUserDetailsService`, `PasswordEncoder`s, a `JwtService`
(mandatory `exp`, weak-secret refusal), a `ProviderManager`/`DaoAuthenticationProvider`, a Bearer auth filter
and an OAuth2 resource-server (JWKS) filter, deny-by-default URL authorization (`HttpSecurity` DSL +
`HttpSecurityFilter`), method security (`#[PreAuthorize]`/`#[Secured]`/`#[RolesAllowed]`) enforced at the CQRS
bus, the controller dispatcher, and a programmatic `AuthorizationChecker` via a single reflection-free
method-security manifest and a **no-`eval`** whitelist expression evaluator, and CSRF + security-headers
hardening filters. It supplies the real `CommandAuthorizer`/`QueryAuthorizer`/`AuditorAware`/`ControllerSecurityGuard`
for the seams shipped in M8/M10/M6. Secure-by-default, fail-closed, opt-in, zero boot reflection.

Apache-2.0 © Firefly Software Solutions Inc.
