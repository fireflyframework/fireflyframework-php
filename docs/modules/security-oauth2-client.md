# OAuth2 Client / OpenID Connect Login

`firefly/security-oauth2-client` is Spring Security's `oauth2Login()` and `oauth2Client()` on top of
[`firefly/security`](security.md): client registrations spelled exactly as Spring Boot spells them (with presets
for Google, GitHub, Okta, Keycloak and Microsoft Entra, and OIDC discovery for everything else), authorization-code
login with PKCE and a nonce, id-token validation against the provider's JWKS, userinfo mapped to an `OidcUser` /
`OAuth2User` principal signed into the same session-persisted `SecurityContext` form login uses, the framework's
login page listing every provider, OIDC RP-initiated logout, an authorized-client manager for client credentials
and refresh, `Http::oauth2Client()`, and a whole provider in one class for tests. Every key lives under
`firefly.security.oauth2.client.*` and defaults to off.

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

With that, `GET /login` lists "Sign in with Google" and "Sign in with Corporate SSO", `GET /oauth2/authorization/google`
starts a login, `GET /login/oauth2/code/google` finishes it, a controller can take `#[AuthenticationPrincipal] OidcUser $user`,
and `Http::oauth2Client('billing')->get($url)` calls an API as the application.

## Registrations and providers

`firefly.security.oauth2.client.registration.{id}` and `provider.{id}` are Spring Boot's `spring.security.oauth2.client.*`
shape verbatim, read by `OAuth2ClientProperties` and turned into immutable `ClientRegistration` value objects by
`OAuth2ClientPropertiesMapper`:

