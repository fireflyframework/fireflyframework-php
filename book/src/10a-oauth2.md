<span class="eyebrow">Part III — Coordinating and Securing the Application · Chapter 10A</span>

# OAuth2 and OpenID Connect: Signing In, and Being the Provider {.chtitle}

By the end of this chapter you will know which of the two OAuth2 packages you are holding and why they are two — `firefly/security-oauth2-client` makes your application a **relying party** that signs people in through Google, Keycloak, Okta, Entra or any conformant provider, and `firefly/security-oauth2-server` makes it an **authorization server** that issues the tokens somebody else consumes. You will know the two filters the client half adds at `-89` and `-88`, the two base URIs they answer on, and what the framework puts in an authorization request that Chapter 10's form login never had to think about: a single-use state, a nonce, and an S256 PKCE challenge. You will know what an `OidcUser` is once it reaches your controller, how a `groups` claim becomes a `ROLE_`, how logging out reaches the provider's session as well as yours, and how a queued job calls an API as the application with no person involved at all. Then the picture turns around: the same round trip, from the side that mints the code — the consent page, the token endpoint, refresh-token rotation with reuse detection, introspection, revocation, userinfo, the JWKS, and the one command that generates the key it all hangs from.

!!! note "New term: relying party"
    In OpenID Connect the application that sends a person to a provider to be identified is the **relying party** (the RP) — it *relies* on somebody else's assertion about who the person is, instead of holding the password itself. The provider is the **OpenID Provider** or, in plain OAuth2 terms, the **authorization server**. The two packages in this chapter are those two roles, and the words matter because a single deployment is regularly both: an internal portal that signs its staff in through corporate SSO *and* issues tokens to the mobile app it owns.

---

## Two packages, two directions

Chapter 10 ended with a filter chain and a warning that three of its numbers belonged to packages that chapter did not install. Here they are. Neither package is a dependency of the other — both depend only on `firefly/security` — and installing one never drags the other in:

```bash
composer require firefly/security-oauth2-client   # be a relying party: sign people in through a provider
composer require firefly/security-oauth2-server   # be the provider: issue the tokens yourself
```

`firefly/security-oauth2-client` contributes `OAuth2AuthorizationRequestRedirectFilter` (`-89`) and `OAuth2LoginAuthenticationFilter` (`-88`), which sit between the JWT filter and the resource-server filter in the chain you already know. `firefly/security-oauth2-server` contributes exactly one, `OAuth2AuthorizationServerFilter` (`-82`), which answers **every** endpoint the server owns. Both halves default to off, key by key, and both are configured under `firefly.security.oauth2.*` — the same block Chapter 10 used for `resource_server`, which is the third OAuth2 role and the one that only *verifies* a token somebody else minted.

One pairing is refused outright at boot, and it is worth reading the refusal rather than the summary of it, because the reason is a filter order and you now know how to read one:

<!-- source: packages/security-oauth2-server/src/Boot/OAuth2ServerWiringPass.php -->
```php
/**
 * Refusals (1)–(4), as one static so the rules are testable against a bare Config without a boot; a no-op
 * while the server is off.
 */
public static function assertRunnable(Config $config): void
{
    if (! $config->bool('firefly.security.oauth2.server.enabled', false)) {
        return;
    }

    // …
    if ($config->bool('firefly.security.http_basic.enabled', false)) {
        throw new ConfigurationException(
            'firefly.security.oauth2.server.enabled and firefly.security.http_basic.enabled are both on: HttpBasicFilter (-91) '
            .'answers every Authorization: Basic header as a user login — a 401 and a failure event — before the server\'s '
            .'filter (-82) could read a client_secret_basic credential at the token, introspection or revocation endpoint. '
            .'Turn http_basic.enabled off; the server authenticates its clients itself.'
        );
    }
}
```

`HttpBasicFilter` (`-91`) runs before `OAuth2AuthorizationServerFilter` (`-82`), and an OAuth2 client authenticating with `client_secret_basic` sends exactly the header the Basic filter was built to consume. With both switches on, every confidential client in your deployment would be answered `401` as a failed *user* login — with a failure event published for it — before the authorization server's filter ever saw the credential. Nothing about that failure would mention OAuth2. So the boot refuses the pair and names both keys, which is the framework's standing rule for a combination that could only ever produce a confusing runtime error. The same static refuses three more: the server without `firefly.security.enabled`, the server with nothing to carry a principal between requests, and the server beside `firefly.security.jwt.enabled` (whose HMAC filter at `-90` would reject every RS256 token this server issues).

---

## Signing in with a provider

Everything in this section is the relying-party half. The shape of it is Spring Boot's, key for key, and the shipped reference is where the defaults live:

<!-- source: skeleton/config/firefly.php -->
```php
'client' => [
    'enabled' => env('FIREFLY_OAUTH2_CLIENT_ENABLED', false),

    'login' => [
        'enabled' => env('FIREFLY_OAUTH2_LOGIN_ENABLED', false),
        // 'authorization_endpoint_base_uri' => '/oauth2/authorization',
        // 'redirection_endpoint_base_uri' => '/login/oauth2/code',
        // 'default_success_url' => '/',
        // 'always_use_default_success_url' => false,
        // 'failure_url' => '/login?error',
    ],

    'logout' => [
        'oidc_initiated' => env('FIREFLY_OAUTH2_OIDC_LOGOUT', false),
        // 'post_logout_redirect_uri' => '{baseUrl}/login?logout',
    ],

    // Seconds of leeway on exp/iat of an id token, and the margin before an access token's expiry
    // at which the manager refreshes it. Default: 60.
    'clock_skew' => 60,

    // Outbound calls (discovery, token endpoint, JWKS, userinfo): the same bounded timeouts the
    // resource server's JWKS fetch uses, and whether Http::oauth2Client('{id}') is registered.
    'http' => [
        'connect_timeout' => 5,
        'timeout' => 5,
        'macro' => true,
    ],

    'discovery' => [
        'cache_ttl' => 3600,
        'eager' => env('FIREFLY_OAUTH2_DISCOVERY_EAGER', false),
    ],

    'jwk_set' => [
        'cache_ttl' => 3600,
    ],

    // How long a user-bound client that holds a refresh token stays in the cache service.
    'authorized_client' => [
        'cache_ttl' => 86400,
    ],

    'registration' => [
        // 'google' => [
        //     'client_id' => env('GOOGLE_CLIENT_ID'),
        //     'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // ],
        // 'corp' => [
        //     'provider' => 'keycloak',
        //     'client_id' => 'portal',
        //     'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),
        //     'client_authentication_method' => 'client_secret_basic', // client_secret_basic | client_secret_post | none
        //     'authorization_grant_type' => 'authorization_code',      // authorization_code | client_credentials
        //     'redirect_uri' => '{baseUrl}/login/oauth2/code/{registrationId}',
        //     'scope' => ['openid', 'profile', 'email'],
        //     'client_name' => 'Corporate SSO',
        //     'pkce' => true,
        // ],
    ],

    'provider' => [
        // 'keycloak' => [
        //     'issuer_uri' => 'https://sso.example.com/realms/corp',
        //     'authorization_uri' => null,
        //     'token_uri' => null,
        //     'jwk_set_uri' => null,
        //     'user_info_uri' => null,
        //     'user_name_attribute' => 'sub',
        //     'end_session_uri' => null,
        // ],
    ],
],
```

