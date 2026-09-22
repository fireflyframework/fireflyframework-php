# firefly/security-oauth2-server

LaraFly's OAuth2 authorization server (Spring Authorization Server's shape): an OAuth 2.1 / OpenID Connect 1.0
provider that runs inside the application. Registered clients (a config map or Eloquent), the authorization-code
grant with PKCE and the framework's own consent page, client credentials, refresh tokens with rotation and reuse
detection, RS256/ES256 JWT (or opaque reference) access tokens, id tokens, JWKS publishing through the security
core's `JwksDocumentSource` — so the same application can be its own resource server — RFC 8414 / OIDC discovery
metadata, RFC 7662 introspection, RFC 7009 revocation, OIDC userinfo, RP-initiated logout and RFC 7591 dynamic
registration, every endpoint answered by one filter ordered ahead of the CSRF and URL-rule filters, every error
the RFC 6749 JSON document or the redirect-with-error, every token stored as a hash. Off by default under
`firefly.security.oauth2.server.enabled`; see `docs/modules/security-oauth2-server.md`.

Apache-2.0 © Firefly Software Solutions Inc.