| `registration.{id}.` | Default | Meaning |
|---|---|---|
| `provider` | the registration id | The `provider.{id}` block and/or the preset this registration uses. |
| `client_id` | _(required)_ | |
| `client_secret` | `''` | Required for `client_secret_basic`/`client_secret_post`. |
| `client_authentication_method` | `client_secret_basic` when a secret is set, `none` otherwise | `client_secret_basic` (HTTP Basic, RFC 6749 §2.3.1), `client_secret_post` (form body), `none` (a public client — PKCE is then mandatory). |
| `authorization_grant_type` | `authorization_code` | `authorization_code` (a login) or `client_credentials` (the application's own token). |
| `redirect_uri` | `{baseUrl}/login/oauth2/code/{registrationId}` | A template; `{baseUrl}` is the application's root as `UrlGenerator::to('/')` builds it (honouring `URL::forceScheme()`/`forceRootUrl()` behind a proxy). |
| `scope` | the preset's, else `[]` | A list, or a space/comma separated string. `openid` makes it an OpenID Connect login. |
| `client_name` | the preset's, else the id | The "Sign in with …" label. |
| `pkce` | `true` | PKCE (S256) for a confidential client; a public client always uses it. |

| `provider.{id}.` | Default | Meaning |
|---|---|---|
| `issuer_uri` | — | OIDC discovery: every endpoint below that is not set is read from `{issuer}/.well-known/openid-configuration`. |
| `authorization_uri`, `token_uri`, `jwk_set_uri`, `user_info_uri`, `end_session_uri` | discovered | Explicit endpoints; an explicit key always wins over the discovered value. |
| `user_name_attribute` | `sub` | The claim / userinfo attribute that names the principal (`id` for GitHub). |

**Presets** (`CommonOAuth2Provider`): a `provider` value — or a registration id — of `google`, `github`, `okta`,
`keycloak` or `microsoft` (alias `entra`) starts from the preset's endpoints, scopes, `client_name` and
`client_authentication_method`, and your `provider.{id}` keys overlay it. Google and GitHub are complete; Okta, Keycloak
and Microsoft are per-tenant and **require `issuer_uri`**, from which discovery learns the rest.

**Validation happens at boot**, statically and without a request: a missing `client_id`, a secret-less registration
with a method that needs one, an unknown grant type or method, a provider that is neither a preset nor configured,
a per-tenant preset without its issuer, a provider without `issuer_uri` that does not spell out `authorization_uri`
and `token_uri` (plus `jwk_set_uri` for an `openid` registration, `user_info_uri` for a plain OAuth2 one), an unknown
setting (`client-id` is refused, not ignored), and a login with no registration at all are each a
`ConfigurationException` naming the key. The `ClientRegistrationRepository` port (`findByRegistrationId()`,
`registrationIds()`, `all()`) is what every filter and the manager read; bind your own to source registrations from
a table of tenants.

## Discovery

`OidcDiscovery` fetches the well-known document through Laravel's Http client with the same bounded timeouts the
resource server's JWKS fetch uses (`http.connect_timeout`, `http.timeout`, 5 s each), validates it — the `issuer`
must equal the configured `issuer_uri` (OIDC Discovery §4.3), `authorization_endpoint` and `token_endpoint` must be
present — **before** caching the raw document for `discovery.cache_ttl`, and answers every failure (a 5xx, a refused
connection, a timeout, a body that is not JSON, a foreign issuer) as `ProviderDiscoveryException`: a **503
`OIDC_DISCOVERY_UNAVAILABLE`** naming the host, never the URI. Resolution is **lazy** — a registration is resolved
on first use and memoised for the process, so `firefly:cache` and console boots never need the provider —
unless `discovery.eager` is on, in which case every issuer is discovered at boot and a dead one fails the boot
(Spring Boot's behaviour). The login page omits a provider whose discovery is down at that moment, with a warning,
rather than taking every other way in down with it.

## The login flow

```
GET /oauth2/authorization/{id}   OAuth2AuthorizationRequestRedirectFilter (-89)
  → OAuth2AuthorizationRequestResolver: state (32 random bytes), nonce (always, for `openid`), PKCE verifier (S256)
  → SessionAuthorizationRequestRepository (one request per session)
  → 302 {authorization_uri}?response_type=code&client_id&scope&state&redirect_uri&nonce&code_challenge&code_challenge_method=S256

GET /login/oauth2/code/{id}?code&state   OAuth2LoginAuthenticationFilter (-88)
  → the authorization request is PULLED from the session first (single use, whatever follows)
  → `error` in the query → the provider's RFC 6749 code
  → none saved / another registration's → authorization_request_not_found; state ≠ (hash_equals) → invalid_state_parameter
  → the callback URL ≠ the request's redirect_uri → invalid_redirect_uri; no code → invalid_request
  → OAuth2LoginAuthenticationProvider
      DefaultOAuth2AccessTokenResponseClient: POST token_uri (form, Basic/post/none client auth, code_verifier, the two timeouts)
      openid: OidcIdTokenDecoder (RS256 against the JWKS through RemoteJwksProvider, exp) → OidcIdTokenValidator
              (iss, aud, azp, iat, sub, nonce — OIDC Core §3.1.3.7) → DefaultOidcUserService (userinfo when the
              endpoint exists AND a profile/email/address/phone scope was granted; its `sub` must match) → DefaultOidcUser
      else:   DefaultOAuth2UserService: GET user_info_uri with the bearer → DefaultOAuth2User
      GrantedAuthoritiesMapper bean (optional) → OAuth2AuthenticationToken::of(user, mapped, registrationId)
  → session id regenerated, SecurityContextRepository::save(), the authorized client saved (session + cache, encrypted),
    AuthenticationSuccessEvent + InteractiveAuthenticationSuccessEvent('oauth2-login'),
    302 saved request | login.default_success_url
  → any OAuth2AuthenticationException → AuthenticationFailureBadCredentialsEvent (username '', ip) + 302 login.failure_url
```

Both filters answer their own paths before `HttpSecurityFilter`, so no URL rule is needed for them; both are gated
by the security master flag, the package master and `login.enabled`, re-read live. A 503 from discovery or the JWKS
is not a refused login and propagates as the 503 it is — the token was never examined.

**The login page.** `login.enabled` implies the framework's login page, the session middleware and logout exactly as
`form_login.enabled` does (`FormLoginSettings::$pageEnabled`). The page lists every `authorization_code` registration
as a "Sign in with {client_name}" button through firefly/security's `LoginPageLinks` port — after the password form
when form login is on too, alone (no form) when it is not — and its `?error` notice reads "Signing in with the
provider did not work" when there is no form. The entry point sends an anonymous browser there, with the request
saved, as for form login.

## Principals

- `OAuth2User` — `getName()`, `getAttributes()`, `getAttribute()`, `getAuthorities()` (Spring's).
- `OidcUser extends OAuth2User, ClaimAccessor` — plus `getIdToken()`, `getUserInfo()`, `getSubject()`, `getEmail()`,
  `getFullName()`, `getPreferredUsername()`, `getClaims()`, `getClaim()`, `hasClaim()`. Its claims are the id token's
  with the userinfo's written over them (Spring's precedence); the name is `user_name_attribute` read from that merge.
- `DefaultOidcUser` is a `CredentialsContainer`: the copy the session stores has the id token's **raw value blanked**
  (claims kept), the way the shipped `User` is stored without its hash. The raw id token, the access token and the
  refresh token live only in the authorized-client entry, which is **encrypted** with the application key.
- Authorities: `OIDC_USER` (an `OidcUserAuthority` carrying the claims) or `OAUTH2_USER` (`OAuth2UserAuthority`,
  the attributes), then `SCOPE_x` per granted scope — the same spelling the resource-server filter uses, so
  `hasScope('x')` reads both. The **`GrantedAuthoritiesMapper`** port is where an application adds `ROLE_*` from a
  `groups`/`roles` claim: bind an implementation as a `#[Bean]`; it receives the granted list (the authority carrying
  the claims first) and returns the list the `Authentication` carries, while the principal keeps the granted one.
- The `Authentication` carries the registration id on its attributes (`OAuth2AuthenticationToken::REGISTRATION_ID`,
  read back with `OAuth2AuthenticationToken::registrationId($authentication)`), which the manager and RP-initiated
  logout use to know which provider signed the person in.

### Principal injection and expressions

`#[AuthenticationPrincipal] OidcUser $user` (a 401 when the principal is not one — a form login's `User`, an acting
`string`), `#[AuthenticationPrincipal] ?OAuth2User $user` (null for those) and `?Authentication $auth` all work through
firefly/security's `SecurityArgumentResolver` unchanged: an attributed principal is handed over when it *is* what the
parameter declares. `hasScope('orders:read')` / `hasAnyScope(...)` are in `SecurityExpressionRoot` (a bare scope is
normalised to `SCOPE_`), in `#[PreAuthorize]` expressions, and in the URL vocabulary as `hasScope:orders:read`;
`SecurityExpressionEvaluator::authorities()` reports them as `SCOPE_x` in `requiredAuthorities`.

## Logout

`logout.oidc_initiated` binds `OidcClientInitiatedLogoutSuccessHandler` on firefly/security's `LogoutSuccessHandler`
port. The `LogoutFilter` asks it **before** invalidating the session — so it can still read the encrypted authorized
client — and it answers a 302 to the provider's `end_session_endpoint` (explicit `end_session_uri` or discovered)
with `id_token_hint`, `client_id` and `post_logout_redirect_uri` (`logout.post_logout_redirect_uri`, `{baseUrl}`
expanded; default `{baseUrl}/login?logout`, the framework page's signed-out notice). A principal that did not sign in
through OAuth2, a registration that no longer exists, or a provider without an end-session endpoint (logged at
warning) gets the default `logout_success_url` redirect — the person is signed out of this application either way.

## Client credentials, refresh, and outbound calls

`OAuth2AuthorizedClientManager::authorize($registrationId, $principalName = null)` (Spring's) hands out an
`OAuth2AuthorizedClient` — the registration **id**, the principal name, the access token, the refresh token, the raw id
token; never the registration, so a secret never enters a session or a cache:

- a **`client_credentials`** registration is fetched with the client's own credentials and configured scopes, cached
  per registration (and per `$principalName` when one is given) in the `OAuth2AuthorizedClientService` (the Laravel
  cache, encrypted), and refetched when the access token is within `clock_skew` seconds of expiring;
- an **`authorization_code`** registration is read from the session (`OAuth2AuthorizedClientRepository`, the encrypted
  entry the login wrote) when the current request has one, else from the cache service under `$principalName` (else
  the signed-in name) — the path a queued job takes — refreshed with the refresh token when about to expire (the
  rotated refresh token and the id token kept, the result written back to both stores), and refused with
  `ClientAuthorizationRequiredException` (**401 `CLIENT_AUTHORIZATION_REQUIRED`**: sign in through that registration)
  when there is nothing, or nothing to refresh with. A refresh the provider answers `invalid_grant` to removes the
  dead entry and propagates as the **503 `OAUTH2_INVALID_GRANT`** it is; the next call is the 401.

`Http::oauth2Client('billing')` (`PendingRequest`) is Spring's `ServletOAuth2AuthorizedClientExchangeFilterFunction`:
the application's Http factory with that bearer attached — faked by `Http::fake()`, traced by the observability
wave's client middleware, `->retry()`-able like any other request. `Http::oauth2Client('corp', 'ada')` names the
principal. Registered at provider `register()` time behind `http.macro` (so Larastan types it); calling it with the
package off is a `ConfigurationException` naming `firefly.security.oauth2.client.enabled`.

## Hardening

- `state` is **single-use** (pulled from the session before anything is compared), **session-bound** (it lives nowhere
  else) and compared in **constant time**; the callback URL must equal the request's `redirect_uri` exactly.
- **PKCE** is on by default and cannot be switched off for a public client; a **nonce** is always sent for `openid`
  and always checked (constant time) on the id token; `iss`, `aud`, `azp`, `iat`, `sub` and `exp` are checked per
  OIDC Core §3.1.3.7 with `clock_skew` seconds of leeway.
- Tokens are stored **encrypted** with the application key (session entry and cache entry); the principal in the
  session carries claims and never a token; `ClientRegistration::__debugInfo()` masks the secret; the token exchange's
  exceptions name the registration and the RFC code and never the code, the verifier, the secret or a token.
- Every outbound call (discovery, token, JWKS, userinfo) has bounded timeouts and goes through the container's Http
  factory (traced, fakeable).
- Both filters clear `SecurityContextHolder` in a `finally`, so nothing bleeds into the next request under Octane.

### Filter order

| Order | Filter |
|---|---|
| -95 | `SecurityHeadersFilter` |
| -94 | `SecurityContextPersistenceFilter` |
| -93 | `LogoutFilter` |
| -92 | `FormLoginFilter` |
| -91 | `HttpBasicFilter` |
| -90 | `JwtAuthenticationFilter` |
| **-89** | **`OAuth2AuthorizationRequestRedirectFilter`** |
| **-88** | **`OAuth2LoginAuthenticationFilter`** |
| -85 | `OAuth2ResourceServerFilter` |
| -83 | `RememberMeAuthenticationFilter` |
| -80 | `CsrfFilter` |
| -70 | `HttpSecurityFilter` |

## Configuration (`firefly.security.oauth2.client.*`, snake_case)

`enabled` is the **package master**: registrations, discovery, the token client, the authorized-client stores and
manager, and the `Http::oauth2Client()` macro's target. It does **not** need `firefly.security.enabled` — a job that
calls an API with client credentials has no inbound security to speak of. `login.enabled` **does** need it (the login
filters sign into master-gated beans) and is refused at boot without it, or without `enabled`, or with no
registration at all; `logout.oidc_initiated` is refused without `login.enabled`.

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `false` | The package master (see above). |
| `registration.{id}.*` | — | `provider`, `client_id`, `client_secret`, `client_authentication_method`, `authorization_grant_type`, `redirect_uri`, `scope`, `client_name`, `pkce` — see [Registrations and providers](#registrations-and-providers). |
| `provider.{id}.*` | — | `issuer_uri`, `authorization_uri`, `token_uri`, `jwk_set_uri`, `user_info_uri`, `user_name_attribute`, `end_session_uri`. |
| `login.enabled` | `false` | `oauth2Login()`: the two filters, the login-page links, the session repository of authorized clients. Implies the login page, the session middleware and logout (`FormLoginSettings::$pageEnabled`, `SessionSecuritySettings`, `LogoutSettings`). Requires `enabled` and `firefly.security.enabled`. |
| `login.authorization_endpoint_base_uri` | `/oauth2/authorization` | `GET {base}/{id}` starts a login. |
| `login.redirection_endpoint_base_uri` | `/login/oauth2/code` | `GET {base}/{id}` is the callback; also the default `redirect_uri` template's path. |
| `login.default_success_url` | `/` | Where a login lands when no request was saved. |
| `login.always_use_default_success_url` | `false` | Ignore the saved request. |
| `login.failure_url` | `/login?error` | Where a refused login is redirected. |
| `logout.oidc_initiated` | `false` | RP-initiated logout on the `LogoutSuccessHandler` port. Requires `login.enabled`. |
| `logout.post_logout_redirect_uri` | `{baseUrl}/login?logout` | Sent to the provider as `post_logout_redirect_uri` (`{baseUrl}` and `{registrationId}` expand). Register it at the provider. |
| `clock_skew` | `60` | Seconds of leeway on `exp`/`iat` of an id token, and the margin before an access token's expiry at which the manager refreshes it. |
| `http.connect_timeout` / `http.timeout` | `5` / `5` | Seconds, for discovery, the token endpoint, the JWKS and userinfo. Laravel's default (30) equals PHP's execution limit. |
| `http.macro` | `true` | Whether `Http::oauth2Client()` is registered. |
| `discovery.cache_ttl` | `3600` | Seconds the raw discovery document is cached (validated before caching; a failure caches nothing). |
| `discovery.eager` | `false` | Resolve every registration at boot: a dead issuer fails the boot (`ProviderDiscoveryException`), Spring Boot's behaviour. |
| `jwk_set.cache_ttl` | `3600` | Seconds a provider's JWKS is cached (`RemoteJwksProvider`). |
| `authorized_client.cache_ttl` | `86400` | Seconds a client that can be renewed (a refresh token, or an access token without expiry) stays in the cache service; a bare one stays until its access token expires. |

## Testing

`Firefly\Testing\Security\OAuth2\FakeAuthorizationServer` is a whole OpenID Connect provider in one class:

```php
abstract class LoginTestCase extends SecurityCapstoneTestCase   // or any FireflyTestCase
{
    public FakeAuthorizationServer $idp;

    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.security.oauth2.client.enabled' => true,
            'firefly.security.oauth2.client.login.enabled' => true,
            'firefly.security.oauth2.client.registration.fake' => FakeAuthorizationServer::registrationConfig(),
            'firefly.security.oauth2.client.provider.fake' => FakeAuthorizationServer::providerConfig('http://localhost/fake-idp'),
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        $this->idp = FakeAuthorizationServer::install($app, 'http://localhost/fake-idp');
    }
}
```

`install()` mounts the **front channel** as real routes on the application at the issuer's path (`/authorize`,
auto-approving or — `requireConsent()` — rendering a consent page whose form approves; `/end-session`) and fakes the
**back channel** with `Http::fake()` for exactly `{issuer}/.well-known/openid-configuration`, `/token`, `/jwks` and
`/userinfo` (anything else is left to the real client, so a stray request is still a stray request). Tokens are RS256
JWTs signed with a key pair generated once per process; codes are single-use, bound to their `redirect_uri` and
PKCE-checked; the token endpoint authenticates the client by Basic header, form body, or — `acceptPublicClient()` — as a
public client; refresh tokens rotate; userinfo needs a token it issued; the clock is `Date::now()`, so `$this->travel()`
expires tokens. Every hop is recorded (`authorizationRequests`, `tokenRequests`, `userInfoRequests`, `endSessionRequests`,
`discoveryRequests`, `jwksRequests`, `issuedIdTokens`, `issuedAccessTokens`; `lastAuthorizationRequest()`,
`lastTokenRequest()`), and the knobs make each failure reproducible: `withUser()`, `expiresIn()`,
`overrideIdTokenClaims()` (a null removes a claim), `signWithUnknownKey()`, `refuseToken()`, `refuseAuthorization()`,
`withoutUserInfo()`, `takeDiscoveryDown()`. Laravel's test client follows the redirects in-process; the browser suite
(`tests/Browser/OAuth2LoginTest.php`) drives the same fake from Chromium, because the plugin serves the app in-process.

`FireflyTestCase::actingAsOidcUser()` signs a real `OidcUser` in for the rest of a test — claims, scopes, extra
authorities, the registration id — with no provider involved:

```php
$this->actingAsOidcUser(['sub' => 'ada', 'email' => 'ada@example.com'], ['ROLE_ADMIN'], 'okta', ['openid', 'profile']);
```

and `actingAsAuthentication(Authentication $token)` is the seam beneath it and `actingAsPrincipal()`. The package's own
suites are the reference: `packages/security-oauth2-client/tests/Support/OAuth2ClientCapstoneTestCase.php` boots the
real providers with the fake installed, and every flow (the login page, the redirect, the callback, each refusal, the
principal in a controller, scope rules, the mapper, RP-initiated logout, the manager and the macro) runs through the
real HTTP pipeline.

## Laravel comparison

| Concern | Plain Laravel | LaraFly (`firefly/security-oauth2-client`) |
|---|---|---|
| Social / SSO login | Socialite: a driver per provider, a controller you write, `Auth::login()` | configuration in Spring Boot's shape, presets + OIDC discovery, two filters, the framework login page |
| Id token | not validated (Socialite reads userinfo) | signature against the JWKS, `iss`/`aud`/`azp`/`exp`/`iat`/`nonce`/`sub` per OIDC Core |
| PKCE / state | driver-dependent | PKCE S256 by default (mandatory for public clients), single-use constant-time state, exact `redirect_uri` |
| Principal | an Eloquent user you create from the provider's payload | `OidcUser`/`OAuth2User` with claims, `SCOPE_*`, a `GrantedAuthoritiesMapper` seam, injected into actions |
| Logout at the provider | manual | `logout.oidc_initiated` on the `LogoutSuccessHandler` port |
| Calling APIs | manual token storage and refresh | `OAuth2AuthorizedClientManager`, `Http::oauth2Client()`, encrypted stores |
| Testing | `Socialite::fake()` or mocks | `FakeAuthorizationServer` (a real provider, in-process) and `actingAsOidcUser()` |

## Known-latent

Remember-me is not set on an OAuth2 login (Spring's `AbstractAuthenticationProcessingFilter` would call
`rememberMeServices.loginSuccess()`); the session's own lifetime is the login's. `ClientAuthorizationRequiredException`
from a controller is answered as the 401 it is — Spring's redirect filter would start the authorization flow for that
registration instead, which Laravel's route pipeline (which turns a controller's exception into a response before any
global middleware sees it) does not allow at the filter level. A registration whose tokens should be shared between
processes without a request (a queue worker acting as a person) relies on the cache service, whose entries expire with
`authorized_client.cache_ttl`; a durable store is a `OAuth2AuthorizedClientService` binding of your own. The
authorization server, real IdP adapters beyond the presets, and MFA are the next waves.
