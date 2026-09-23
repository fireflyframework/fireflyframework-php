<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Firefly (LaraFly) configuration reference
|--------------------------------------------------------------------------
|
| Every `firefly.*` key the framework actually reads is listed here, grouped by the package that reads
| it, with the real default the code falls back to when the key is absent. Keys that a typical
| application never touches are left COMMENTED OUT with their default shown, so the file stays short
| enough to read while remaining a complete reference: an absent key and a key set to the value printed
| next to it behave identically.
|
| Reading conventions used throughout:
|
|   * "default X" is the literal fallback in the call site, not an aspiration. Where the framework uses
|     `Config::has()` rather than a default, the key is documented as "unset" and MUST stay commented
|     out — writing it changes behaviour even when you write what looks like the default.
|   * Flags gated by #[ConditionalOnProperty] are compared as STRINGS after stringification: only the
|     boolean `true` and the string `'true'` match `havingValue: 'true'` — the integer `1` stringifies to
|     `'1'` and does NOT. Use boolean literals (or env() values, which Laravel already casts).
|   * Duration-shaped values accept either a bare number of seconds or a `Firefly\Resilience\Duration`
|     string: `250ms`, `30s`, `5m`, `1h`.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Component scanning — firefly/autoconfigure, firefly/context
    |--------------------------------------------------------------------------
    |
    | The PSR-4 roots Firefly scans for stereotypes (#[Component]/#[Service]/#[RestController]/
    | #[Controller]/#[Repository]/#[Configuration]) and for every attribute-driven manifest. This is the
    | ONE key an application must get right: `firefly:cache` compiles these roots, and an uncached boot
    | scans them in-process. Leave it empty and the app boots with no routes, handlers, listeners,
    | scheduled tasks, constraints or method-security rules at all.
    |
    */

    'scan' => [
        'paths' => [
            'App\\' => app_path(),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled artifacts — firefly/cli, firefly/context
    |--------------------------------------------------------------------------
    |
    | Where `php artisan firefly:cache` writes the compiled manifests and the #[Transactional] proxies,
    | and where the boot path looks for them. Boot resolves each manifest in this order: the compiled
    | artifact if it exists, else an in-process scan of `scan.paths`, else an empty manifest. So a
    | missing cache directory costs reflection at boot, never correctness.
    |
    | `path` is the directory (read by Firefly\Context\Scan\AppScan and firefly/cli); the two
    | `*_manifest` keys are the two files FireflyAutoConfigureServiceProvider loads directly.
    |
    */

    'cache' => [
        'path' => base_path('bootstrap/cache/firefly'),
        'component_manifest' => base_path('bootstrap/cache/firefly/component.php'),
        'context_manifest' => base_path('bootstrap/cache/firefly/context.php'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Profiles — firefly/config
    |--------------------------------------------------------------------------
    |
    | Active profiles for #[Profile] and #[ConditionalOnProfile]. ProfileResolver reads, in order: the
    | FIREFLY_PROFILES_ACTIVE environment variable, then this key, then `app.env`, then the implicit
    | `default` profile — so setting nothing here means "the profile is APP_ENV". A list is accepted and
    | joined with commas.
    |
    | Default: unset (falls back to APP_ENV, then 'default').
    |
    */

    // 'profiles' => [
    //     'active' => ['prod', 'eu'],
    // ],

    /*
    |--------------------------------------------------------------------------
    | Security — firefly/security
    |--------------------------------------------------------------------------
    |
    | OFF by default, and opt-in surface by surface. `enabled` is the master flag: it gates the principal
    | model, the role hierarchy, the user store, the authentication manager, the CQRS authorizers, the
    | programmatic AuthorizationChecker, the event publisher, method security on beans, and every
    | interactive mechanism (session, form_login, http_basic, logout, remember_me, the entry point, and
    | OAuth2 login — oauth2.client.login.enabled, in the block below).
    |
    | Each surface below has its own flag. `jwt`, `oauth2.resource_server`, `csrf` and `headers` are
    | independent of the master flag and can be turned on by themselves; everything else ALSO requires it,
    | because its filters and beans consume master-gated beans. Note that authenticating (jwt/oauth2)
    | without `http` or method security enforces no authorization at all — it only establishes a principal.
    |
    */

    'security' => [

        // Master flag. Default: false.
        'enabled' => env('FIREFLY_SECURITY_ENABLED', false),

        /*
         | Method security (#[PreAuthorize], #[PostAuthorize], #[Secured], #[RolesAllowed], #[PreFilter], #[PostFilter]).
         |
         | Enforcement treats "no rule recorded for this method" as ALLOW, so an EMPTY method-security
         | manifest silently disables every annotation in the application — it fails OPEN. Boot resolves
         | the manifest from the compiled artifact, else an in-process scan, else empty; `strict` refuses
         | to boot when neither a compiled artifact nor a scan produced one, which is the only defence
         | against a build that ships without the compile step. Turn it on in production images.
         |
         | Default: false.
        */
        'method' => [
            /*
             | Method security on every stereotyped bean. With the master flag on, #[PreAuthorize],
             | #[PostAuthorize], #[Secured], #[RolesAllowed], #[PreFilter] and #[PostFilter] are enforced on
             | any #[Service]/#[Component]/#[Repository] method through the same proxy #[Transactional] uses
             | (security runs before the transaction), on both boot paths: `firefly:cache` compiles the plan
             | into proxy-plan.php plus a proxy per planned class, and the uncached boot scans the same advice
             | sources. A cache from before proxy-plan.php existed — security-methods.php with no plan beside
             | it — is refused at boot; run `php artisan firefly:cache` again. Turning this off keeps the
             | controller dispatcher and the CQRS bus enforcing their rules, makes the proxy link a
             | pass-through, and stands the stale-cache refusal down with it. Read live.
             |
             | Default: true.
            */
            'enabled' => env('FIREFLY_SECURITY_METHOD_ENABLED', true),

            'strict' => env('FIREFLY_SECURITY_METHOD_STRICT', false),
        ],

        /*
         | Session-persisted SecurityContext. Firefly filters are GLOBAL middleware and Laravel starts the
         | session in the `web` route group, later — so when this is on the framework pushes EncryptCookies,
         | AddQueuedCookiesToResponse and StartSession onto the global stack ahead of the security filters
         | (and excludes them on every route as it is matched — cached or not — where a second EncryptCookies
         | pass would null every cookie). It is switched on implicitly by form_login, remember_me and
         | http_basic.session below, and by oauth2.client.login.enabled (OAuth2 login signs into the same
         | session). What the session carries is a context saved through the
         | SecurityContextRepository: the interactive mechanisms (form login, http_basic.session, remember-me)
         | save at the moment of success, and so can your own code (a controller that calls
         | SessionSecurityContextRepository::save()). Nothing stored carries a credential: a principal that
         | implements CredentialsContainer (the shipped User) is written without its encoded password. A bearer
         | principal (jwt, oauth2.resource_server) is re-verified on every request by design and NEVER stored.
         | A context a controller merely sets on SecurityContextHolder is saved on the way out only when no
         | filter cleared the holder first: while `jwt` is on it never is (that filter clears the holder
         | unconditionally on exit); under `oauth2.resource_server`, which clears only a bearer context it
         | established itself, it is saved whenever the request presented no bearer. Saving through the
         | repository behaves the same whichever is on. A session driver is required.
         |
         | `fixation_protection` regenerates the session id on every interactive sign-in.
         |
         | Defaults: enabled false, fixation_protection true.
        */
        'session' => [
            'enabled' => env('FIREFLY_SECURITY_SESSION_ENABLED', false),
            // 'fixation_protection' => true,
        ],

        /*
         | Form login: the framework's own server-rendered sign-in page (or your Blade `view`), a POST to
         | `login_processing_url` verified against the session CSRF token, the AuthenticationManager, and a
         | redirect to the page the person was refused at (or `default_success_url`). Failure redirects to
         | `failure_url` and publishes an AuthenticationFailure* event carrying the username and the source
         | ip — never the password. Turning this on turns `session` and `logout` on with it.
         |
         | `view` receives `$login` (a LoginPageModel: action, usernameParameter, passwordParameter,
         | csrfToken, error, loggedOut, rememberMeParameter, title). A `view` that does not exist or throws
         | while rendering falls back to the framework page AND IS LOGGED at warning naming the view: a typo
         | here would otherwise replace your page with the framework's, with a 200 and not a word anywhere.
         |
         | The framework's page is mounted at `login_page` ONLY when no GET route of yours already answers
         | that path: a #[GetMapping('/login')] or a routes-file route there (with or without a domain) is
         | left in place and the framework page is not mounted, as Spring does for a custom login page. The
         | redirect, the URL-rule exemption and the POST handling work the same for your page — its form
         | only has to post the session token as `_token` to `login_processing_url`. The framework page's
         | form action is root-relative (base path + the path of `login_processing_url`), so it posts to
         | the origin the browser fetched the page from, TLS-terminating proxy or not.
         |
         | Defaults: enabled false, login_page '/login', login_processing_url '/login', username_parameter
         | 'username', password_parameter 'password', default_success_url '/',
         | always_use_default_success_url false, failure_url '/login?error', view '' (the framework page).
        */
        'form_login' => [
            'enabled' => env('FIREFLY_SECURITY_FORM_LOGIN_ENABLED', false),
            // 'login_page' => '/login',
            // 'login_processing_url' => '/login',
            // 'username_parameter' => 'username',
            // 'password_parameter' => 'password',
            // 'default_success_url' => '/',
            // 'always_use_default_success_url' => false,
            // 'failure_url' => '/login?error',
            // 'view' => 'auth.login',
        ],

        /*
         | HTTP Basic: the `Authorization: Basic` header is authenticated on every request (stateless) unless
         | `session` is true, in which case a browser's first success is stored in the session. The entry
         | point answers a 401 with `WWW-Authenticate: Basic realm="…"` to non-browser clients.
         |
         | Defaults: enabled false, realm 'LaraFly', session false.
        */
        'http_basic' => [
            'enabled' => env('FIREFLY_SECURITY_HTTP_BASIC_ENABLED', false),
            // 'realm' => 'LaraFly',
            // 'session' => false,
        ],

        /*
         | Logout: a POST to `logout_url` (verified against the session CSRF token — a GET that signs someone
         | out is a link an attacker can plant) invalidates the session, expires the remember-me cookie and any
         | cookie named in `delete_cookies`, publishes LogoutSuccessEvent and redirects to `logout_success_url`.
         |
         | Defaults: enabled follows form_login.enabled or oauth2.client.login.enabled, logout_url '/logout',
         | logout_success_url '/login?logout', invalidate_session true, delete_cookies [],
         | clear_authentication true.
        */
        'logout' => [
            // 'enabled' => true,
            // 'logout_url' => '/logout',
            // 'logout_success_url' => '/login?logout',
            // 'invalidate_session' => true,
            // 'delete_cookies' => [],
            // 'clear_authentication' => true,
        ],

        /*
         | Remember-me: a signed cookie (`username:expiry:HMAC-SHA256(username:expiry:password-hash:key)`,
         | Spring's TokenBasedRememberMeServices) set when the login form's `parameter` is checked (or always,
         | with `always_remember`), that re-authenticates a request whose session holds no principal. A
         | password change invalidates it, because the hash is part of the signature. `key` is required once
         | enabled and is held to the JWT secret rule: a placeholder or fewer than 32 bytes REFUSES TO BOOT.
         |
         | Defaults: enabled false, parameter 'remember-me', cookie_name 'remember-me',
         | token_validity_seconds 1209600 (14 days), always_remember false.
        */
        'remember_me' => [
            'enabled' => env('FIREFLY_SECURITY_REMEMBER_ME_ENABLED', false),
            'key' => env('FIREFLY_SECURITY_REMEMBER_ME_KEY', ''),
            // 'parameter' => 'remember-me',
            // 'cookie_name' => 'remember-me',
            // 'token_validity_seconds' => 1209600,
            // 'always_remember' => false,
        ],

        /*
         | The user store. `driver` is `memory` (default: this map, keyed by username — the shape below) or
         | `eloquent` (any Eloquent model; the reserved keys configure it and are never read as usernames).
         |
         | Memory: `password` is the ENCODED string — typically `{id}`-prefixed for the
         | DelegatingPasswordEncoder, e.g. `{bcrypt}$2y$...`. `authorities` defaults to [], `enabled` to
         | true, `locked` to false. With no users every login fails with a 401.
         |
         | Eloquent: the expected schema is `email` (username_column), `password` (password_column, the
         | encoded string as above), optional `enabled`/`locked` booleans (enabled_column/locked_column —
         | empty means "no such flag"), and optional `authorities` — a column holding a JSON list (or an
         | `array` cast) or `relation.attribute` to pluck from a relation (`roles.name`); empty means "the
         | model carries no authorities" and every account authenticates with none. A model class that
         | does not exist or is not an Eloquent model REFUSES TO BOOT. A configured column (or relation)
         | the loaded row does not carry is refused on the lookup, never read as NULL — a locked_column
         | typo would otherwise unlock every account silently. Laravel's stock `users` table (id, name,
         | email, password) therefore needs `'authorities' => ''`, or a column/relation added to it: the
         | default names an `authorities` column that table does not have.
         |
         | Defaults: driver 'memory', model '', username_column 'email', password_column 'password',
         | enabled_column '', locked_column '', authorities 'authorities'.
        */
        'users' => [
            // 'driver' => 'eloquent',
            // 'model' => App\Models\User::class,
            // 'username_column' => 'email',
            // 'password_column' => 'password',
            // 'enabled_column' => '',
            // 'locked_column' => '',
            // 'authorities' => '', // the stock users table has no authorities column; name one (or `roles.name`) once it does

            // 'alice' => [
            //     'password' => '{bcrypt}$2y$12$...',
            //     'authorities' => ['ROLE_ADMIN'],
            //     'enabled' => true,
            //     'locked' => false,
            // ],
        ],

        /*
         | Role implication rules, one per line, in the form `ROLE_A > ROLE_B`. A principal holding
         | ROLE_A is then treated as holding ROLE_B everywhere authority checks run.
         |
         | Default: [] (no implications; roles are compared literally).
        */
        'role_hierarchy' => [
            // 'ROLE_ADMIN > ROLE_USER',
        ],

        /*
         | URL authorization. REQUIRES the master flag above as well as this one. DENY BY DEFAULT: with
         | both on, a request matching NO rule is refused (401 when anonymous, 403 when authenticated).
         | Rules are first-match-wins over Str::is() patterns.
         |
         | `access` is a FIXED vocabulary, not free expression text — HttpSecurity::fromConfig() maps it:
         |
         |     permitAll | denyAll | authenticated | hasRole:<ROLE> | hasAuthority:<AUTHORITY>
         |
         | Anything it does not recognise compiles to denyAll(): the spec is fail-closed, so a typo
         | locks the path down rather than opening it. Write `hasRole:ADMIN`, never `hasRole('ADMIN')`.
         |
         | Defaults: enabled false, rules [].
        */
        'http' => [
            'enabled' => env('FIREFLY_SECURITY_HTTP_ENABLED', false),
            /*
             | What an ANONYMOUS request to a protected URL gets. `auto`: a browser (Accept names text/html,
             | not an XMLHttpRequest, not under firefly.web.error-page.json-paths) is redirected to the login
             | page with the request saved when form_login or oauth2.client.login is on; otherwise a 401 with
             | `WWW-Authenticate: Basic` when http_basic is on; otherwise the 401 problem document / HTML page.
             | `login`, `challenge` and `problem` force one of the three (`login` without form_login or OAuth2
             | login is refused at boot). An AUTHENTICATED but under-privileged request is always the 403.
             |
             | The browser test does NOT depend on firefly.web.error-page.enabled: switching the framework's
             | error page off changes how a 401 is drawn, not whether a person is sent to sign in.
             |
             | Default: 'auto'.
            */
            'entry_point' => env('FIREFLY_SECURITY_ENTRY_POINT', 'auto'),
            'rules' => [
                // ['pattern' => 'actuator/health', 'access' => 'permitAll'],
                // ['pattern' => 'actuator/*',      'access' => 'hasRole:ACTUATOR'],
                // ['pattern' => '*',               'access' => 'authenticated'],
            ],
        ],

        /*
         | Local JWT bearer authentication. Mutually exclusive with the OAuth2 resource server below —
         | enabling both throws at boot, because the local filter (order -90) would reject tokens before
         | the resource-server filter (order -85) could validate them.
         |
         | `secret` is required once `enabled` is true, and JwtService REFUSES TO BOOT on a placeholder
         | or a secret shorter than its minimum byte length.
         |
         | Defaults: enabled false, algorithm 'HS256', leeway 0, authorities_claim 'authorities'.
        */
        'jwt' => [
            'enabled' => env('FIREFLY_JWT_ENABLED', false),
            'secret' => env('FIREFLY_JWT_SECRET', ''),
            'algorithm' => 'HS256',
            'leeway' => 0,
            'authorities_claim' => 'authorities',
        ],

        /*
         | OAuth2. resource_server validates bearer tokens against a remote JWKS (`jwks_uri` is required
         | once `enabled` is true). An empty `issuer`/`audience` skips that claim check.
         |
         | Defaults: enabled false, issuer '', audience '', authorities_claim 'roles', cache_ttl 3600.
        */
        'oauth2' => [
            'resource_server' => [
                'enabled' => env('FIREFLY_OAUTH2_ENABLED', false),
                'jwks_uri' => env('FIREFLY_OAUTH2_JWKS_URI', ''),
                'issuer' => env('FIREFLY_OAUTH2_ISSUER', ''),
                'audience' => env('FIREFLY_OAUTH2_AUDIENCE', ''),
                'authorities_claim' => 'roles',
                'cache_ttl' => 3600,

                /*
                 | Where the signing keys come from: `auto` (default), `local` or `remote`.
                 |
                 | `remote` fetches `jwks_uri` over HTTP, bounded by the two timeouts below, and answers a
                 | 503 JWKS_UNAVAILABLE (never a 401) when the issuer cannot be reached. `local` answers from
                 | a Firefly\Security\OAuth2\JwksDocumentSource bean YOU bind — the key set this
                 | application signs its own tokens with — and refuses to boot without one. `auto` picks
                 | `local` when such a bean is bound AND `jwks_uri` names this very application (its
                 | `/.well-known/jwks.json` at `app.url`, or at a loopback address on `firefly.server.port`),
                 | and `remote` otherwise. A server must never fetch its own keys from itself over HTTP: on a
                 | single-process dev server that nested request is a deadlock.
                */
                'jwks_source' => 'auto',

                // Seconds to connect to, and to read from, the JWKS URI. Laravel's default is thirty — the
                // same as PHP's execution limit, which turns a slow issuer into a fatal error. Default: 5.
                'jwks_connect_timeout' => 5,
                'jwks_timeout' => 5,
            ],

            /*
             | OAuth2 client and OpenID Connect login — firefly/security-oauth2-client (Spring Security's
             | oauth2Login() + oauth2Client()). `enabled` is the package master: registrations, discovery, the
             | token client, the OAuth2AuthorizedClientManager and the Http::oauth2Client() macro. It does NOT
             | need firefly.security.enabled (a job calling an API with client credentials has no inbound
             | security); `login.enabled` DOES, and is refused at boot without it.
             |
             | Registrations and providers are spelled exactly as Spring Boot spells them. A registration whose
             | `provider` (or, when absent, whose own id) names a preset — google, github, okta, keycloak,
             | microsoft (alias entra) — inherits the preset's endpoints, scopes and client_name, and any
             | `provider.{id}` key you set overlays it. Okta, Keycloak and Microsoft are per-tenant and need
             | `provider.{id}.issuer_uri`. Any provider with an `issuer_uri` has every endpoint it does not
             | spell out discovered from {issuer}/.well-known/openid-configuration (fetched through Laravel's
             | Http client with the two timeouts below, cached for discovery.cache_ttl, refused when the
             | document's issuer differs); a provider without one must spell out authorization_uri and
             | token_uri, plus jwk_set_uri for an `openid` registration and user_info_uri for any other, or the
             | boot is refused naming the key. Discovery is fetched on first use, so firefly:cache and console
             | boots never need the provider; discovery.eager resolves every registration at boot instead.
             |
             | Login: GET {login.authorization_endpoint_base_uri}/{id} builds the authorization request (state,
             | a nonce for `openid`, a PKCE S256 challenge when `pkce` — default true, ALWAYS for a public
             | client), keeps it in the session and redirects; GET {login.redirection_endpoint_base_uri}/{id}
             | checks the state (single-use, constant-time), exchanges the code, validates the id token against
             | the provider's JWKS (iss, aud, azp, exp, iat, nonce, sub, with clock_skew seconds of leeway),
             | loads userinfo, maps the claims to an OidcUser/OAuth2User principal (name from
             | user_name_attribute; authorities OIDC_USER/OAUTH2_USER + SCOPE_x, then your
             | GrantedAuthoritiesMapper bean), signs it into the session (id regenerated), publishes the
             | authentication events and redirects to the saved request or default_success_url; a failure
             | publishes the failure event and redirects to failure_url. The login page lists every
             | authorization_code registration as "Sign in with {client_name}". Tokens are kept in the
             | session ENCRYPTED with the application key; the principal carries claims, never a token.
             |
             | Logout: logout.oidc_initiated sends the browser to the provider's end_session_endpoint with
             | id_token_hint, client_id and post_logout_redirect_uri ({baseUrl} expands to the app's root).
             |
             | Defaults: enabled false, login.enabled false, login.authorization_endpoint_base_uri
             | '/oauth2/authorization', login.redirection_endpoint_base_uri '/login/oauth2/code',
             | login.default_success_url '/', login.always_use_default_success_url false, login.failure_url
             | '/login?error', logout.oidc_initiated false, logout.post_logout_redirect_uri '{baseUrl}/login?logout',
             | clock_skew 60, http.connect_timeout 5, http.timeout 5, http.macro true, discovery.cache_ttl 3600,
             | discovery.eager false, jwk_set.cache_ttl 3600, authorized_client.cache_ttl 86400; per
             | registration: client_authentication_method client_secret_basic when a client_secret is set and
             | none otherwise (the google and github presets say client_secret_basic outright — a web OAuth app
             | there is never public — so a registration on them without a client_secret is refused at boot),
             | authorization_grant_type authorization_code, redirect_uri
             | '{baseUrl}/login/oauth2/code/{registrationId}', scope the preset's or [], client_name the
             | preset's or the id, pkce true; per provider: user_name_attribute 'sub'.
            */
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

            /*
             | OAuth2 authorization server (firefly/security-oauth2-server): this application issues the tokens.
             | Requires the master flag AND session security (form_login.enabled, or session.enabled with a sign-in
             | mechanism of your own) — the authorization endpoint needs a session-held user — and cannot run
             | beside jwt.enabled (the local HMAC filter would reject every token this server issues) or beside
             | http_basic.enabled (the Basic filter would answer every client_secret_basic request as a failed
             | user login before the server saw it; the server authenticates its clients itself). Every
             | endpoint is answered by a filter ordered ahead of the CSRF and URL-rule filters, so no
             | `http.rules` entry and no `csrf.except` pattern is needed for them.
             |
             | Defaults: enabled false, issuer app.url, the endpoint paths below, RS256 self-contained access
             | tokens (300 s), refresh tokens rotated (3600 s), codes 300 s, id tokens 1800 s, PKCE required,
             | consent required, memory drivers, no purge, no rate limit.
            */
            'server' => [
                'enabled' => env('FIREFLY_OAUTH2_SERVER_ENABLED', false),

                // The `iss` claim and the base of every published URL. Default: app.url.
                // 'issuer' => env('APP_URL'),

                // The endpoint paths (Spring Authorization Server's defaults). `oidc_client_registration_endpoint`
                // is OFF while empty; `/connect/register` turns RFC 7591 dynamic registration on. A bearer with
                // scope `client.create` registers ONE client: the registration invalidates that access token, so
                // each further client needs a new initial access token, and a body asking for `client.create`
                // itself is refused (it would be a self-renewing registration credential no secret rotation
                // could reach). Registration REQUIRES A STORE THAT OUTLIVES THE REQUEST: set `clients.driver` to
                // `eloquent` below, or bind a durable RegisteredClientRepository of your own. The `memory` driver
                // is this config map rebuilt in every process, so a registered client would not survive the
                // request that created it; the boot refuses THAT store — the one it resolves, not the driver
                // name — rather than hand out dead credentials.
                // 'authorization_endpoint' => '/oauth2/authorize',
                // 'token_endpoint' => '/oauth2/token',
                // 'jwk_set_endpoint' => '/oauth2/jwks',
                // 'token_introspection_endpoint' => '/oauth2/introspect',
                // 'token_revocation_endpoint' => '/oauth2/revoke',
                // 'oidc_user_info_endpoint' => '/userinfo',
                // 'oidc_logout_endpoint' => '/connect/logout',
                // 'oidc_client_registration_endpoint' => '',

                /*
                 | The signing key: a PEM string (inline, starting with -----BEGIN) or a path to one. Generate it
                 | with `php artisan firefly:oauth2:keys` (RSA 2048 by default, `--algorithm=ES256` for P-256).
                 | `key_id` defaults to the RFC 7638 thumbprint of the public key. `previous_keys` lists keys that
                 | still VERIFY (they stay in the JWKS) after a rotation: [{key: <pem or path>, key_id: <kid>}].
                 | Every published key needs its own kid (a verifier keeps one key per id), so an explicit
                 | `key_id` must change with the key on a rotation: the boot refuses two keys under one kid.
                 | Required once `enabled` is true.
                */
                'jwt' => [
                    'signing_key' => env('FIREFLY_OAUTH2_SERVER_SIGNING_KEY', ''),
                    // 'key_id' => '',
                    // 'algorithm' => 'RS256',
                    // 'previous_keys' => [],
                ],

                // `self_contained` (a JWT: iss, sub, aud=client_id, exp, iat, nbf, jti, scope, client_id) or
                // `reference` (opaque; resolved only through introspection/userinfo). ttl in seconds.
                // Revocation (and the rotation that replaces an access token) is recorded on the authorization,
                // so it binds introspection, userinfo and a `reference` token at once — while a `self_contained`
                // token is accepted by any resource server that verifies the signature until its own `exp`,
                // which is what the short ttl is for. Choose `reference` when a revocation must bite immediately
                // at the resource server; it costs an introspection call per request.
                'access_token' => [
                    // 'format' => 'self_contained',
                    // 'ttl' => 300,
                ],

                // With `reuse` false (the default) every refresh rotates the token; presenting a superseded one
                // revokes the whole authorization (reuse detection).
                'refresh_token' => [
                    // 'ttl' => 3600,
                    // 'reuse' => false,
                ],

                'authorization_code' => [
                    // 'ttl' => 300,
                ],

                'id_token' => [
                    // 'ttl' => 1800,
                ],

                // PKCE (S256 only; `plain` is refused). `require_pkce` demands it of every authorization_code
                // request (OAuth 2.1); `require_proof_key_for_public_clients` demands it of public clients
                // (client_authentication_methods: [none]) even when the first is off.
                // 'require_pkce' => true,
                // 'require_proof_key_for_public_clients' => true,

                // Whether a client needs the user's consent by default (a client's
                // client_settings.require_authorization_consent overrides it), and a Blade view that replaces the
                // framework's consent page; it receives `$consent` (ConsentPageModel) and falls back, logged at
                // warning, when it does not exist or throws.
                'consent' => [
                    // 'required' => true,
                    // 'view' => '',
                ],

                /*
                 | Registered clients. `driver` is `memory` (this map) or `eloquent` (the oauth2_registered_clients
                 | table; publish the migration with `php artisan vendor:publish --tag=firefly-oauth2-server-migrations`).
                 | `client_secret` is the ENCODED secret — `{bcrypt}$2y$…` (password_hash), `{argon2id}…`, or
                 | `{noop}plain` in development only; a value with no {id} prefix is refused at boot because the
                 | encoder would never match it. `client_settings.jwk_set` is the decoded JWKS a private_key_jwt
                 | client signs its assertions with: RSA or EC public keys, `alg` one of RS256, RS384, RS512,
                 | ES256, ES384 or omitted (RS256 for RSA, ES256/ES384 for P-256/P-384), a `kid` on every key
                 | when the set holds more than one — a set that could never verify an assertion is refused at
                 | boot, naming the client and the key. `require_pkce`, `require_authorization_consent` and
                 | `reuse_refresh_tokens` follow the same rule as every other boolean key: true/false, or a string
                 | env() may hand back ("on"/"off", "yes"/"no", "1"/"0"); anything else is refused at boot rather
                 | than cast.
                */
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

                // Where codes, tokens (by SHA-256 hash, never by value) and consents live: `memory` (one process,
                // fine for tests and a single dev server) or `eloquent` (oauth2_authorizations +
                // oauth2_authorization_consents). The purge is a scheduled task under the DistributedLock, listed
                // by `firefly:schedule` and /actuator/scheduledtasks like any #[Scheduled] method.
                'authorizations' => [
                    'driver' => env('FIREFLY_OAUTH2_SERVER_AUTHORIZATIONS_DRIVER', 'memory'),
                    'purge' => [
                        // 'enabled' => false,
                        // 'cron' => '*/15 * * * *',
                    ],
                ],

                // A token bucket per client id (else per IP) at the token endpoint, over firefly/resilience's
                // store: `max_tokens` burst, `refill_rate` tokens per second. A refused request is
                // 429 temporarily_unavailable with Retry-After.
                'rate_limit' => [
                    // 'enabled' => false,
                    // 'max_tokens' => 60,
                    // 'refill_rate' => 1.0,
                ],
            ],
        ],

        /*
         | Response security headers, applied by a filter ordered -95 so they survive on error responses
         | too. Each value below is the framework default and is written verbatim onto the response.
         |
         | Default: enabled false.
        */
        'headers' => [
            'enabled' => env('FIREFLY_SECURITY_HEADERS_ENABLED', false),
            // 'hsts' => 'max-age=31536000; includeSubDomains',
            // 'frame_options' => 'DENY',
            // 'content_type_options' => 'nosniff',
            // 'referrer_policy' => 'no-referrer',
            // 'csp' => "default-src 'self'",
        ],

        /*
         | CSRF protection for state-changing requests. `except` holds Str::is() patterns skipped by the
         | filter — a JSON API authenticated by bearer token usually belongs here. With session security on
         | (session.enabled, or any mechanism that implies it) the filter verifies Laravel's session token,
         | read exactly as Laravel's own middleware reads it: the `_token` field, the `X-CSRF-TOKEN` header,
         | or the encrypted `XSRF-TOKEN` cookie echoed in `X-XSRF-TOKEN` the way a SPA client (Axios) sends
         | it. Without a session it is the stateless double-submit cookie check.
         |
         | Defaults: enabled false, except [].
        */
        'csrf' => [
            'enabled' => env('FIREFLY_SECURITY_CSRF_ENABLED', false),
            'except' => [
                // 'api/*',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Management endpoints — firefly/actuator
    |--------------------------------------------------------------------------
    |
    | The Spring-Actuator-shaped surface. Mounted at `endpoints.web.base-path`; the shipped endpoint ids
    | are: health, info, env, beans, conditions, mappings, loggers, scheduledtasks — plus metrics and
    | prometheus from firefly/observability.
    |
    */

    'management' => [

        // Master gate: false unmounts every actuator route. Default: true.
        'enabled' => true,

        /*
         | THE MANAGEMENT PORT — Spring Boot's `management.server.*`, and the same idea.
         |
         | With `port` set, every management surface — the actuator, and the admin dashboard with it —
         | answers ONLY on that port, and a request arriving on the application's port gets a 404. That is
         | what lets you bind the application to the internet and the management traffic to a private
         | interface, so an operator's URL is not merely unadvertised but unreachable.
         |
         | 404, NEVER 403. A 403 would confirm that a management surface exists on some other port, which is
         | one more fact than an unauthenticated scan of the public port deserves.
         |
         | `address` is a BIND address for your process manager, not a request-time check: nothing here can
         | make PHP listen on a second socket, so setting `port` tells the framework which requests to
         | ACCEPT and your web server or `artisan serve --port` decides what actually listens. Setting
         | `port` to the application's own port is rejected at boot rather than silently doing nothing.
         |
         | Defaults: port null (same port as the application), address null, base-path ''.
        */
        // 'server' => [
        //     'port' => (int) env('MANAGEMENT_PORT', 9001),
        //     'address' => env('MANAGEMENT_ADDRESS', '127.0.0.1'),
        //     // A prefix in FRONT of `endpoints.web.base-path`: '/internal' makes the health endpoint
        //     // '/internal/actuator/health'. Default: ''.
        //     'base-path' => '',
        // ],

        'endpoints' => [
            'web' => [
                // Default: '/actuator'.
                'base-path' => '/actuator',

                /*
                 | Web exposure, CSV or `*`. `*` is a wildcard in BOTH lists and EXCLUDE WINS, so
                 | `exclude => '*'` is the kill switch. Secure by default: only health and info are
                 | reachable; anything else answers 404 even though the endpoint exists. `env`, `beans`,
                 | `conditions`, `mappings` and `loggers` disclose configuration and wiring — expose them
                 | only behind the security.http rules above.
                 |
                 | Defaults: include 'health,info', exclude ''.
                */
                'exposure' => [
                    'include' => env('FIREFLY_ACTUATOR_EXPOSE', 'health,info'),
                    'exclude' => '',
                ],
            ],
        ],

        'endpoint' => [

            /*
             | Per-endpoint kill switch, checked at dispatch AND on the index: `firefly.management.
             | endpoint.<id>.enabled`. Default for every id: true.
            */
            // 'env' => ['enabled' => false],
            // 'loggers' => ['enabled' => false],

            'health' => [
                /*
                 | 'always' includes each contributor's component details in the body; anything else
                 | (including the default) returns the aggregated status only. Details name drivers,
                 | paths and error messages, so they are off by default.
                 |
                 | Default: 'never'.
                */
                'show-details' => env('FIREFLY_HEALTH_SHOW_DETAILS', 'never'),

                /*
                 | The DB indicator is ON BY DEFAULT whenever `database.default` names a connection with a
                 | driver — Spring Boot's DataSourceHealthIndicator auto-configuration. A failing query (a
                 | missing sqlite file, a refused connection) is caught and reported DOWN, and /health
                 | answers 503; an application with no default database gets no `db` component at all.
                 | Set false to remove the indicator.
                 |
                 | Default: true.
                */
                'db' => [
                    'enabled' => env('FIREFLY_HEALTH_DB_ENABLED', true),
                ],

                /*
                 | Free-space indicator. Reports DOWN below `threshold` bytes at `path`.
                 |
                 | Defaults: path = the process working directory (getcwd(), NOT base_path() — the sample
                 | below is the value you probably want, not the framework default), threshold = 10485760
                 | (10 MB).
                */
                // 'diskspace' => [
                //     'path' => base_path(),
                //     'threshold' => 10485760,
                // ],

                /*
                 | Probe groups served at /actuator/health/{name} — CSV of indicator names. An UNSET
                 | group is a 404, so these must stay commented out until you mean them.
                 |
                 | Default: unset (no groups configured).
                */
                // 'group' => [
                //     'liveness' => ['include' => 'ping'],
                //     'readiness' => ['include' => 'db,diskSpace'],
                // ],
            ],
        ],

        'info' => [
            /*
             | Surfaced verbatim under the `app` key of /actuator/info.
             |
             | Default: unset (the contributor returns nothing).
            */
            // 'app' => [
            //     'name' => env('APP_NAME', 'LaraFly'),
            //     'version' => '1.0.0',
            // ],

            /*
             | A generated build-info JSON file, surfaced under `build`. A missing file is not an error.
             |
             | Default: firefly-build.json in the process working directory (getcwd(), NOT base_path() —
             | the sample below is the value you probably want, not the framework default).
            */
            // 'build' => [
            //     'path' => base_path('firefly-build.json'),
            // ],

            /*
             | The `runtime` fragment of /actuator/info — PHP version/SAPI/OPcache, Laravel version, LaraFly
             | version, current and peak memory. Gated by #[ConditionalOnProperty(matchIfMissing: true)], so
             | leaving it unset keeps the contributor; setting it false removes the BEAN, not just the output.
             |
             | Default: true.
            */
            // 'runtime' => [
            //     'enabled' => false,
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Error page — firefly/web
    |--------------------------------------------------------------------------
    |
    | The HTML page a BROWSER gets when a request fails, in the same visual language as the welcome page and
    | the admin dashboard, with the exception, its `previous` chain and a stack trace whose frames are split
    | into yours and your dependencies'.
    |
    | WHO GETS IT. Only a client that NAMED `text/html` in its Accept header — which every browser does and
    | no API client does by accident. A request that wants JSON, an XMLHttpRequest, and a bare `curl` (whose
    | wildcard Accept expresses no preference) all still receive the RFC-7807 `application/problem+json`
    | document, with the same status and the same `code` the page shows. One failure, two renderings, one
    | vocabulary.
    |
    | Defaults: enabled true, trace = app.debug, title = app.name, excerpt-lines 7.
    |
    */

    // 'web' => [
    //     'error-page' => [
    //         // Turn this off to fall back to Laravel's own error page. Default: true.
    //         'enabled' => true,
    //
    //         /*
    //          | Whether the page carries the exception MESSAGE, its file and line, a source excerpt and the
    //          | stack trace. Follows `app.debug`, and setting it wins over that in both directions.
    //          |
    //          | It is enforced when the report is BUILT, not when it is rendered: with this off the
    //          | framework never walks the trace, never opens a source file and never copies the message, so
    //          | there is nothing assembled for a template mistake to leak. What production shows instead is
    //          | the status, the reason and the stable error code — enough for a user to quote into a ticket
    //          | and an operator to grep for, and nothing that names a class, a file or a row.
    //          |
    //          | Default: the value of `app.debug`.
    //         */
    //         'trace' => env('APP_DEBUG', false),
    //
    //         // The name in the page's wordmark and title. Default: `app.name`.
    //         'title' => env('APP_NAME', 'LaraFly'),
    //
    //         // How many source lines to show around a throwing line, clamped to 0-40. 0 shows none.
    //         // Default: 7.
    //         'excerpt-lines' => 7,
    //
    //         /*
    //          | CSV of path patterns that answer with `application/problem+json` WHATEVER the caller's
    //          | Accept header says. Checked BEFORE the header, because the header says who is asking and
    //          | the path says what the URL is.
    //          |
    //          | Without it, a developer opening an API URL in a browser is shown a styled page instead of
    //          | the payload their client will receive — and so is anything that follows a link into the API
    //          | with a copied browser header. Patterns use Laravel's `Str::is` wildcards.
    //          |
    //          | Default: 'api/*'.
    //         */
    //         'json-paths' => 'api/*,webhooks/*',
    //
    //         /*
    //          | Your OWN Blade view for a status, or for everything. The view is handed the same
    //          | `$error` report the built-in page gets — so it is bound by the same `trace` gate above and
    //          | cannot print a stack trace the settings withheld — plus `$settings`.
    //          |
    //          | A view that THROWS falls back to the built-in page rather than propagating: this renders
    //          | while the application is already failing, and an override is application code (a renamed
    //          | layout, a component querying the database that is down). A white screen at that moment is
    //          | the worst possible outcome.
    //          |
    //          | Default: [] (the framework's page for every status).
    //         */
    //         'views' => [
    //             '404' => 'errors.not-found',
    //             'default' => 'errors.generic',
    //         ],
    //     ],
    //
    //     /*
    //      | The problem+json document — what a CLIENT receives for a failure.
    //     */
    //     'problem' => [
    //         /*
    //          | Whether an UNHANDLED throwable's own message (a QueryException's SQL and bindings, a
    //          | TypeError's absolute path, a PDOException's host) may appear in `detail`. This is the
    //          | problem document's OWN gate: it does NOT follow `app.debug` and it does NOT follow
    //          | `error-page.trace`, because every local and compose environment sets APP_DEBUG and a console
    //          | fed by problem+json then renders a driver message in a red banner. With it off the client
    //          | gets `An unexpected error occurred. It has been logged; quote reference <traceId> …` and the
    //          | message stays on the exception, where the log has it beside the same traceId.
    //          |
    //          | Default: false. Turn it on deliberately, on a machine where the payload is yours to read.
    //         */
    //         'disclose' => false,
    //     ],
    // ],

    /*
    |--------------------------------------------------------------------------
    | Validation — firefly/validation
    |--------------------------------------------------------------------------
    |
    | Bean Validation over Laravel's validator: #[NotBlank], #[Size], #[Email], #[Pattern], #[Valid] … are
    | compiled into a manifest by `firefly:cache` and run before a #[RequestBody] DTO is hydrated, so an
    | invalid body is a 422 with one entry per failed constraint. One switch.
    |
    */

    'validation' => [
        /*
         | How a 422's field errors are WORDED. `constraint` (the default) publishes the constraint's own
         | sentence, the way Spring's FieldError does — `must not be blank`, `size must be between 1 and 50`,
         | `must match "^[A-Z0-9]…"` — with the field named once, in `field`, exactly as the client spelled it
         | (`shipTo.street`, `lines[1].sku`) and the constraint named in `constraint` (`NotBlank`, `Size`,
         | `Pattern`). A `message:` element on the attribute (`#[NotBlank(message: 'give us a name')]`) wins in
         | both styles. `laravel` keeps the sentences Laravel's validator writes, humanised attribute included
         | (`The ship to.street field is required.`), for an application whose clients already assert on
         | them. The `validate($data, $rules)` primitive has no constraints to describe and keeps Laravel's
         | sentences whatever this says. Anything else is refused at boot.
         |
         | Default: constraint.
        */
        'messages' => env('FIREFLY_VALIDATION_MESSAGES', 'constraint'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin dashboard — firefly/admin
    |--------------------------------------------------------------------------
    |
    | A browser dashboard over the actuator's data. It reads the endpoint registry IN-PROCESS, deliberately
    | bypassing the exposure model above — seeing beans, conditions and the environment locally without
    | first publishing them over HTTP to everyone is the whole point.
    |
    | That makes the dashboard's own URL the only boundary, so `enabled` DEFAULTS TO `app.debug`: an app
    | already serving stack traces is a development environment by definition, and an app with debug off
    | must opt in explicitly — and should put the route behind its own auth middleware when it does.
    | Setting the key wins over the debug default in both directions.
    |
    | Defaults: enabled = app.debug, base-path '/firefly', title = app.name, refresh-seconds 10,
    | theme 'auto', graph.max-nodes 220, pages.exclude ''.
    |
    */

    // 'admin' => [
    //     'enabled' => env('FIREFLY_ADMIN_ENABLED', false),
    //     'base-path' => '/firefly',
    //     'title' => env('APP_NAME', 'LaraFly'),
    //
    //     /*
    //      | How often a live page reloads itself. FLOORED AT 2: a shorter interval reloads faster than the
    //      | page renders, so the countdown would never finish and the dashboard would hammer the very
    //      | application it exists to observe.
    //      |
    //      | Default: 10.
    //     */
    //     'refresh-seconds' => 10,
    //
    //     // 'auto' (follow the operating system) | 'light' | 'dark'. Anything unrecognised falls back to
    //     // 'auto' rather than rendering unstyled. Default: 'auto'.
    //     'theme' => 'auto',
    //
    //     'graph' => [
    //         /*
    //          | The node count past which the Bean graph page LISTS the relations instead of drawing them.
    //          | A diagram past a couple of hundred nodes is a hairball rather than something anyone can
    //          | read. Configurable — not a constant — because "unreadable" depends on the screen and the
    //          | application; 0 always lists.
    //          |
    //          | Default: 220.
    //         */
    //         'max-nodes' => 220,
    //     ],
    //
    //     'pages' => [
    //         /*
    //          | CSV of page slugs to REFUSE. This is a refusal, not a menu preference: an excluded page is
    //          | hidden from the menu AND its URL 404s — hiding `env` from the menu achieves nothing if the
    //          | URL still answers. The index page's slug is `overview`.
    //          |
    //          | Default: '' (nothing excluded).
    //         */
    //         'exclude' => 'env,configprops',
    //     ],
    //
    //     /*
    //      | THE DATA BROWSER — /firefly/data
    //      |
    //      | A browsable, searchable, sortable view of the records behind your repositories, in the shape
    //      | Django's admin made familiar. It discovers every bean implementing CrudRepository — which you
    //      | get from `extends EloquentRepository` — and reads THROUGH the repository, so what it shows is
    //      | what your own data layer returns, not a raw table dump. Nothing to register: the sample
    //      | App\Orders\OrderRepository shows up as "Order" the moment this is switched on.
    //      |
    //      | IT HAS ITS OWN SWITCH, DEFAULTING TO FALSE, even though the dashboard around it already
    //      | defaults to app.debug. Beans, conditions and mappings describe the SHAPE of an application;
    //      | these are its customers' records. "Debug is on" is a fine reason to show the first and not the
    //      | second, so the browser is off until someone says otherwise — and when it is off, its pages
    //      | 404 rather than 403, because a 403 confirms the surface exists.
    //     */
    //     'data' => [
    //         // Default: false.
    //         'enabled' => env('FIREFLY_ADMIN_DATA_ENABLED', false),
    //
    //         /*
    //          | Whether the browser may EDIT and DELETE records. Ineffective on its own — a write needs
    //          | this AND `enabled` — so switching the browser on never silently makes it writable. With
    //          | this off the edit form is not rendered and the write URLs refuse.
    //          |
    //          | There is deliberately no "create": a generic form cannot honour the constructor
    //          | invariants of an arbitrary entity, and one that quietly bypassed them would be worse than
    //          | not having it. Create records through your own use cases.
    //          |
    //          | Default: false.
    //         */
    //         'writable' => env('FIREFLY_ADMIN_DATA_WRITABLE', false),
    //
    //         // Rows per page, and the ceiling a `?per-page=` in the URL may raise it to. Both are clamped
    //         // to a hard maximum of 1000 so no query string can ask for the whole table at once.
    //         // Defaults: 25 and 200.
    //         'page-size' => 25,
    //         'max-page-size' => 200,
    //
    //         /*
    //          | CSV of resource slugs to REFUSE — same hard refusal as `pages.exclude` above: excluded
    //          | resources are absent from the menu AND their URLs 404. Use it for the tables you do not
    //          | want browsable even by someone who is allowed in at all.
    //          |
    //          | Default: '' (nothing excluded).
    //         */
    //         'exclude' => 'order',
    //
    //         /*
    //          | Whether to discover an entity's RELATIONS, so a record links to the rows it references and
    //          | the Entity map page has edges to draw.
    //          |
    //          | Discovery CALLS the model methods that declare a relation, because a method's name says
    //          | nothing and only the call reveals which columns it joins on. Only a public, no-argument
    //          | method whose DECLARED RETURN TYPE is an Eloquent Relation is ever called — the same signal
    //          | Laravel's own tooling relies on, and one an accessor cannot claim without lying about its
    //          | signature. This key exists so an application with an unusual model base can switch that off
    //          | without losing the rest of the browser.
    //          |
    //          | Default: true.
    //         */
    //         'relations' => true,
    //     ],
    //
    //     /*
    //      | THE DATASOURCE PAGE — /firefly/datasource
    //      |
    //      | Connections (with secrets masked), whether PDO holds them open between requests, and the
    //      | compiled #[Transactional] contract. It needs no key to appear; these two govern the parts that
    //      | do something rather than report something.
    //     */
    //     'datasource' => [
    //         /*
    //          | Whether the page may OPEN a configured connection to report that it answers. One is probed
    //          | per page load — the default, or the one named by `?probe=` — because opening a socket can
    //          | hang against a firewalled host, and a page that opened every configured connection would
    //          | take the slowest one's timeout to render, on the page you opened because something is wrong.
    //          |
    //          | Default: true.
    //         */
    //         'probe' => true,
    //
    //         /*
    //          | The connection WIZARD: a form that opens a connection you have not configured yet and
    //          | reports the server version or the driver's own error, plus the config block to paste. It
    //          | writes nothing.
    //          |
    //          | OFF BY DEFAULT, and refused outright when `app.env` is production — a check no key lifts. A
    //          | form that opens a socket to a host somebody typed is a request-forgery primitive, and its
    //          | errors distinguish "refused" from "timed out" well enough to map a private network. It is a
    //          | convenience for a developer's machine and should be unreachable anywhere else.
    //          |
    //          | Default: false.
    //         */
    //         'wizard' => env('FIREFLY_ADMIN_DATASOURCE_WIZARD', false),
    //     ],
    //
    //     /*
    //      | THE FEATURE-SWITCH CONSOLE — /firefly/settings
    //      |
    //      | Every framework switch this application is running with, where each value came from, and —
    //      | outside production — a control to change it.
    //      |
    //      | IT IS THE ONE PAGE THAT CHANGES THE APPLICATION rather than describing it, which is why it is
    //      | off by default while the rest of the dashboard follows `app.debug`. A surface that alters a
    //      | running system should never appear because somebody left a debug flag on.
    //      |
    //      | A change is written to ONE json file under bootstrap/cache and merged over configuration at
    //      | boot; deleting that file restores your configured values exactly. Nothing is ever written to
    //      | `.env` — a config cache would disagree with it until someone cleared it, the file is routinely
    //      | read-only in a container image, and a web form that edits the file holding your database
    //      | password is not a feature.
    //      |
    //      | Only a fixed, framework-owned list of switches can be written. A crafted POST naming `app.key`
    //      | or a database host finds nothing to write, which is what keeps this a feature switch rather
    //      | than a remote configuration endpoint.
    //     */
    //     'settings' => [
    //         // Default: false.
    //         'enabled' => env('FIREFLY_ADMIN_SETTINGS_ENABLED', false),
    //
    //         // Whether the page has controls as well as readings. Ineffective in production, where every
    //         // write is refused whatever this says. Default: false.
    //         'writable' => env('FIREFLY_ADMIN_SETTINGS_WRITABLE', false),
    //     ],
    // ],

    /*
    |--------------------------------------------------------------------------
    | API documentation — firefly/openapi
    |--------------------------------------------------------------------------
    |
    | The OpenAPI 3.1 document is generated from the same compiled artifacts the dispatcher and the
    | validator read — RouteManifest for paths/operations/parameters, ConstraintManifest for request-body
    | schemas — so there is no annotation dialect and nothing that can drift. `php artisan firefly:openapi`
    | writes the same document to a file or to stdout.
    |
    | Both routes are mounted natively on the illuminate Router from a BootPass, which is what makes their
    | paths configurable at all: an attribute route bakes its literal into a compiled RouteDescriptor. It is
    | also why this package's own routes never appear in the document it generates.
    |
    | SECURING IT. The whole surface is ordinary routes, so `firefly.security.http.rules` above covers it
    | with no code edge. A deployment that wants no documentation surface in production sets `enabled` to
    | false — which leaves both paths genuinely unrouted, not merely blank — and generates the document in
    | CI with `firefly:openapi --output=` instead.
    |
    | Defaults: enabled true, path '/openapi.json', viewer.enabled true, viewer.path '/openapi',
    | viewer.style 'swagger', title 'API', version '0.0.0', description '', servers [], exclude '',
    | include-html false.
    |
    */

    // 'openapi' => [
    //     'enabled' => true,
    //     'path' => '/openapi.json',
    //
    //     'viewer' => [
    //         'enabled' => true,
    //         'path' => '/openapi',
    //
    //         /*
    //          | Which console /openapi renders. Three values, and only one of them makes a third-party
    //          | request:
    //          |
    //          |   'swagger' — the DEFAULT. The official Swagger UI, served from THIS application's own
    //          |               origin out of the swagger-api/swagger-ui composer package (a hard dependency
    //          |               of firefly/openapi, so it is already on disk). Byte-for-byte the distribution
    //          |               Swagger publishes — try-it-out, deep linking, OAuth2 — with no CDN request and
    //          |               no npm step. Falls back to 'builtin' if the dist is somehow missing, rather
    //          |               than rendering a page whose assets 404.
    //          |   'builtin' — a hand-written, dependency-free reference: one inline script, no third-party
    //          |               JavaScript at all. Groups operations by tag and resolves $ref client-side.
    //          |   'cdn'     — Swagger UI fetched from cdn.jsdelivr.net at an exactly pinned version. The
    //          |               ONLY style that makes a network request at page view, and therefore the only
    //          |               one that renders nothing in an air-gapped or strict-CSP deployment. No
    //          |               Subresource Integrity hash is claimed: one the framework cannot verify at
    //          |               release time would be security theatre.
    //          |
    //          | Anything unrecognised falls back to 'swagger' rather than rendering a blank page.
    //          |
    //          | Default: 'swagger'.
    //         */
    //         'style' => 'swagger',
    //
    //         /*
    //          | The older spelling of `style => 'cdn'`, kept so an application that set it before `style`
    //          | existed keeps the behaviour it configured. `cdn => true` still FORCES the CDN page and wins
    //          | over `style`; prefer `style` in new configuration.
    //          |
    //          | Default: false.
    //         */
    //         // 'cdn' => false,
    //     ],
    //
    //     /*
    //      | Info Object members, written verbatim into the document. `summary` is 3.1's short one-line
    //      | form (3.0 had only `description`); `terms-of-service` must be a URL if you set it.
    //     */
    //     'title' => env('APP_NAME', 'API'),
    //     'version' => '1.0.0',
    //     'description' => '',
    //     'summary' => 'Orders, customers and fulfilment.',
    //     'terms-of-service' => 'https://example.test/terms',
    //
    //     /*
    //      | Server Objects. Both spellings a real config file uses are accepted — a bare URL string, and
    //      | OpenAPI's own object form with a `description`. An entry that is neither is DROPPED rather than
    //      | emitted, because a Server Object with no `url` is invalid under the 3.1 schema.
    //      |
    //      | Default: [].
    //     */
    //     'servers' => [
    //         'https://api.example.test',
    //         // ['url' => 'https://staging.example.test', 'description' => 'Staging'],
    //     ],
    //
    //     /*
    //      | CSV of path prefixes left out of the document. Note this only removes them from the SPEC — it
    //      | does not unroute them; that is what firefly.security.http.rules is for.
    //      |
    //      | Default: ''.
    //     */
    //     'exclude' => '/internal,/admin',
    //
    //     /*
    //      | Document #[Controller] HTML routes as `text/html` operations. Off by default: an HTML page is
    //      | not part of a JSON API's contract, and a typed client generated from a document containing one
    //      | gets a method that returns markup.
    //      |
    //      | Default: false.
    //     */
    //     'include-html' => false,
    // ],

    /*
    |--------------------------------------------------------------------------
    | Observability — firefly/observability
    |--------------------------------------------------------------------------
    |
    | The Micrometer analogue. `metrics.enabled` gates the MeterRegistry, the HTTP MetricsFilter, the
    | CQRS metrics recorder and both /actuator/metrics and /actuator/prometheus, with the same key on
    | each so they can never disagree. `tracing.enabled` is the second gate: distributed tracing over
    | OpenTelemetry, off by default.
    |
    */

    'observability' => [
        'metrics' => [

            // Default: true (matchIfMissing).
            'enabled' => true,

            /*
             | Naming a CACHE STORE swaps SimpleMeterRegistry for CacheMeterRegistry, whose counters and
             | timers accumulate ACROSS PROCESSES. This matters under PHP-FPM: each request is a fresh
             | process, so with the in-memory registry a scrape of /actuator/metrics sees only what that
             | scrape's own request recorded — which reads as data but is not. Point it at a store with
             | an atomic increment (redis, memcached, apc, dynamodb); `array` is no better than memory.
             |
             | Opt-in on purpose: a registry that silently starts writing to whatever cache an app
             | happens to have configured is a surprise.
             |
             | Default: '' (in-process SimpleMeterRegistry).
            */
            'store' => env('FIREFLY_METRICS_STORE', ''),

            /*
             | Expiry in seconds for each cache-backed meter, so a meter nothing writes any more is
             | eventually reclaimed instead of living in the store forever. Only consulted when `store`
             | is set; 0 or less means no expiry.
             |
             | Default: 0 (no expiry).
            */
            'ttl' => (int) env('FIREFLY_METRICS_TTL', 0),

            /*
             | Histogram buckets for timers — Micrometer's distribution statistics, Prometheus's `histogram`
             | type. `buckets` is a list of upper bounds in SECONDS applied to every timer; `per-meter` maps a
             | meter name to its own list (an empty list turns that one meter back into a summary). A timer
             | with buckets scrapes as `<name>_bucket{le="…"}` + `_count` + `_sum` (what histogram_quantile()
             | needs); without, as the `_count` + `_sum` summary it always was.
             |
             | Off by default because switching a family's `# TYPE` from summary to histogram on upgrade would
             | change a running scrape without being asked. The Prometheus client default list is the one to
             | start from; the cache-backed registry carries the buckets too.
             |
             | Defaults: buckets [], per-meter [].
            */
            'distribution' => [
                // 'buckets' => [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10],
                // 'per-meter' => ['http_server_requests_seconds' => [0.05, 0.1, 0.25, 0.5, 1, 2.5]],
                'buckets' => [],
            ],
        ],

        /*
         | Micrometer's method attributes: #[Timed], #[Counted] and #[Observed] on any #[Service]/
         | #[Component]/#[Repository] method, enforced through the SAME proxy chain #[Transactional] and
         | #[PreAuthorize] use. The metric advice is the OUTERMOST link (order 50, ahead of security's 100
         | and the transaction's 1000), so a timer measures the authorization refusal and the COMMIT as well
         | as the method body — the latency a caller actually waited for — and a refused call is counted as
         | a failure instead of vanishing from the meter.
         |
         | #[Timed(value:, extraTags:, description:, longTask:)] records a timer, tagged `exception` with the
         | thrown class's short name (`none` on success). `longTask` adds a `<meter>.active` gauge carrying
         | the timer's own tags and holding the number of invocations THIS PROCESS has in flight (a nested or
         | recursive call reads 2, not 1); with a `metrics.store` configured the shared key is last-writer-
         | wins like every other set-gauge, so read it as "work in flight somewhere" rather than as a
         | fleet-wide count. #[Counted(value:, extraTags:, recordFailuresOnly:)] counts invocations, tagged
         | `result`. #[Observed(name:, contextualName:, lowCardinalityKeyValues:)] starts a span AND a timer
         | under one name — Micrometer's Observation API in one attribute; with tracing off it degrades to
         | the timer.
         |
         | A failure INSIDE the recording never reaches the caller: the meters and the span are written
         | best-effort, so a cache-backed registry that cannot reach its store loses the sample rather than
         | turning a method that returned into a method that threw.
         |
         | #[Timed(percentiles:)] is REFUSED at scan time: this package publishes fixed histogram buckets,
         | not client-side quantile summaries. Configure `metrics.distribution.per-meter` above and compute
         | the quantile in the query instead.
         |
         | Turning `enabled` off makes every one of the three inert (the proxies run a pass-through link);
         | it never leaves them half-enforced. The three `name` keys are the meter names used when an
         | attribute does not name its own.
         |
         | Defaults: enabled true, timed.name 'method.timed', counted.name 'method.counted',
         | observed.name 'method.observed'.
        */
        'method' => [
            'enabled' => env('FIREFLY_OBSERVABILITY_METHOD_ENABLED', true),
            'timed' => [
                // 'name' => 'method.timed',
            ],
            'counted' => [
                // 'name' => 'method.counted',
            ],
            'observed' => [
                // 'name' => 'method.observed',
            ],
        ],

        /*
         | The rolling buffer behind /actuator/httpexchanges (and the request counter in /actuator/process)
         | — the last N requests this application answered, newest first.
         |
         | A SEPARATE SWITCH FROM METRICS, deliberately: metrics aggregate, this retains individual
         | requests. An operator happy to publish latency histograms may still want no per-request record
         | kept anywhere, and has to be able to say so without losing metrics.
        */
        'httpexchanges' => [

            /*
             | Gates the RECORDING FILTER, not the endpoints — /actuator/httpexchanges and /actuator/process
             | stay mounted either way and answer `"recording": false`, because two 404s that explain
             | nothing is the opposite of what an operator staring at an empty panel needs.
             |
             | Compared as a string by #[ConditionalOnProperty], so `1`/`'on'`/`'yes'` read as OFF. Use a
             | boolean literal.
             |
             | Default: true (matchIfMissing).
            */
            'enabled' => true,

            /*
             | Ring size, clamped to [1, 10000]. Both ends of the clamp are load-bearing: 0 would divide by
             | zero inside the cache-backed recorder (a config typo that 500s every request), and capacity
             | is the number of cache keys fetched per endpoint call, so a very large value builds an
             | endpoint that times out.
             |
             | Default: 100.
            */
            'capacity' => 100,

            /*
             | Naming a CACHE STORE swaps InMemoryHttpExchangeRecorder for CacheHttpExchangeRecorder. This
             | matters more here than it does for metrics: under PHP-FPM the in-memory ring is not merely
             | stale but always EMPTY — each request is a fresh process, and the request rendering the
             | endpoint has not been recorded yet, because the filter records on the way out.
             |
             | Default: '' (process-local InMemoryHttpExchangeRecorder).
            */
            'store' => env('FIREFLY_HTTPEXCHANGES_STORE', ''),

            /*
             | Expiry in seconds for each cache-backed row. Only consulted when `store` is set; 0 or less
             | means no expiry.
             |
             | Default: 0 (no expiry).
            */
            'ttl' => (int) env('FIREFLY_HTTPEXCHANGES_TTL', 0),

            /*
             | Add masked request headers to each row. Request and response BODIES are never recorded, with
             | or without this.
             |
             | Default: false.
            */
            'include-headers' => false,

            /*
             | Glob patterns whose requests are recorded by nobody. Setting this REPLACES the default rather
             | than adding to it, and an empty list means "record everything, management traffic included".
             |
             | The default is the management base path and everything under it, because a dashboard is a
             | polling client: left in, a panel refreshing /actuator/httpexchanges would evict every genuine
             | request from a 100-row ring and then show the operator nothing but their own polling.
             | Running firefly/admin? Add its base path here for exactly the same reason — the framework
             | does not reach into another package's key to guess at its mount point.
             |
             | Default: the value of management.endpoints.web.base-path, plus that path with `/*`.
            */
            // 'exclude' => ['actuator', 'actuator/*', 'firefly', 'firefly/*'],
        ],

        /*
         | Distributed tracing — the Spring Boot `management.tracing` analogue over OpenTelemetry. Off by
         | default, and inert without the SDK: `composer require open-telemetry/sdk` (plus
         | open-telemetry/exporter-otlp for `exporter => otlp`) makes OpenTelemetryAutoConfiguration bind the
         | real Tracer ahead of the NoOp. With it on, every request gets a SERVER span continued from an inbound
         | W3C traceparent, outbound Laravel Http calls get CLIENT spans and carry traceparent, commands and
         | queries get INTERNAL spans, and events carry traceparent in their envelope headers with
         | PRODUCER/CONSUMER spans. The trace and span ids reach Laravel Context (firefly.trace_id /
         | firefly.span_id), every log line (see `logging.structured`), and /actuator/httpexchanges.
        */
        'tracing' => [

            /*
             | The master gate. Compared as a string by #[ConditionalOnProperty], so use a boolean.
             |
             | Default: false.
            */
            'enabled' => env('FIREFLY_TRACING_ENABLED', false),

            /*
             | Where finished spans go: `none` (recorded for ids and propagation, exported nowhere),
             | `console` (one JSON document per span on stdout) or `otlp` (see `otlp` below). An application
             | that binds its own OpenTelemetry\SDK\Trace\SpanExporterInterface bean overrides this.
             |
             | Default: 'none'.
            */
            'exporter' => env('FIREFLY_TRACING_EXPORTER', 'none'),

            /*
             | The `service.name` resource attribute a backend groups spans by. Empty falls back to app.name;
             | `logging.structured` uses the same name for `service.name` in log lines.
             |
             | Default: '' (app.name).
            */
            'service-name' => env('FIREFLY_TRACING_SERVICE_NAME', ''),

            /*
             | Extra resource attributes (scalar values only) stamped on every span beside service.name and
             | deployment.environment.name (app.env).
             |
             | Default: [].
            */
            // 'resource-attributes' => ['deployment.region' => 'eu-west-1', 'service.version' => '26.09.3'],

            /*
             | How new traces are sampled. An inbound traceparent's sampled flag always wins (ParentBased), so
             | this only decides for traces that START here. `ratio` keeps the given share of traces.
             |
             | Defaults: type 'always_on', ratio 1.0.
            */
            'sampler' => [
                'type' => env('FIREFLY_TRACING_SAMPLER', 'always_on'),
                'ratio' => (float) env('FIREFLY_TRACING_SAMPLER_RATIO', 1.0),
            ],

            /*
             | The OTLP exporter. `endpoint` is the collector's base URL (`/v1/traces` is appended for the
             | http protocols, exactly as OTEL_EXPORTER_OTLP_ENDPOINT would be); `protocol` is http/protobuf
             | (the default every collector accepts), http/json, or grpc (needs open-telemetry/transport-grpc
             | and ext-grpc); `headers` is `name=value,name2=value2` — the OTEL_EXPORTER_OTLP_HEADERS shape,
             | parsed by the SDK's own parser and percent-decoded the same way, so a vendor's documented
             | `Authorization=Basic%20<b64>` works verbatim and a pair without `=` refuses to boot — or a map,
             | whose values are taken as written. Spans are batched and flushed when the request terminates.
             |
             | Defaults: endpoint 'http://localhost:4318', protocol 'http/protobuf', headers ''.
            */
            'otlp' => [
                'endpoint' => env('FIREFLY_TRACING_OTLP_ENDPOINT', 'http://localhost:4318'),
                'protocol' => env('FIREFLY_TRACING_OTLP_PROTOCOL', 'http/protobuf'),
                'headers' => env('FIREFLY_TRACING_OTLP_HEADERS', ''),
            ],

            /*
             | The SERVER span per request (TracingFilter). `exclude` is a glob list of paths that get no span;
             | like httpexchanges.exclude it defaults to the management base path so a polling dashboard does
             | not produce a trace every five seconds, and setting it REPLACES that default.
             |
             | Defaults: enabled true; exclude = management.endpoints.web.base-path plus that path with `/*`.
            */
            'http-server' => [
                'enabled' => true,
                // 'exclude' => ['actuator', 'actuator/*', 'firefly', 'firefly/*'],
            ],

            /*
             | CLIENT spans and traceparent on every Laravel Http client request (a Guzzle middleware
             | installed on the Http factory at boot).
             |
             | Default: true.
            */
            'http-client' => [
                'enabled' => true,
            ],

            /*
             | INTERNAL spans around every command and query the buses dispatch, named by the message class.
             |
             | Default: true.
            */
            'cqrs' => [
                'enabled' => true,
            ],

            /*
             | PRODUCER spans on publish (traceparent stamped into the envelope headers) and CONSUMER spans on
             | delivery, in-memory, queue and every broker consumer alike.
             |
             | Default: true.
            */
            'eda' => [
                'enabled' => true,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging — firefly/observability
    |--------------------------------------------------------------------------
    |
    | What a log LINE looks like, on top of Laravel's own logging.php (which still decides where lines go).
    | Spring Boot's `logging.structured.format`, for Monolog channels: the formatter is set on the listed
    | channels' EXISTING handlers, never replacing them. Every line — structured or not — already carries
    | the correlation id, the request id and, with tracing on, the trace and span ids in Monolog `extra`.
    |
    */

    'logging' => [
        'structured' => [

            /*
             | '' keeps Laravel's plain-text lines. `json` is Monolog's JsonFormatter
             | ({"message","context","level","level_name","channel","datetime","extra":{"trace_id",
             | "span_id","correlation_id","request_id","service_name","service_environment"}}); `ecs` is
             | Elastic Common Schema 8 (@timestamp, log.level, message, ecs.version, log.logger,
             | service.{name,environment}, trace.id, span.id, labels.{correlation_id,request_id}, error.*,
             | context, extra); `logstash` is Monolog's LogstashFormatter (@timestamp, @version, host,
             | message, type, channel, level, monolog_level, fields, context). See docs/modules/logging.md
             | for one full line of each. Anything else refuses to boot.
             |
             | Default: ''.
            */
            'format' => env('FIREFLY_LOG_FORMAT', ''),

            /*
             | The channels whose handlers get the formatter (and the id processors). Empty means the default
             | channel (logging.default). A `stack` channel's handlers ARE its members' handlers, so listing
             | the stack formats every member — with `ignore_exceptions` on too, through the group handler
             | Laravel wraps them in — and listing a member as well, in either order, changes nothing (each
             | processor goes on once, the formatter is simply set again). Every name must exist under
             | logging.channels: one that does not refuses
             | to boot (checked at boot, before anything writes a line), because Laravel would quietly hand it
             | an emergency logger and the channel you actually write to would keep plain text without a
             | single id.
             |
             | Default: [] (the default channel).
            */
            // 'channels' => ['stack', 'stderr'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resilience — firefly/resilience
    |--------------------------------------------------------------------------
    |
    | Named pattern instances, read once into ResilienceRegistry. `<pattern>.<name>.<key>` where pattern
    | is retry / circuit-breaker / rate-limiter / bulkhead / time-limiter and name is whatever your code
    | passes to $registry->circuitBreaker('payments'). An unconfigured name still works — every pattern
    | has defaults — as long as no OTHER name is configured under that pattern.
    |
    | NOTE — this section is the one exception to the "commented out at its default" convention above. A
    | pattern instance is named by YOUR code, so there is no default instance to print; the commented blocks
    | below are ILLUSTRATIVE named instances, and several of their values are deliberately not the framework
    | default (the real defaults are: retry wait-duration 0, backoff-multiplier 1.0; circuit-breaker
    | minimum-number-of-calls 0; time-limiter timeout 30s). Only `store.lock-block-timeout` below is written
    | at its true default.
    |
    | See docs/modules/resilience.md for the full key-by-key tables.
    |
    */

    'resilience' => [

        /*
         | How long a pattern waits for the shared-state mutex before failing fast with a 503. This is
         | the WAIT budget, not how long the lock is held. The right value depends on the cache driver:
         | an array store or a local Redis hands over in microseconds, a database-backed cache across an
         | availability zone can legitimately need tens of milliseconds.
         |
         | Default: 0.5 (500ms).
        */
        'store' => [
            'lock-block-timeout' => '500ms',
        ],

        // 'retry' => [
        //     'payments' => ['max-attempts' => 3, 'wait-duration' => '250ms', 'backoff-multiplier' => 2.0],
        // ],
        // 'circuit-breaker' => [
        //     'payments' => [
        //         'failure-threshold' => 5,
        //         'window-size' => 10,
        //         'minimum-number-of-calls' => 5,
        //         'wait-duration-in-open' => '30s',
        //         'half-open-max-calls' => 1,
        //         'half-open-probe-timeout' => '30s',
        //     ],
        // ],
        // 'rate-limiter' => [
        //     'api' => ['max-tokens' => 10, 'refill-rate' => 10.0, 'timeout' => 0],
        // ],
        // 'bulkhead' => [
        //     'db' => ['max-concurrent' => 10, 'max-wait' => 0, 'permit-ttl' => '60s'],
        // ],
        // 'time-limiter' => [
        //     'payments' => ['timeout' => '2s'],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Data — firefly/data
    |--------------------------------------------------------------------------
    |
    | The repository and transaction layer. Everything here has a safe default; the block exists so the
    | switches are findable.
    |
    */

    'data' => [

        /*
         | Spring's PersistenceExceptionTranslator. With it on, a driver failure leaves every repository
         | method and every #[Transactional] method as a member of the kernel's DataAccessException family
         | — DuplicateKeyException (409 DUPLICATE_KEY), DataIntegrityViolationException (409),
         | CannotAcquireLockException / DeadlockLoserDataAccessException (409), QueryTimeoutException
         | (504), DataAccessResourceFailureException (503, unreachable), TransientDataAccessResource-
         | Exception (503, retryable), BadSqlGrammarException (500) — with the QueryException as `previous`
         | and a fixed sentence as the message, so the statement never reaches problem+json. Rollback rules
         | match the translated type AND the original. Off: the raw QueryException, as before.
         |
         | Default: true.
        */
        'exception-translation' => [
            'enabled' => env('FIREFLY_DATA_EXCEPTION_TRANSLATION', true),
        ],

        'transaction' => [
            /*
             | Seconds a #[Transactional] unit of work may run when its attribute names no `timeout:`.
             | On the outermost transaction the driver is told to give up on a statement past the budget
             | (pgsql statement_timeout; mysql max_execution_time, mariadb max_statement_time — each with
             | innodb_lock_wait_timeout, and a `mysql` connection whose server is mariadb is detected;
             | sqlite the busy timeout) and a wall-clock deadline is checked when the method returns: an
             | overrun is rolled back and answered 504 TRANSACTION_TIMED_OUT. 0 means no deadline.
             |
             | Default: 0.
            */
            'default-timeout' => (int) env('FIREFLY_DATA_TRANSACTION_TIMEOUT', 0),

            /*
             | Whether the driver-level statement timeout above is issued at all. false keeps only the
             | wall-clock check — for a driver whose SET the connection user may not run.
             |
             | Default: true.
            */
            'statement-timeout' => env('FIREFLY_DATA_STATEMENT_TIMEOUT', true),
        ],

        /*
         | #[TransactionalEventListener]: listeners that run in a phase of the transaction their event
         | was published in (BEFORE_COMMIT, AFTER_COMMIT, AFTER_ROLLBACK, AFTER_COMPLETION). false leaves
         | every such listener unregistered — nothing else changes, #[AsEventListener] is unaffected.
         |
         | Default: true.
        */
        'transactional-event-listeners' => [
            'enabled' => env('FIREFLY_DATA_TRANSACTIONAL_LISTENERS', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling — firefly/scheduling
    |--------------------------------------------------------------------------
    |
    | Which DistributedLock backs #[Scheduled] tasks so one task runs once across N instances:
    |   'none'     — no coordination (correct for a single instance). The default.
    |   'cache'    — the app's atomic cache lock.
    |   'postgres' — Postgres advisory locks; requires firefly/scheduling-postgres.
    |
    */

    'scheduling' => [
        'lock' => [
            'provider' => env('FIREFLY_SCHEDULING_LOCK', 'none'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | CQRS — firefly/cqrs
    |--------------------------------------------------------------------------
    */

    'cqrs' => [

        /*
         | The broker destination domain events are published to when a handler's #[CommandHandler] does
         | not name one of its own.
         |
         | Default: 'cqrs.events'.
        */
        'default_destination' => 'cqrs.events',

        /*
         | What DomainEventBridge does when publishing throws. The publish runs AFTER the DB commit, so
         | the write already succeeded:
         |   'log'   — swallow and log; the command result stands, the integration publish is best-effort.
         |   'raise' — rethrow wrapped in CommandProcessingException so the caller sees the failure.
         |
         | Default: 'log'.
        */
        'event_failure_strategy' => 'log',

        /*
         | Default TTL in seconds for #[Cacheable] query results that do not declare their own. UNSET
         | means "no default TTL" — not zero — so leave it commented out unless you want one.
         |
         | Default: unset.
        */
        // 'query' => [
        //     'cache_ttl' => 60,
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Events — firefly/eda (+ eda-rabbitmq / eda-postgres / eda-kafka)
    |--------------------------------------------------------------------------
    |
    | `provider` selects the EventPublisher adapter:
    |   'memory'   — in-process bus. The default; listeners run synchronously.
    |   'queue'    — Laravel queue; listeners run in `queue:work`.
    |   'rabbitmq' — requires firefly/eda-rabbitmq.
    |   'postgres' — requires firefly/eda-postgres; enables the same-transaction outbox.
    |   'kafka'    — requires firefly/eda-kafka and ext-rdkafka.
    |
    | The broker providers are consumed by `php artisan firefly:eda:consume`; 'queue' uses
    | `php artisan queue:work`; 'memory' has no consumer loop.
    |
    */

    'eda' => [

        'provider' => env('FIREFLY_EDA_PROVIDER', 'memory'),

        /*
         | Envelope encoding. Only 'json' ships today; anything else throws at boot rather than picking
         | a format silently.
         |
         | Default: 'json'.
        */
        'serialization_format' => 'json',

        /*
         | In-process delivery retries per listener before the envelope goes to the DeadLetterStore, and
         | the delay between attempts in seconds.
         |
         | Defaults: retries 0, retry_delay 0.0.
        */
        'retries' => 0,
        'retry_delay' => 0.0,

        /*
         | Broker destinations `firefly:eda:consume` binds when `--destination` is not passed. Must be a
         | list of strings.
         |
         | Default: [].
        */
        'destinations' => [
            // 'cqrs.events',
        ],

        /*
         | provider=queue: which Laravel queue connection and queue name carry the envelopes. Both UNSET
         | means "the application's own defaults".
         |
         | Default: unset.
        */
        // 'queue' => [
        //     'connection' => 'redis',
        //     'name' => 'events',
        // ],

        /*
         | Consumer group identity, used by the Kafka adapter.
         |
         | Default: 'firefly'.
        */
        // 'consumer' => [
        //     'group_id' => 'firefly',
        // ],

        /*
         | provider=rabbitmq. The exchange is topic-routed by event type; the DLX receives envelopes a
         | consumer nacks.
         |
         | Defaults: host '127.0.0.1', port 5672, user 'guest', password 'guest', vhost '/',
         | exchange 'firefly.events', queue 'firefly.eda', dlx 'firefly.events.dlx', prefetch 10.
        */
        // 'rabbitmq' => [
        //     'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        //     'port' => (int) env('RABBITMQ_PORT', 5672),
        //     'user' => env('RABBITMQ_USER', 'guest'),
        //     'password' => env('RABBITMQ_PASSWORD', 'guest'),
        //     'vhost' => env('RABBITMQ_VHOST', '/'),
        //     'exchange' => 'firefly.events',
        //     'queue' => 'firefly.eda',
        //     'dlx' => 'firefly.events.dlx',
        //     'prefetch' => 10,
        // ],

        /*
         | provider=postgres — the same-transaction outbox. `connection` UNSET means the default database
         | connection. `channel` is the LISTEN/NOTIFY channel; `max_attempts` bounds relay retries before
         | a row is marked FAILED.
         |
         | `relay.downstream_provider` is OPTIONAL and only used by `php artisan firefly:outbox:relay`,
         | which forwards committed outbox rows to a SECOND broker. It takes 'rabbitmq', 'kafka', an
         | EventPublisher class-string, or the id of your own binding. Leave it unset unless you run the
         | relay: provider=postgres already delivers rows in-process via `firefly:eda:consume`.
         |
         | Defaults: connection unset, channel 'firefly_eda_events', max_attempts 3,
         | relay.downstream_provider unset.
        */
        // 'postgres' => [
        //     'connection' => 'pgsql',
        //     'channel' => 'firefly_eda_events',
        //     'max_attempts' => 3,
        //     'relay' => [
        //         'downstream_provider' => 'rabbitmq',
        //     ],
        // ],

        /*
         | provider=kafka. Comma-separated broker list.
         |
         | Default: '127.0.0.1:9092'.
        */
        // 'kafka' => [
        //     'brokers' => env('KAFKA_BROKERS', '127.0.0.1:9092'),
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Messaging — firefly/messaging
    |--------------------------------------------------------------------------
    |
    | The point-to-point #[MessageListener] transport, independent of the event bus above.
    |   'memory' — in-process. The default.
    |   'queue'  — Laravel queue; both keys UNSET mean the application's own defaults.
    |
    */

    'messaging' => [
        'provider' => env('FIREFLY_MESSAGING_PROVIDER', 'memory'),

        // 'queue' => [
        //     'connection' => 'redis',
        //     'name' => 'messages',
        // ],
    ],

];
