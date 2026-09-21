# firefly/security-oauth2-client

Spring Security's `oauth2Login()` and `oauth2Client()` for LaraFly, on top of `firefly/security`'s
session-persisted principal model. Configure a registration the way Spring Boot spells it and the framework
does the rest: the login page lists the provider, `GET /oauth2/authorization/{id}` sends the browser there
with a state, a nonce and a PKCE challenge, `GET /login/oauth2/code/{id}` exchanges the code, validates the id
token against the provider's JWKS, loads userinfo, maps the claims to an `OidcUser` principal and signs it into
the session — every step through the same filters, events and entry point form login uses.

```php
// config/firefly.php
'security' => [
    'enabled' => true,
    'http' => ['enabled' => true, 'rules' => [['pattern' => '*', 'access' => 'authenticated']]],
    'oauth2' => [
        'client' => [
            'enabled' => true,
            'login' => ['enabled' => true],
            'registration' => [
                'google' => ['client_id' => env('GOOGLE_CLIENT_ID'), 'client_secret' => env('GOOGLE_CLIENT_SECRET')],
                'corp' => ['provider' => 'keycloak', 'client_id' => 'portal', 'client_secret' => env('KC_SECRET'), 'client_name' => 'Corporate SSO'],
                'billing' => ['provider' => 'corp', 'client_id' => 'billing-job', 'client_secret' => env('BILLING_SECRET'), 'authorization_grant_type' => 'client_credentials', 'scope' => ['invoices:read']],
            ],
            'provider' => [
                'corp' => ['issuer_uri' => 'https://sso.example.com/realms/corp'],
            ],
        ],
    ],
],
```

## What you get

| Surface | Class |
| --- | --- |
| Registrations from config, presets for `google`, `github`, `okta`, `keycloak`, `microsoft`/`entra`, OIDC discovery of `issuer_uri` (cached, lazy, `discovery.eager` to resolve at boot) | `ClientRegistrationRepository`, `CommonOAuth2Provider`, `OidcDiscovery` |
| Authorization-code login with PKCE (on by default, always for a public client) and a nonce for `openid` | `OAuth2AuthorizationRequestRedirectFilter` (`-89`), `OAuth2LoginAuthenticationFilter` (`-88`) |
| Id-token validation: RS256 signature via the provider's JWKS, `iss`, `aud`, `azp`, `exp`, `iat`, `nonce`, `sub` | `OidcIdTokenDecoder`, `OidcIdTokenValidator` |
| Principals: `OidcUser` (claims, id token, userinfo) and `OAuth2User` (attributes), authorities `OIDC_USER`/`OAUTH2_USER` + `SCOPE_x`, a `GrantedAuthoritiesMapper` bean to add `ROLE_*` from claims | `DefaultOidcUser`, `DefaultOAuth2User`, `OidcUserAuthority` |
| `#[AuthenticationPrincipal] OidcUser $user` in a controller, `hasScope('x')` in URL rules and method rules | firefly/security's resolver and expression root |
| RP-initiated logout (`end_session_endpoint` with `id_token_hint`) | `OidcClientInitiatedLogoutSuccessHandler` |
| Client credentials and refresh: `OAuth2AuthorizedClientManager::authorize('billing')`, `Http::oauth2Client('billing')->get(...)` | `DefaultOAuth2AuthorizedClientManager`, `OAuth2ClientHttpMacros` |
| Tests: a whole provider in one class, and `actingAsOidcUser()` | `Firefly\Testing\Security\OAuth2\FakeAuthorizationServer` |

Tokens are stored in the session **encrypted** with the application key, the principal the session carries
holds claims and never a token string, and the token exchange never puts the client secret, the code or a
token into an exception message or a log line.

Every key lives under `firefly.security.oauth2.client.*` and defaults to off; the reference is
`config/firefly.php` in the skeleton and the module guide is `docs/modules/security-oauth2-client.md`.

Apache-2.0 © Firefly Software Solutions Inc.