Two commented-out blocks carry the whole model. A **registration** is one relationship with one provider: a client id, a secret, the scopes to ask for, the grant to use. A **provider** is one issuer's endpoints. A registration names its provider (or, when it does not, its own id is taken as the provider's name), and five names are **presets** — `google`, `github`, `okta`, `keycloak` and `microsoft` (alias `entra`) — whose endpoints, default scopes, `client_name` and client-authentication method are built in. Google and GitHub are complete as they stand; the other three are per-tenant and need `provider.{id}.issuer_uri`, from which **OIDC discovery** reads the rest out of `{issuer}/.well-known/openid-configuration`. Discovery is lazy — a registration resolves on first use and is memoised for the process, so `firefly:cache` and console boots never need the provider to be reachable — unless `discovery.eager` is on, which resolves every registration at boot and fails the boot on a dead issuer, exactly as Spring Boot does.

Everything that can be checked without a request **is** checked without one: a missing `client_id`, a secret-less registration on a method that needs a secret, an unknown grant type, a provider that is neither a preset nor configured, a per-tenant preset with no issuer, a provider with no `issuer_uri` that does not spell out its endpoints, a misspelt key (`client-id` is refused, not ignored) and `login.enabled` with no registration at all are each a `ConfigurationException` naming the key, at boot.

With `login.enabled` on, two URLs exist. `GET /oauth2/authorization/{id}` starts a sign-in and `GET /login/oauth2/code/{id}` finishes it — the defaults of `login.authorization_endpoint_base_uri` and `login.redirection_endpoint_base_uri`, and the second is also the path the default `redirect_uri` template points at. Both are answered by their own filter, *before* `HttpSecurityFilter` (`-70`), so neither needs a URL rule of its own. The framework's login page from Chapter 10 grows one "Sign in with {client_name}" button per `authorization_code` registration — after the password form when form login is also on, and alone when it is not.

::: figure art/figures/oauth2-authorization-code.svg | Figure 10A.1 — The authorization-code round trip with PKCE: the browser, the relying party and the authorization server, with every real endpoint path.

Read the figure left to right and notice what the browser never carries. The start URL builds an authorization request and keeps it in the session; only its public halves — the `state`, the `nonce`, the `code_challenge` — travel through the browser. The verifier that the challenge was derived from stays behind, and is spent in a back-channel `POST` the browser never makes. Three random values, one generator:

<!-- source: packages/security-oauth2-client/src/Web/OAuth2AuthorizationRequestResolver.php -->
```php
/**
 * Builds the authorization request for a registration (Spring's DefaultOAuth2AuthorizationRequestResolver):
 * a fresh `state` every time (32 random bytes, base64url — 43 characters), a `nonce` whenever `openid` is
 * requested (OIDC Core §3.1.2.1 makes it optional; this package makes it mandatory, because the nonce is
 * what ties the id token to THIS browser's request and there is no reason to ever leave it out), a PKCE
 * verifier whenever the registration uses PKCE (by default; always for a public client), and the redirect
 * URI expanded from the application's base URL. The three random values share one generator: 256 bits from
 * random_bytes(), and the base64url alphabet is a subset of the RFC 7636 verifier alphabet.
 */
final class OAuth2AuthorizationRequestResolver
{
    public function resolve(ClientRegistration $registration, string $baseUrl): OAuth2AuthorizationRequest
    {
        return new OAuth2AuthorizationRequest(
            registrationId: $registration->registrationId,
            authorizationUri: $registration->providerDetails->authorizationUri,
            clientId: $registration->clientId,
            redirectUri: RedirectUriTemplate::expand($registration->redirectUri, $baseUrl, $registration->registrationId),
            scopes: $registration->scopes,
            state: self::random(),
            nonce: $registration->usesOpenId() ? self::random() : null,
            codeVerifier: $registration->usesPkce() ? self::random() : null,
        );
    }

    /** 32 random bytes as base64url: 43 characters of `[A-Za-z0-9_-]`. */
    public static function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
```

The return leg is where the refusals live, and the order they run in is itself a decision. The saved authorization request is **pulled** from the session first — single use, whatever happens next — so a replayed callback finds nothing to compare against. Then, in order: a provider that answered `?error=` hands back its own RFC 6749 code; no saved request (or one belonging to another registration) is `authorization_request_not_found`; a `state` that does not match, compared with `hash_equals`, is `invalid_state_parameter`; a callback URL that is not exactly the request's `redirect_uri` is `invalid_redirect_uri`; a missing code is `invalid_request`. Only then is the code exchanged.

What comes back is examined before anybody is signed in. For an `openid` registration the id token's signature is verified against the provider's JWKS (cached for `jwk_set.cache_ttl`), and then `iss`, `aud`, `azp`, `iat`, `sub` and the `nonce` are checked per OIDC Core §3.1.3.7 with `clock_skew` seconds of leeway; userinfo is fetched when the provider has that endpoint and a `profile`, `email`, `address` or `phone` scope was granted, and its `sub` must match the id token's. A plain OAuth2 registration — no `openid` — has no id token to check and is identified from `user_info_uri` alone. Then the session id is regenerated, the `SecurityContext` is saved the way Chapter 10's form login saves it, and the browser is sent to the request that was saved when it was first refused, or to `login.default_success_url`.

!!! warning "A 503 from the provider is not a refused login"
    Discovery, the JWKS fetch and userinfo all go through Laravel's Http client with both timeouts bounded at five seconds — Laravel's own default is thirty, which is PHP's execution limit, so a slow issuer would be a fatal error rather than an error page. When one of them is genuinely unreachable the result is a **503**, not a 401 and not a redirect to `/login?error`: nothing about the person's credentials was examined, and telling them "signing in did not work" would send them to try a password they do not have. The login page omits a provider whose discovery is down at that moment, with a warning logged, rather than taking every other way in down with it.

---

## The person in your controller

After a successful login the principal in the session is an `OidcUser` (or an `OAuth2User` for a registration without `openid`), and Chapter 10's `SecurityArgumentResolver` injects it with no extra wiring. The package's own fixture controller is every way an application reads one:

<!-- source: packages/security-oauth2-client/tests/Fixtures/Flows/AccountController.php -->
```php
/**
 * Every way an application reads an OAuth2 principal: `#[AuthenticationPrincipal] OidcUser` (a 401 when the
 * principal is not one), the nullable `?OAuth2User` that serves both kinds, `hasScope` as a URL rule (the
 * capstone's `api/scoped`) and as a method rule, and a role a GrantedAuthoritiesMapper must have added.
 */
#[RestController]
final class AccountController
{
    /** @return array{name: string, subject: string, email: ?string, fullName: ?string, groups: mixed, idTokenValue: string, hasUserInfo: bool, issuer: mixed} */
    #[GetMapping('/account')]
    public function account(#[AuthenticationPrincipal] OidcUser $user): array
    {
        return [
            'name' => $user->getName(),
            'subject' => $user->getSubject(),
            'email' => $user->getEmail(),
            'fullName' => $user->getFullName(),
            'groups' => $user->getClaim('groups'),
            'idTokenValue' => $user->getIdToken()->getTokenValue(),
            'hasUserInfo' => $user->getUserInfo() !== null,
            'issuer' => $user->getAttribute('iss'),
        ];
    }
    // …
    /** @return array{email: bool} */
    #[PreAuthorize("hasScope('email')")]
    #[GetMapping('/api/email')]
    public function email(): array
    {
        return ['email' => true];
    }

    /** @return array{engineers: bool} */
    #[PreAuthorize("hasRole('ENGINEERING')")]
    #[GetMapping('/api/engineers')]
    public function engineers(): array
    {
        return ['engineers' => true];
    }
}
```

`OidcUser` extends `OAuth2User` and `ClaimAccessor`: on top of `getName()`, `getAttributes()` and `getAuthorities()` it has `getIdToken()`, `getUserInfo()`, `getSubject()`, `getEmail()`, `getFullName()`, `getPreferredUsername()` and the `getClaim()`/`hasClaim()` pair. Its claims are the id token's with the userinfo's written over them — Spring's precedence — and `getName()` reads whichever claim `provider.{id}.user_name_attribute` names (`sub` by default; `id` for GitHub).

Three of those return values are worth pausing on, and the test that pins them says so out loud:

<!-- source: packages/security-oauth2-client/tests/Web/Login/OAuth2PrincipalFlowTest.php -->
```php
/**
 * The principal after an OIDC login, as a controller sees it on the NEXT request — read back from the session:
 * an OidcUser with the merged claims, no raw id token, the userinfo, and `SCOPE_x` honoured by a URL rule and a
 * method rule.
 */
uses(OAuth2ClientCapstoneTestCase::class, SecurityFlows::class);

it('injects the OidcUser into a controller with its claims, and honours hasScope in a URL rule and a method rule', function () {
    /** @var OAuth2ClientCapstoneTestCase $this */
    $callback = $this->signInThroughProvider();
    $this->forgetSession();
    $client = $this->followSession($callback);

    $account = $client->getJson('/account');
    $account->assertOk()->assertJson([
        'name' => 'ada',
        'subject' => 'ada',
        'email' => 'ada@example.com',
        'fullName' => 'Ada Lovelace',
        'groups' => ['engineering'],
        'idTokenValue' => '',
        'hasUserInfo' => true,
        'issuer' => OAuth2ClientCapstoneTestCase::ISSUER,
    ]);

    $client->getJson('/account/any')->assertOk()->assertJson(['name' => 'ada', 'kind' => 'oidc']);
    $client->getJson('/api/scoped')->assertOk()->assertJson(['scoped' => true]);
    $client->getJson('/api/email')->assertOk()->assertJson(['email' => true]);
    $client->getJson('/api/engineers')->assertStatus(403);

    expect($this->events->denials())->toHaveCount(1);
});
```

`'idTokenValue' => ''` is the first. `DefaultOidcUser` is a `CredentialsContainer`, exactly as the shipped `User` of Chapter 10 is, so the copy the session stores has the id token's **raw value blanked** while every claim decoded out of it survives. The raw id token, the access token and the refresh token live in one place only — the authorized-client entry — and that entry is **encrypted** with the application key. A principal in a session is a set of facts about a person, not a bearer credential somebody could lift out of a session file.

The second is `/api/engineers` answering `403`. The provider granted `ada` a `groups` claim of `['engineering']`, and nothing in OAuth2 says what a group means to your application — so by default it means nothing, and `hasRole('ENGINEERING')` is false. The authorities an OAuth2 login carries are `OIDC_USER` (an `OidcUserAuthority` holding the claims) or `OAUTH2_USER`, plus one `SCOPE_x` per granted scope — the same spelling Chapter 10's resource-server filter produces, which is why `hasScope('email')` reads both without knowing which filter authenticated the request. Turning a claim into a role is the application's decision, and it has a port:

<!-- source: packages/security-oauth2-client/tests/Fixtures/Mapper/GroupsAuthoritiesConfiguration.php -->
```php
/** How an application binds its mapper: a #[Bean] of the port type; the login provider's optional dependency picks it up. */
#[Configuration]
final class GroupsAuthoritiesConfiguration
{
    #[Bean]
    public function grantedAuthoritiesMapper(): GrantedAuthoritiesMapper
    {
        return new GroupsAuthoritiesMapper;
    }
}
```

<!-- source: packages/security-oauth2-client/tests/Fixtures/Mapper/GroupsAuthoritiesMapper.php -->
```php
/** The mapper an application writes: `groups: [engineering]` from the provider becomes `ROLE_ENGINEERING`, on top of what was granted. */
final class GroupsAuthoritiesMapper implements GrantedAuthoritiesMapper
{
    public function mapAuthorities(array $authorities): array
    {
        $mapped = $authorities;
        foreach ($authorities as $authority) {
            if (! $authority instanceof OAuth2UserAuthority) {
                continue;
            }
            $groups = $authority->getAttributes()['groups'] ?? [];
            foreach (is_array($groups) ? $groups : [] as $group) {
                if (is_string($group) && $group !== '') {
                    $mapped[] = new SimpleGrantedAuthority('ROLE_'.strtoupper($group));
                }
            }
        }

        /** @var list<GrantedAuthority> $mapped */
        return $mapped;
    }
}
```

Bind that `#[Bean]` and the same test's `/api/engineers` answers `200`, because `GrantedAuthoritiesMapper` receives the granted list — the authority carrying the claims first — and returns the list the `Authentication` will carry. The principal keeps the granted one, so a mapper can add without erasing what the provider actually said.

The third is that the `Authentication` also remembers **which** registration signed the person in, on its attributes, read back with `OAuth2AuthenticationToken::registrationId($authentication)`. That is not bookkeeping: logging out and calling an API both need to know which provider to talk to, and the next two sections are those two needs.

---

## Signing out again

`logout.oidc_initiated` binds one bean on the `LogoutSuccessHandler` port Chapter 10 introduced. `LogoutFilter` (`-93`) asks its success handler **before** it invalidates the session — which is the only reason the handler can still read the encrypted authorized client and find the raw id token to send:

<!-- source: packages/security-oauth2-client/src/Web/Logout/OidcClientInitiatedLogoutSuccessHandler.php -->
```php
/**
 * OpenID Connect RP-Initiated Logout 1.0 (Spring's OidcClientInitiatedLogoutSuccessHandler): after the
 * application's own logout, the browser is sent to the provider's `end_session_endpoint` so the provider's
 * session ends too, with `id_token_hint` (the raw id token of the login — read from the encrypted authorized
 * client the session still holds, because the LogoutFilter asks its success handler BEFORE invalidating the
 * session), `client_id`, and `post_logout_redirect_uri` — `logout.post_logout_redirect_uri` with `{baseUrl}`
 * (and `{registrationId}`) expanded from the application's root, the way the redirect_uri is.
 *
 * WHICH PROVIDER: the registration id the login recorded on the token's attributes. A principal that did not
 * sign in through OAuth2 (a form login in the same application), a registration that no longer exists, a
 * provider with no end-session endpoint (explicitly configured, or a discovery document that names none), or
 * a provider whose discovery is down at that moment, all hand back to the default redirect — the person IS
 * signed out of this application either way, and the last three are logged at warning so an operator sees
 * that the provider's session outlives the application's. Nothing here reads the token's raw value into a
 * log or an exception.
 */
final class OidcClientInitiatedLogoutSuccessHandler implements LogoutSuccessHandler
{
    public function __construct(
        private readonly ClientRegistrationRepository $registrations,
        private readonly OAuth2AuthorizedClientRepository $authorizedClients,
        private readonly OAuth2ClientSettings $settings,
        private readonly Container $container,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    // …
    public function onLogoutSuccess(Request $request, ?Authentication $authentication): ?Response
    {
        $registrationId = OAuth2AuthenticationToken::registrationId($authentication);
        if ($registrationId === null) {
            return null;
        }
        // …
        $endSession = $registration->providerDetails->endSessionUri;
        if ($endSession === null || $endSession === '') {
            $this->logger?->warning("RP-initiated logout skipped for [{$registrationId}]: its provider has no end_session_endpoint (set provider.{$registrationId}.end_session_uri, or accept that the provider's session outlives this one).", ['registration' => $registrationId]);

            return null;
        }

        /** @var UrlGenerator $urls */
        $urls = $this->container->make(UrlGenerator::class);
        $idToken = $this->authorizedClients->loadAuthorizedClient($registrationId, $request)?->idToken;

        $query = [];
        if ($idToken !== null && $idToken !== '') {
            $query['id_token_hint'] = $idToken;
        }
        $query['client_id'] = $registration->clientId;
        $query['post_logout_redirect_uri'] = RedirectUriTemplate::expand($this->settings->postLogoutRedirectUri, $urls->to('/'), $registrationId);

        return new RedirectResponse($endSession.(str_contains($endSession, '?') ? '&' : '?').http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }
}
```

Every branch that returns `null` is a branch where the person is still signed out of *this* application — the handler only decides whether the browser also visits the provider. That is the right shape for a handler at this position: a provider that has no `end_session_endpoint`, a registration somebody deleted from the configuration this morning, or a provider whose discovery is down cannot be allowed to turn "log out" into an error page. Three of the four are logged at warning, so an operator can see that the provider's session is outliving the application's rather than guessing.

`post_logout_redirect_uri` defaults to `{baseUrl}/login?logout` — the framework login page with its signed-out notice, the same one form login lands on — and it has to be **registered at the provider**, which is the one step of this that is not in your own configuration file. The whole round trip is driven end to end in Chromium:

<!-- source: tests/Browser/OAuth2LoginTest.php -->
```php
    // 6. Sign out: the logout POST went to the provider's end-session endpoint with the id token, which sent the
    //    browser back to the post-logout URI — the framework login page, signed-out notice showing.
    $page->navigate('/browser-fixture/account')
        ->assertSee('Signed in as ada')
        ->press('Sign out')
        ->assertPathIs('/login')
        ->assertQueryStringHas('logout')
        ->assertSee('You have signed out.')
        ->assertSee('Sign in with Fake IdP')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'oauth2-signed-out');

    expect($this->idp->endSessionRequests)->toHaveCount(1)
        ->and($this->idp->endSessionRequests[0]['id_token_hint'] ?? null)->toBe($this->idp->issuedIdTokens[0])
        ->and($this->idp->endSessionRequests[0]['client_id'] ?? null)->toBe(FakeAuthorizationServer::CLIENT_ID)
        ->and($this->idp->endSessionRequests[0]['post_logout_redirect_uri'] ?? null)->toBe($origin.'/login?logout');

    // 7. The session is gone: the protected page is refused again.
    $page->navigate('/browser-fixture/account')->assertPathIs('/login')->assertDontSee('Signed in as');
```

---

## Calling an API as the application

Signing a person in is one of the two things an OAuth2 client does. The other is holding a token so that *your* code can call somebody else's API — a nightly job that posts invoices, a controller that reads a partner's catalogue. That has no browser in it at all, which is why `firefly.security.oauth2.client.enabled` deliberately does **not** require `firefly.security.enabled`: a queue worker with an outbound token has no inbound security to speak of.

`OAuth2AuthorizedClientManager::authorize()` is the one entry point, and it is Spring's two managers in one class because the only thing that differs between them is where the client is looked up:

<!-- source: packages/security-oauth2-client/src/Authorized/DefaultOAuth2AuthorizedClientManager.php -->
```php
public function authorize(string $clientRegistrationId, ?string $principalName = null): OAuth2AuthorizedClient
{
    $registration = $this->registrations->findByRegistrationId($clientRegistrationId)
        ?? throw new ConfigurationException("There is no client registration [{$clientRegistrationId}] under firefly.security.oauth2.client.registration to authorize.");
    $now = Date::now()->getTimestamp();

    if ($registration->authorizationGrantType === AuthorizationGrantType::ClientCredentials) {
        return $this->clientCredentials($registration, $principalName ?? $registration->clientId, $now);
    }

    return $this->userBound($registration, $principalName, $now);
}
// …
private function clientCredentials(ClientRegistration $registration, string $principalName, int $now): OAuth2AuthorizedClient
{
    $id = $registration->registrationId;
    $client = $this->service->loadAuthorizedClient($id, $principalName);
    if ($client !== null && ! $client->accessToken->isExpired($now, $this->settings->clockSkewSeconds)) {
        return $client;
    }

    $response = $this->tokens->clientCredentials($registration, $registration->scopes);
    $client = new OAuth2AuthorizedClient($id, $principalName, $response->accessToken, $response->refreshToken);
    $this->service->saveAuthorizedClient($client);

    return $client;
}
```

A `client_credentials` registration is the application's own token: looked up in the `OAuth2AuthorizedClientService` (the Laravel cache, encrypted), fetched when it is absent or within `clock_skew` seconds of expiring, and cached — one token per process pool, not one per request. An `authorization_code` registration is a *person's* token: read from the session first when the current request has one (the login put it there, and the request is what binds it to that browser), else from the cache service under the principal name — the path a queued job takes — and refreshed with the refresh token when it is about to expire, keeping the rotated refresh token and the id token and writing the result back to both stores. With nothing to return and nothing to refresh with, the answer is a `ClientAuthorizationRequiredException`: a **401 `CLIENT_AUTHORIZATION_REQUIRED`** that says, in effect, *sign in through that registration first*.

Notice what an `OAuth2AuthorizedClient` holds: the registration **id**, the principal name and the tokens — never the `ClientRegistration` itself. A client secret therefore never enters a session or a cache entry, whatever an application later does with the object.

You will rarely call the manager yourself, because there is a macro over it:

<!-- source: packages/security-oauth2-client/src/Http/OAuth2ClientHttpMacros.php -->
```php
/**
 * `Http::oauth2Client('{registrationId}')` — Spring's ServletOAuth2AuthorizedClientExchangeFilterFunction as a
 * Laravel Http macro: a PendingRequest from the application's Http factory carrying the bearer the
 * OAuth2AuthorizedClientManager hands out for the registration (and, optionally, for a named principal), so
 * `Http::oauth2Client('billing')->get($url)` fetches or refreshes the token, attaches it, and is otherwise
 * exactly `Http::get($url)`: faked by Http::fake(), traced by the observability wave's client middleware,
 * retried with ->retry(), and so on.
 *
 * REGISTERED AT PROVIDER register() TIME, not from a boot pass, so Larastan — which boots the discovered providers
 * when it analyses — types the call; behind `firefly.security.oauth2.client.http.macro` (default true). The
 * closure resolves the factory AND the manager from the container on every call: the factory is
 * the instance Http::fake() stubs (and the one the facade swaps), and the manager is a bean of the package
 * master, so calling the macro with the package off is a ConfigurationException naming the key — not a
 * container error deep inside Laravel. The closure does not read `$this` (the factory it is bound to) on
 * purpose: resolving the factory from the container answers the same instance and keeps PHPStan out of the
 * closure-binding question.
 */
final class OAuth2ClientHttpMacros
{
    public const string OAUTH2_CLIENT = 'oauth2Client';

    public static function register(Container $app): void
    {
        // NOT a static closure: Macroable::__call() binds the closure to the factory instance, and PHP warns
        // ("Cannot bind an instance to a static closure") on every call when asked to bind a static one.
        HttpFactory::macro(self::OAUTH2_CLIENT, function (string $registrationId, ?string $principalName = null) use ($app): PendingRequest {
            if (! $app->bound(OAuth2AuthorizedClientManager::class)) {
                throw new ConfigurationException("Http::oauth2Client('{$registrationId}') needs firefly.security.oauth2.client.enabled: the OAuth2AuthorizedClientManager is a bean of the package master.");
            }

            /** @var OAuth2AuthorizedClientManager $manager */
            $manager = $app->make(OAuth2AuthorizedClientManager::class);
            /** @var HttpFactory $http */
            $http = $app->make(HttpFactory::class);

            return $http->withToken($manager->authorize($registrationId, $principalName)->accessToken->tokenValue);
        });
    }
}
```

`Http::oauth2Client('billing')->get($url)` is `Http::get($url)` with a bearer on it, and it stays an ordinary Laravel Http call in every way that matters to a test: `Http::fake()` intercepts it, the observability wave's client middleware traces it, and `->retry()` still works. `Http::oauth2Client('corp', 'ada')` names the principal for a user-bound registration. The one thing to remember about the cache-backed store is that `authorized_client.cache_ttl` (a day, by default) is how long a renewable client survives there; a registration whose tokens must outlive that — a worker acting as a person for weeks — wants an `OAuth2AuthorizedClientService` binding of your own over a table, which is a single `#[Bean]`.

---

## Running your own authorization server

Turn the picture around. `firefly/security-oauth2-server` is Spring Authorization Server's model inside your application: registered clients, the authorization-code grant with PKCE and a consent page, client credentials, refresh tokens, JWT or opaque access tokens, and the discovery document that tells everybody else where all of it lives. Its configuration block is longer than the client's because it is a protocol surface rather than a relationship, but every value below is a default you can ignore until you need it:

<!-- source: skeleton/config/firefly.php -->
```php
'server' => [
    'enabled' => env('FIREFLY_OAUTH2_SERVER_ENABLED', false),
    // …
    // 'authorization_endpoint' => '/oauth2/authorize',
    // 'token_endpoint' => '/oauth2/token',
    // 'jwk_set_endpoint' => '/oauth2/jwks',
    // 'token_introspection_endpoint' => '/oauth2/introspect',
    // 'token_revocation_endpoint' => '/oauth2/revoke',
    // 'oidc_user_info_endpoint' => '/userinfo',
    // 'oidc_logout_endpoint' => '/connect/logout',
    // 'oidc_client_registration_endpoint' => '',
    // …
    'jwt' => [
        'signing_key' => env('FIREFLY_OAUTH2_SERVER_SIGNING_KEY', ''),
        // 'key_id' => '',
        // 'algorithm' => 'RS256',
        // 'previous_keys' => [],
    ],
    // …
    'access_token' => [
        // 'format' => 'self_contained',
        // 'ttl' => 300,
    ],
    // …
    'refresh_token' => [
        // 'ttl' => 3600,
        // 'reuse' => false,
    ],
    // …
    'authorization_code' => [
        // 'ttl' => 300,
    ],

    'id_token' => [
        // 'ttl' => 1800,
    ],
    // …
    // 'require_pkce' => true,
    // 'require_proof_key_for_public_clients' => true,
    // …
    'consent' => [
        // 'required' => true,
        // 'view' => '',
    ],
    // …
    'clients' => [
        'driver' => env('FIREFLY_OAUTH2_SERVER_CLIENTS_DRIVER', 'memory'),
        // 'web-app' => [
        //     'client_id' => 'web-app',
        //     'client_secret' => '{bcrypt}$2y$10$…',
        //     'client_name' => 'The web application',
        //     'client_authentication_methods' => ['client_secret_basic'],
        //     'authorization_grant_types' => ['authorization_code', 'refresh_token'],
        //     'redirect_uris' => ['https://app.example.com/login/oauth2/code/web-app'],
        //     'post_logout_redirect_uris' => ['https://app.example.com/'],
        //     'scopes' => ['openid', 'profile', 'email'],
        //     'client_settings' => ['require_pkce' => true, 'require_authorization_consent' => true],
        //     'token_settings' => ['access_token_ttl' => 300, 'refresh_token_ttl' => 3600, 'reuse_refresh_tokens' => false, 'access_token_format' => 'self_contained', 'authorization_code_ttl' => 300, 'id_token_ttl' => 1800],
        // ],
    ],
    // …
    'authorizations' => [
        'driver' => env('FIREFLY_OAUTH2_SERVER_AUTHORIZATIONS_DRIVER', 'memory'),
        'purge' => [
            // 'enabled' => false,
            // 'cron' => '*/15 * * * *',
        ],
    ],
    // …
    'rate_limit' => [
        // 'enabled' => false,
        // 'max_tokens' => 60,
        // 'refill_rate' => 1.0,
    ],
],
```

Those paths — plus the two `/.well-known/` documents that advertise them — are what `OAuth2AuthorizationServerFilter` (`-82`) answers, and its position in the chain answers two questions people ask immediately. It runs *after* the session filter at `-94` and the bearer filters — because the authorization endpoint needs whatever principal there is — and *before* `CsrfFilter` (`-80`) and `HttpSecurityFilter` (`-70`), which is why a machine's `POST /oauth2/token` is never asked for a session token and why no deny-by-default URL rule can accidentally close the server. Your `http.rules` may end with `*` → `authenticated` and the metadata, the JWKS and the token endpoint still answer; the authorization and userinfo endpoints check the principal themselves. Nothing needs adding to `http.rules` or to `csrf.except`, and the one browser form the server owns — the consent POST — is checked against the session token by the endpoint itself, exactly as the login and logout filters check theirs. A request whose path is none of the server's costs one path comparison and falls through.

| Path (default) | Method | Answers |
|---|---|---|
| `/.well-known/openid-configuration`, `/.well-known/oauth-authorization-server` | GET | One discovery document (OIDC's is a superset of RFC 8414's): every endpoint URL, the grants, the client-authentication methods, `S256`, and the signing algorithm |
| `/oauth2/jwks` | GET | The public keys — current first, previous after — with `Cache-Control: public, max-age=3600` |
| `/oauth2/authorize` | GET, POST | The authorization endpoint and the consent form |
| `/oauth2/token` | POST | `authorization_code` (with `code_verifier`), `client_credentials`, `refresh_token`; credentials are read from the body only, never the query string |
| `/oauth2/introspect` | POST | RFC 7662: `{active: false}` for anything unknown, revoked, expired, or a code |
| `/oauth2/revoke` | POST | RFC 7009: a refresh token takes its access token with it; unknown is `200` |
| `/userinfo` | GET, POST | The claims of the `OidcUserInfoMapper`, for a bearer carrying `openid` |
| `/connect/logout` | GET, POST | RP-initiated logout: the other side of the section above |
| `/connect/register` (off) | POST | RFC 7591 dynamic registration, behind a single-use bearer carrying `client.create` |

The authorization endpoint is the one with a person in it, and it runs in a fixed order: the `client_id` and an exactly-matching registered `redirect_uri` first — refused **on the page**, never redirected, because RFC 6749 §4.1.2.1 says an unverified redirect target is where an attacker would like the error to go — then `response_type=code`, the client's grants, the requested scopes and PKCE, each of which *is* a redirect carrying `error=`. Only then does it look for a resource owner, and it looks in one place: the principal the `SecurityContextRepository` holds **between** requests. A bearer authenticated by the resource-server filter at `-85` is not a resource owner and cannot redeem itself for a code. With nobody signed in, the `AuthenticationEntryPoint` of Chapter 10 takes over — the login page, with the authorization request saved — and the return visit lands back on `/oauth2/authorize` to meet the consent page.

Consent is the framework's own page, built from the login page's design and carrying no JavaScript, unless `consent.view` names a Blade view of yours; either way the model is a `ConsentPageModel` with the client name, the scopes as `{scope, description, approved}` triples, the CSRF token and the form action. Approving records an `OAuth2AuthorizationConsent` (merged with whatever was approved before), so the next authorization request for the same scopes skips the page; denying is `access_denied` back at the client.

What the token endpoint then hands out is a short-lived access token — a JWT with RFC 9068's claims by default, or 32 opaque random bytes when `access_token.format` is `reference` — and, for a confidential client with the grant, a refresh token. The refresh token is where the interesting rule lives, and it is easier to read as a test than as a paragraph:

<!-- source: packages/security-oauth2-server/tests/Web/RefreshTokenFlowTest.php -->
```php
it('rotates the refresh token, invalidates the previous access token, narrows the scope on request, and detects reuse by revoking the family', function () {
    /** @var RefreshCapstoneTestCase $this */
    $this->signIn();
    $oauth2 = $this->oauth2();
    $first = $oauth2->tokens('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid profile orders:read', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET);
    /** @var string $firstRefresh */
    $firstRefresh = $first['refresh_token'];
    /** @var string $firstAccess */
    $firstAccess = $first['access_token'];

    $refreshed = $oauth2->refresh('web-app', $firstRefresh, OAuth2ServerCapstoneTestCase::WEB_APP_SECRET, 'openid orders:read');
    $refreshed->assertOk()->assertJson(['token_type' => 'Bearer', 'scope' => 'openid orders:read']);
    /** @var array<string,mixed> $second */
    $second = $refreshed->json();
    /** @var string $secondRefresh */
    $secondRefresh = $second['refresh_token'];
    expect($secondRefresh)->not->toBe($firstRefresh)
        ->and($second['access_token'])->not->toBe($firstAccess)
        ->and($second)->toHaveKey('id_token');

    /** @var OAuth2AuthorizationService $service */
    $service = $this->app()->make(OAuth2AuthorizationService::class);
    $authorization = $service->findByToken($secondRefresh, OAuth2TokenType::RefreshToken);
    expect($authorization?->authorizedScopes)->toBe(['openid', 'profile', 'orders:read'])
        ->and($authorization?->refreshTokenFamily())->toHaveCount(1)
        // The previous access token was replaced on the record: it no longer resolves, so introspection says inactive.
        ->and($service->findByToken($firstAccess, OAuth2TokenType::AccessToken))->toBeNull();

    // The new token widens back to the original grant when asked for nothing narrower.
    $oauth2->refresh('web-app', $secondRefresh, OAuth2ServerCapstoneTestCase::WEB_APP_SECRET)->assertOk()->assertJson(['scope' => 'openid profile orders:read']);

    // Presenting the FIRST refresh token again is a replay: the whole authorization is gone.
    $oauth2->refresh('web-app', $firstRefresh, OAuth2ServerCapstoneTestCase::WEB_APP_SECRET)->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    expect($service->findById($authorization->id ?? ''))->toBeNull();
    $oauth2->refresh('web-app', $secondRefresh, OAuth2ServerCapstoneTestCase::WEB_APP_SECRET)->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
});
```

Four rules in one test. Every refresh **rotates** the token and invalidates the access token it replaces. A refresh may ask for a *narrower* scope, and the authorization still remembers the original grant, so the next refresh can widen back to it. Presenting a superseded refresh token is treated as theft rather than as a mistake: the whole authorization is revoked — which is why the last line, using the *good* token, also fails. And a client that genuinely cannot rotate can set `token_settings.reuse_refresh_tokens`, in which case it gets its own token back, because a server that stores only hashes has no value to echo.

Two more things about storage, because they decide whether the defaults survive contact with a real deployment. Tokens are stored **by SHA-256 hash**, never by value, so a dump of `oauth2_authorizations` is not a set of credentials. And `clients.driver` and `authorizations.driver` both default to `memory`, which means *this process* — fine for a test and for a single dev server, and wrong the moment there are two workers, because a code issued by one must be redeemable by the other. `eloquent` is the answer there, and `php artisan vendor:publish --tag=firefly-oauth2-server-migrations` publishes the tables.

---

## Keys, and rotating them

Everything above hangs from one private key. The framework generates it:

```bash
php artisan firefly:oauth2:keys                              # RSA 2048 → storage/oauth2/private.pem, chmod 0600
php artisan firefly:oauth2:keys --algorithm=ES256            # an EC P-256 key instead
php artisan firefly:oauth2:keys --out=/run/secrets/oauth2.pem --force
php artisan firefly:oauth2:keys --print                      # to stdout, for a secret manager
```

The command prints the two lines you need next — the environment variable to set, and the `kid` the JWKS will publish — and its options are exactly these:

<!-- source: packages/cli/src/Command/OAuth2KeysCommand.php -->
```php
/**
 * Generates the private key the OAuth2 authorization server signs with: RSA 2048 for RS256 (the default) or
 * P-256 for ES256, written to storage/oauth2/private.pem with owner-only permissions (or printed with --print),
 * and the two lines a developer needs next — the env variable to set and the kid the JWKS will publish. A file
 * that already exists is never overwritten without --force: a key replaced by accident invalidates every token
 * in flight. Rotation is documented in docs/modules/security-oauth2-server.md (move the old key to
 * jwt.previous_keys, generate a new one here).
 */
final class OAuth2KeysCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:oauth2:keys
        {--algorithm=RS256 : RS256 (an RSA key) or ES256 (an EC P-256 key)}
        {--bits=2048 : the RSA modulus size (ignored for ES256)}
        {--out= : where to write the PEM (default: storage/oauth2/private.pem)}
        {--force : overwrite an existing file}
        {--print : print the PEM to the console instead of writing a file}';

    /** @var string */
    protected $description = 'Generate a private signing key for the OAuth2 authorization server (firefly.security.oauth2.server.jwt.signing_key).';
```

`--force` exists so that overwriting is deliberate: a key replaced by accident invalidates every token in flight, and no error message anywhere would say why. `jwt.key_id` defaults to the RFC 7638 thumbprint of the public key, which means every node running the same key publishes the same `kid` with no coordination at all.

**Rotating** is two moves and no downtime. Generate a new key; move the old one into `jwt.previous_keys` as `[{key, key_id}]` — its public half is enough, since it now only needs to *verify* — and point `jwt.signing_key` at the new one. Tokens signed with the old key keep verifying until they expire, the JWKS publishes both, and a resource server reading the local key source sees both. Publishing two keys under one `kid` is refused at boot, because a verifier keeps one key per id and the pair would fail every fresh token at first use — so an explicit `key_id` has to change with the key.

!!! tip "Be your own resource server"
    The commonest deployment of this package is an application that issues tokens *and* accepts them. Turn on `firefly.security.oauth2.resource_server` from Chapter 10 with `jwks_source: local`, and the filter reads the key set through the `JwksDocumentSource` bean the server already binds — no HTTP self-fetch, which on a single-process dev server would be a deadlock. Say `local` explicitly rather than leaving `auto`: `auto` recognises only `/.well-known/jwks.json` as "this application's own", and the server publishes at `/oauth2/jwks`.

---

## Seeing it work

Both halves ship with a way to exercise them that involves no external provider and no network.

For the client half it is `Firefly\Testing\Security\OAuth2\FakeAuthorizationServer` — an entire OpenID Connect provider in one class. `install()` mounts its **front channel** as real routes on the application under test (`/authorize`, optionally rendering a consent page; `/end-session`) and fakes its **back channel** with `Http::fake()` for exactly four URLs: the discovery document, `/token`, `/jwks` and `/userinfo`. Anything else stays unfaked, so a stray outbound request is still a stray outbound request. Tokens are real RS256 JWTs signed with a key pair generated once per process; codes are single-use, bound to their `redirect_uri` and PKCE-checked; the clock is `Date::now()`, so `$this->travel()` expires tokens. Every hop is recorded and every failure is one method away — `overrideIdTokenClaims()`, `signWithUnknownKey()`, `refuseToken()`, `refuseAuthorization()`, `withoutUserInfo()`, `takeDiscoveryDown()`. Because the browser plugin serves the application in-process, the same fake drives a real Chromium:

<!-- source: tests/Browser/OAuth2LoginTest.php -->
```php
it('signs in through the provider from the login page — every hop, the consent page, the page the person was refused at — and signs out at the provider too', function (): void {
    /** @var OAuth2LoginBrowserTestCase $this */
    $this->idp->requireConsent();
    $origin = OAuth2LoginBrowserTestCase::origin();

    // 1. The entry point sent the browser to the framework's login page: the provider is listed, there is no password form.
    $page = visit('/browser-fixture/account');
    $page->assertPathIs('/login')
        ->assertSee('Sign in with Fake IdP')
        ->assertDontSee('Username')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'oauth2-login-page');

    // 2. The start URL redirected to the provider with state, nonce and a PKCE challenge; the fake stopped at its consent page.
    $page->click('Sign in with Fake IdP')
        ->assertPathIs('/fake-idp/authorize')
        ->assertQueryStringHas('response_type', 'code')
        ->assertQueryStringHas('client_id', FakeAuthorizationServer::CLIENT_ID)
        ->assertQueryStringHas('redirect_uri', $origin.'/login/oauth2/code/fake')
        ->assertQueryStringHas('scope', 'openid profile email')
        ->assertQueryStringHas('state')
        ->assertQueryStringHas('nonce')
        ->assertQueryStringHas('code_challenge')
        ->assertQueryStringHas('code_challenge_method', 'S256')
        ->assertSee('asks to sign you in as ada')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'oauth2-consent');

    // 3. Approving sent the code back; the callback exchanged it and landed on the page the person was refused at.
    $page->press('Allow')
        ->assertPathIs('/browser-fixture/account')
        ->assertQueryStringMissing('code')
        ->assertQueryStringMissing('state')
        ->assertSee('Signed in as ada')
        ->assertSee('ada@example.com')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'oauth2-signed-in');

    $token = $this->idp->lastTokenRequest();
    expect($this->idp->lastAuthorizationRequest()['redirect_uri'] ?? null)->toBe($origin.'/login/oauth2/code/fake')
        ->and($token['grant_type'])->toBe('authorization_code')
        ->and($token['authorization'])->toStartWith('Basic ')
        ->and($token['form']['redirect_uri'] ?? null)->toBe($origin.'/login/oauth2/code/fake')
        ->and($token['form']['code_verifier'] ?? '')->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($this->idp->userInfoRequests)->toBe([$this->idp->issuedAccessTokens[0]])
        ->and($this->idp->jwksRequests)->toBe(1)
        ->and($this->idp->discoveryRequests)->toBe(1);
```

For the server half it is `Firefly\Testing\Security\OAuth2\OAuth2ServerTestClient`, which drives your *own* server through Laravel's test client: `authorize()` with a fresh PKCE pair and state, `approveConsent()`, `obtainCode()`, `exchangeCode()`, `clientCredentials()`, `refresh()`, `introspect()`, `revoke()` and `userInfo()`. It reads the endpoint paths off the `AuthorizationServerSettings` bean, so renaming one needs no change in a test. The one thing it insists on is that you **sign the person in through the login page and carry the session cookie**: `actingAsPrincipal()` is deliberately not enough at the authorization endpoint, because the endpoint demands the principal the `SecurityContextRepository` holds between requests, so no test double can be a resource owner without a session behind it. The machine helpers need no sign-in at all.

And the whole server round trip is driven in Chromium too, against a relying-party fixture served by the same application:

<!-- source: tests/Browser/OAuth2AuthorizationCodeTest.php -->
```php
it('drives the whole authorization-code flow in Chromium: login page, consent, the redirect back with the code, then the exchange and a protected API call in-process', function (): void {
    /** @var OAuth2ServerBrowserTestCase $this */
    // …
    // Hop 1: the authorization request from an anonymous browser lands on the framework's login page.
    $login = visit(authorizeUrl($redirectUri, $proof, $state, $nonce));
    $login->assertPathIs('/login')
        ->assertSee('Sign in')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'oauth2-login');
    // …
    // Hop 2: signing in returns to the authorization request, which now shows the consent page.
    $consent = $login->fill('username', 'ada')->fill('password', 'secret')->press(SIGN_IN_BUTTON);
    $consent->assertPathIs('/oauth2/authorize')
        ->assertQueryStringHas('client_id', OAuth2ServerBrowserTestCase::CLIENT_ID)
        ->assertQueryStringHas('state', $state)
        ->assertQueryStringHas('nonce', $nonce)
        ->assertSee('Allow access?')
        ->assertSee('The browser relying party')
        ->assertSee('openid')
        ->assertSee('profile')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'oauth2-consent');
    // …
    // In-process, as the relying party's back channel: the exchange with the verifier and the client secret.
    $tokens = $this->withBasicAuth(OAuth2ServerBrowserTestCase::CLIENT_ID, OAuth2ServerBrowserTestCase::CLIENT_SECRET)
        ->post('/oauth2/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri, 'code_verifier' => $proof->verifier], ['Accept' => 'application/json']);
    $this->flushHeaders();
    $tokens->assertOk()->assertJson(['token_type' => 'Bearer', 'scope' => 'openid profile']);
    /** @var array<string,mixed> $body */
    $body = $tokens->json();
    expect($body)->toHaveKeys(['access_token', 'refresh_token', 'id_token']);
    /** @var string $accessToken */
    $accessToken = $body['access_token'];
    /** @var string $idToken */
    $idToken = $body['id_token'];

    $id = $this->app()->make(JwtGenerator::class)->decode($idToken);
    expect($id)->toMatchArray(['sub' => 'ada', 'aud' => [OAuth2ServerBrowserTestCase::CLIENT_ID], 'nonce' => $nonce]);
    // …
    $this->forgetSession();
    $this->withHeader('Authorization', 'Bearer '.$accessToken)->getJson('/api/browser-fixture/profile')
        ->assertOk()
        ->assertJson(['sub' => 'ada', 'authorities' => ['SCOPE_openid', 'SCOPE_profile']]);
    $this->flushHeaders();
    $this->getJson('/api/browser-fixture/profile')->assertStatus(401);
```

The last four lines are the part worth keeping: a token this application minted, sent by a process holding no session, authenticates on a route this application protects — through `OAuth2ResourceServerFilter` (`-85`) and nothing else — and the same route without the token is a `401`. That is the whole loop closed inside one test.

In production the same question is answered by the actuator. `/actuator/oauth2clients` lists every registered client with its methods, grants, scopes, redirect URIs and settings, plus how many authorizations are alive for it — and never a secret, because the payload is assembled field by field and `clientSecret` is named nowhere in it. It is unexposed by default like everything past `health` and `info`, and the admin dashboard's **OAuth2 clients** page (`/firefly/oauth2`, in the Wiring group) renders it in-process. Two details on that page repay a moment: PKCE is reported twice — as the client registered it and as the endpoints actually enforce it, which differ whenever the server-wide `require_pkce` is on — and the authorization counts carry a `processLocal` flag, which is `true` on the `memory` driver and is the difference between "this client has no live authorizations" and "this worker has issued none".

!!! laravel "Laravel parity"
    The client half is the ground Socialite covers, and covers differently: Socialite gives you a driver per provider and expects you to write the controller, call `Auth::login()` and decide what to do with the payload, and it reads userinfo rather than validating an id token. Here the flow is configuration in Spring Boot's shape, the id token is checked signature-and-claim by claim, PKCE and a single-use constant-time `state` are not optional, and the result is a principal the rest of the framework already understands. The server half is Passport's ground, and the differences are the protocol surface: Passport has no discovery document, no introspection endpoint and no OIDC, while this package ships RFC 8414, RFC 7662, RFC 7009, OIDC Core's userinfo, RP-Initiated Logout and RFC 7591 — over the same session, login page, entry point and `PasswordEncoder` Chapter 10 already gave you, rather than over a parallel stack of its own.

---

## What you learned {.recap}

| Concept | What it does |
|---|---|
| `firefly/security-oauth2-client` | Your application as a **relying party**: registrations, discovery, authorization-code login, an `OidcUser` principal, RP-initiated logout, outbound tokens |
| `firefly/security-oauth2-server` | Your application as an **authorization server**: clients, consent, `/oauth2/token`, introspection, revocation, userinfo, JWKS, discovery |
| `OAuth2AuthorizationRequestRedirectFilter` (`-89`) | Answers `GET /oauth2/authorization/{id}`: builds the request, keeps it in the session, redirects to the provider |
| `OAuth2LoginAuthenticationFilter` (`-88`) | Answers `GET /login/oauth2/code/{id}`: pulls the saved request (single use), checks `state`, exchanges the code, signs the person in |
| `OAuth2AuthorizationServerFilter` (`-82`) | The server's one filter — after the session and bearer filters, before CSRF and the URL rules, so no rule and no `csrf.except` entry is needed |
| Registrations and providers | `registration.{id}` is one relationship, `provider.{id}` is one issuer; five presets, and `issuer_uri` discovers the rest |
| `OAuth2AuthorizationRequestResolver` | A fresh 43-character `state` every time, a `nonce` for every `openid` request, and an S256 PKCE verifier — one generator, 256 bits each |
| `OidcUser` | Merged claims (userinfo over id token), `SCOPE_*` per granted scope, and a **blanked** raw id token in the session copy |
| `GrantedAuthoritiesMapper` | The `#[Bean]` that turns a `groups`/`roles` claim into `ROLE_*`; it adds to the granted list rather than replacing it |
| `OidcClientInitiatedLogoutSuccessHandler` | `logout.oidc_initiated`: the provider's `end_session_endpoint` with `id_token_hint`, read before the session is invalidated |
| `OAuth2AuthorizedClientManager` | `client_credentials` from the cache, `authorization_code` from the session or the cache, refreshed within `clock_skew` of expiry |
| `Http::oauth2Client('{id}')` | An ordinary `PendingRequest` with that registration's bearer on it: fakeable, traced, retryable |
| PKCE, `state`, `nonce` | S256 by default and mandatory for a public client; `state` single-use, session-bound and compared in constant time; `nonce` always sent and always checked |
| Refresh-token rotation | Every refresh rotates and invalidates the access token it replaces; presenting a superseded token revokes the whole authorization |
| `firefly:oauth2:keys` | RSA 2048 or P-256, `0600`, never overwritten without `--force`; `kid` defaults to the RFC 7638 thumbprint |
| `jwt.previous_keys` | Rotation with no downtime: the old key still verifies and still appears in the JWKS; two keys under one `kid` is a boot refusal |
| `memory` vs `eloquent` drivers | `memory` is one process — a test or a single dev server; more than one worker needs `eloquent`, or a code one process issued is unredeemable by another |
| `FakeAuthorizationServer` | A whole OIDC provider in one class: real routes for the front channel, `Http::fake()` for exactly four back-channel URLs |
| `OAuth2ServerTestClient` | Drives your own server end to end, over a real sign-in — because the authorization endpoint demands a session-held principal |
| `/actuator/oauth2clients` | Every client, its grants and its live authorization count, never a secret; `processLocal` says whether that count describes the deployment |

---

## Try it yourself {.exercises}

1. **Sign in through a real provider, twice.** Add a `google` registration with nothing but `client_id` and `client_secret` and confirm the login page grows a button; then add a second registration on `keycloak` with only `provider.corp.issuer_uri` set, and watch discovery fill in four endpoints you never typed. Take the issuer offline and reload `/login`: one button disappears with a warning in the log, the other still works.
2. **Watch the state get spent.** Start a login, copy the whole callback URL out of the address bar before the page finishes, and replay it. The second attempt is `authorization_request_not_found` — not a state mismatch — because the saved request is pulled from the session before anything is compared. Then start again, tamper with one character of `state`, and see `invalid_state_parameter` instead.
3. **Turn a claim into a role.** Bind a `GrantedAuthoritiesMapper` that maps a `groups` claim to `ROLE_*`, put `#[PreAuthorize("hasRole('ENGINEERING')")]` on an action, and confirm the same person who got a `403` before the bean existed gets a `200` after it. Then confirm `hasScope('email')` was already true without any mapper at all, and say why.
4. **Be both halves at once.** In one application, turn on `oauth2.server` with a `web-app` client, and `oauth2.resource_server` with `jwks_source: local`. Mint a token with `OAuth2ServerTestClient`, call a protected API with it, then rotate the signing key into `jwt.previous_keys` and call the API again with the *old* token: it still verifies. Now remove `previous_keys` and watch the same token stop.
5. **Break the pair on purpose.** Turn `firefly.security.http_basic.enabled` on beside the authorization server and read the boot refusal. Then reason it out from Chapter 10's filter table alone, without rerunning the boot: which filter answers first, what it answers, and why no OAuth2 error would ever have been logged.
