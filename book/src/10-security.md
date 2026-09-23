<span class="eyebrow">Part III — Coordinating and Securing the Application · Chapter 10</span>

# Security: Authentication and Authorization {.chtitle}

By the end of this chapter you will know `firefly/security`'s immutable principal model (`Authentication`, `SecurityContext`, `SecurityContextHolder`), how `DaoAuthenticationProvider` authenticates a username/password pair while defeating enumeration attacks, how `JwtService` refuses to boot with a weak secret and refuses to accept a token with no expiry, the deny-by-default `HttpSecurity` URL DSL, and — the centrepiece of this chapter — exactly how `#[PreAuthorize]` is evaluated by a hand-rolled, **closed-whitelist** expression grammar that never calls `eval()`, is enforced at the CQRS bus you met in Chapter 7, and normalises `hasRole('X')` to a check against the granted authority `'ROLE_X'`.

!!! note "New term: authentication vs. authorization"
    **Authentication** answers "who is making this request?" — it produces a principal. **Authorization** answers "is that principal allowed to do *this specific thing*?" `firefly/security` keeps the two strictly separate: `Authentication`/`SecurityContextHolder` carry the answer to the first question; `HttpSecurity`, `#[PreAuthorize]`, and `AuthorizationChecker` all answer the second, by evaluating an expression **against** whatever the first question already settled.

---

## The principal model

`Authentication` is an immutable token built exclusively through two named factories, so the two states it can be in — an unauthenticated request still carrying raw credentials, and an authenticated principal carrying granted authorities — can never be confused:

<!-- source: packages/security/src/Core/Authentication.php -->
```php
final class Authentication
{
    // …
    private function __construct(
        public readonly string $name,
        public readonly mixed $principal,
        public readonly mixed $credentials,
        public readonly array $authorities,
        public readonly bool $authenticated,
        public readonly array $attributes,
    ) {}
    // …
    public static function authenticated(string $name, mixed $principal, array $authorities, array $attributes = []): self
    {
        return new self($name, $principal, null, $authorities, true, $attributes);
    }

    public static function unauthenticated(string $name, mixed $principal, mixed $credentials): self
    {
        return new self($name, $principal, $credentials, [], false, []);
    }
    // …
    public function eraseCredentials(): self
    {
        $principal = $this->principal instanceof CredentialsContainer ? $this->principal->eraseCredentials() : $this->principal;

        return new self($this->name, $principal, null, $this->authorities, $this->authenticated, $this->attributes);
    }
// …
}
```

The private constructor means the only way to build one is `authenticated()` (credentials always `null`, `authenticated` always `true`) or `unauthenticated()` (authorities always `[]`, `authenticated` always `false`) — there is no path that lets you construct an "authenticated" token with leftover raw credentials still attached.

`eraseCredentials()` is the same argument made once more, one level down, and its two lines are worth reading closely. It returns a **new** instance rather than clearing a field, so a reference someone else is already holding can never observe a token that was cleared and then repopulated. And it does not stop at its own `credentials`: a principal that is itself a `CredentialsContainer` — the shipped `User`, whose `getPassword()` is the encoded hash — is asked for its own credential-free copy, because a password hash reachable through `$authentication->getPrincipal()` is a password hash on the wire the moment something serialises the context. This is the form `SessionSecurityContextRepository` writes to the session. Notice what does *not* call it: the authentication manager, on success. The token a filter receives still carries the principal's encoded password, because the remember-me cookie's signature is computed from it — so only a **store** ever needs the erased copy. `GrantedAuthority`/`SimpleGrantedAuthority` wrap a bare authority string (`'ROLE_ADMIN'`, `'orders:read'`):

<!-- source: packages/security/src/Core/GrantedAuthority.php -->
```php
interface GrantedAuthority
{
    public function getAuthority(): string;
}
```

<!-- source: packages/security/src/Core/SimpleGrantedAuthority.php -->
```php
final readonly class SimpleGrantedAuthority implements GrantedAuthority
{
    public function __construct(private string $authority) {}

    public function getAuthority(): string
    {
        return $this->authority;
    }
}
```

`SecurityContext` is an immutable snapshot of the current `Authentication` (`anonymous()` is the unauthenticated zero-value), and `SecurityContextHolder` is where a request finds it:

<!-- source: packages/security/src/Core/SecurityContextHolder.php -->
```php
final class SecurityContextHolder
{
    private const KEY = 'firefly.security.context';

    public static function getContext(): SecurityContext
    {
        // …
        $context = Context::get(self::KEY);

        return $context instanceof SecurityContext ? $context : SecurityContext::anonymous();
    }

    public static function setContext(SecurityContext $context): void
    {
        Context::add(self::KEY, $context);
    }

    public static function clearContext(): void
    {
        Context::forget(self::KEY);
    }

    public static function getAuthentication(): ?Authentication
    {
        return self::getContext()->getAuthentication();
    }
}
```

It is `static` on purpose — Spring's own `SecurityContextHolder` shape — because it is a request-local accessor, not an injected collaborator, backed by Laravel's own `Context` facade (per-request, Octane-safe). `getContext()` never requires a null-check at the call site: an unset context resolves to `SecurityContext::anonymous()` automatically. The load-bearing guarantee against one request's principal leaking into the next is that every authentication filter calls `clearContext()` in a `finally` block on exit — PHP always runs a `finally`, so no exception path can skip it.

---

## Authenticating a request: `DaoAuthenticationProvider`

`DaoAuthenticationProvider` checks a username/password pair against a `UserDetailsService`, and it is written specifically to defeat username-enumeration timing attacks:

<!-- source: packages/security/src/Authentication/DaoAuthenticationProvider.php -->
```php
final class DaoAuthenticationProvider implements AuthenticationProvider
{
    // …
    private const DUMMY_PASSWORD = 'firefly-dummy-password-for-timing-mitigation';

    private readonly string $dummyHash;

    public function __construct(
        private readonly UserDetailsService $users,
        private readonly PasswordEncoder $encoder,
    ) {
        // Precompute with the REAL encoder (same algorithm/cost) so the user-not-found verify below is
        // …
        $this->dummyHash = $encoder->encode(self::DUMMY_PASSWORD);
    }
    // …
    public function authenticate(Authentication $authentication): Authentication
    {
        $raw = $authentication->getCredentials();
        $presented = is_string($raw) ? $raw : '';

        try {
            $user = $this->users->loadUserByUsername($authentication->getName());
        } catch (UsernameNotFoundException) {
            // Enumeration mitigation: a real verify against the dummy hash (equal timing) + the SAME generic 401
            // (identical message/body) as a bad password. Unknown user === wrong password to any observer.
            $this->encoder->matches($presented, $this->dummyHash);

            throw new BadCredentialsException('Bad credentials.');
        }

        if (! $this->encoder->matches($presented, $user->getPassword())) {
            throw new BadCredentialsException('Bad credentials.');
        }
        if (! $user->isAccountNonLocked()) {
            throw new LockedException('Account is locked.');
        }
        if (! $user->isEnabled()) {
            throw new DisabledException('Account is disabled.');
        }

        return Authentication::authenticated($user->getUsername(), $user, $user->getAuthorities());
    }
}
```

An unknown username is deliberately made **indistinguishable** from a wrong password: on `UsernameNotFoundException`, the provider still runs a real password verification — against a fixed dummy hash, precomputed at construction time with the *same* encoder and cost factor the real users use — before throwing the exact same generic `BadCredentialsException`, with the same message, that a genuine bad password produces. No timing difference, no content difference. Account-status failures (`locked`/`disabled`) are checked only **after** a correct password — the OWASP-recommended order — so an attacker without the right password learns nothing about whether the account even exists, let alone its state. On success, the returned token is freshly constructed via `Authentication::authenticated()`, which never carries the raw password forward at all.

---

## JWT: mandatory expiry, weak-secret boot refusal

`JwtService` enforces two fail-closed invariants that never require a developer to remember them:

<!-- source: packages/security/src/Jwt/JwtService.php -->
```php
final class JwtService
{
    private const MIN_SECRET_BYTES = 32;

    private const PLACEHOLDERS = ['changeme', 'change-me', 'secret', 'password', 'insecure', 'your-secret-key', 'null', ''];

    public function __construct(
        private readonly string $secret,
        private readonly string $algorithm = 'HS256',
        private readonly int $leewaySeconds = 0,
    ) {
        // …
        if (in_array(strtolower($secret), self::PLACEHOLDERS, true) || strlen($secret) < self::MIN_SECRET_BYTES) {
            throw new WeakSigningSecretException(
            // …
            );
        }
    }
    // …
    public function decode(string $token): array
    {
        JWT::$leeway = $this->leewaySeconds;

        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));
        } catch (ExpiredException $e) {
            throw new TokenExpiredException('JWT has expired.', 'TOKEN_EXPIRED', $e);
        } catch (SignatureInvalidException $e) {
            throw new InvalidTokenException('JWT signature is invalid.', 'INVALID_TOKEN', $e);
        // …
        }
        // …
        $claims = (array) $decoded;
        if (! array_key_exists('exp', $claims)) {
            throw new InvalidTokenException('JWT is missing the mandatory exp claim.');
        }

        return $claims;
    }
}
```

The first invariant fires at **construction**, not at first use: a secret shorter than 32 bytes, or one of a fixed list of common placeholders (`'changeme'`, `'secret'`, `'password'`, an empty string…), throws `WeakSigningSecretException` — the application refuses to boot at all rather than sign tokens with a guessable key. The second fires on every `decode()`: even a cryptographically valid signature is rejected outright if the token carries no `exp` claim, so a forgotten expiry can never produce an eternally valid token. `firefly.security.jwt.enabled` and `firefly.security.oauth2.resource_server.enabled` are additionally **mutually exclusive** — enabling both is refused at boot, matching Spring's one-bearer-mechanism model.

---

## Deny-by-default URL rules: `HttpSecurity`

`HttpSecurity` is a fluent DSL building an ordered list of URL rules, evaluated first-match-wins by `HttpSecurityFilter`, with **no match at all denying the request**:

<!-- source: packages/security/src/Access/HttpSecurity.php -->
```php
public function requestMatcher(string $pattern): self
{
    $this->pending = $pattern;

    return $this;
}

public function anyRequest(): self
{
    return $this->requestMatcher('*');
}

public function permitAll(): self
{
    return $this->finalise('permitAll()');
}

public function denyAll(): self
{
    return $this->finalise('denyAll()');
}

public function authenticated(): self
{
    return $this->finalise('isAuthenticated()');
}

public function hasRole(string $role): self
{
    return $this->finalise("hasRole('".self::assertSafeValue($role)."')");
}

public function hasAuthority(string $authority): self
{
    return $this->finalise("hasAuthority('".self::assertSafeValue($authority)."')");
}

public function hasScope(string $scope): self
{
    return $this->finalise("hasScope('".self::assertSafeValue($scope)."')");
}
```

Every rule compiles to the **exact same expression grammar** `#[PreAuthorize]` uses below — `HttpSecurity` is a builder that emits `permitAll()`/`hasRole('ADMIN')`-shaped strings, not a second authorization engine. `HttpSecurityFilter` evaluates the compiled rules against the request path and, on the first pattern match, checks the rule's expression; a request matching **no** rule at all is denied — fail-closed, not fail-open. A denial renders as a `401` when the context is anonymous (authenticate first) and a `403` when authenticated but under-privileged.

`assertSafeValue()` rejects any role/authority value containing a single quote, for a reason that matters a great deal once you've read the next section: a legitimate role or authority string never contains one, but a value that did could otherwise splice extra grammar into the fixed expression literal it gets interpolated into.

::: figure art/figures/security-filter-chain.svg | Figure 10.1 — HttpSecurityFilter is the last link of an ordered chain: every filter's real #[Order] value, the two framework filters prepended ahead of all of them, and the DelegatingAuthenticationEntryPoint an anonymous denial reaches — a login redirect, a Basic challenge or a 401.

Three of those numbers do one job between them. `OAuth2AuthorizationRequestRedirectFilter` (`-89`) starts a sign-in with a provider, `OAuth2LoginAuthenticationFilter` (`-88`) finishes it, and `OAuth2AuthorizationServerFilter` (`-82`) is what a request hits when *you* are the provider. The first two are `firefly/security-oauth2-client`, the third is `firefly/security-oauth2-server`, and neither package needs the other: either half works against any conformant counterpart on the far side.

Figure 10.2 follows one sign-in through both halves at once, because the thing worth understanding about the authorization-code grant is not any single step but which secret each party holds at which moment. Start at the top left with a rule you have already written: `GET /orders` matches no `permitAll()`, `HttpSecurityFilter` (`-70`) denies it, and the entry point does exactly what it does for form login — saves the GET and redirects to `/login`. What is different is that the login page now carries a button per registration, and that button goes to `/oauth2/authorization/{id}`. From there the browser never carries a secret again: the code it brings back is worthless without the PKCE verifier, which never left the relying party's session, and is spent in a back-channel `POST /oauth2/token` no browser ever sees.

::: figure art/figures/oauth2-authorization-code.svg | Figure 10.2 — One sign-in, end to end: HttpSecurityFilter denies the request and the entry point saves it and redirects to /login, the login page's provider button starts the round trip at /oauth2/authorization/{id} with a single-use state, a nonce and an S256 code challenge, the authorization server runs its own login and consent pages before minting a single-use code, and the relying party spends that code and the verifier in a back-channel token exchange whose id token is checked against /oauth2/jwks.

---

## Signing a person in: the session, the form and the page

Everything so far in this chapter authenticates a request that arrives carrying its own credential — a JWT, a Basic header. That is the right shape for an API and the wrong shape for a person, who signs in once and then browses. Three filters and one page make that work, and all three are on by one key.

### The context that survives a request

<!-- source: packages/security/src/Session/SecurityContextPersistenceFilter.php -->
```php
protected function doFilter(Request $request, Closure $next): mixed
{
    $entry = SecurityContextHolder::getContext();
    $loaded = null;

    if (! $entry->isAuthenticated()) {
        $loaded = $this->repository->load($request);
        if ($loaded !== null) {
            SecurityContextHolder::setContext($loaded);
        }
    }

    try {
        return $next($request);
    } finally {
        $current = SecurityContextHolder::getContext();
        if ($current->isAuthenticated() && $current !== $entry && $current !== $loaded) {
            $this->repository->save($current, $request);
        }
        SecurityContextHolder::clearContext();
    }
}
```

`SecurityContextPersistenceFilter` runs at `#[Order(-94)]` — right after the headers filter and **ahead of every authentication filter**, so a session-held principal is what `HttpSecurityFilter` and the method-security guards see. Twenty lines, and every one of them is deliberate:

- It does **not** load when the holder already carries an authenticated context. A test's acting principal, or an outer middleware, established one on purpose.
- On the way out it saves only a context that **changed** during the request and is **still on the holder**. It never deletes the stored one: an inner authentication filter that clears the holder in its own `finally` does so before this exit runs, and an empty holder at that point means "the request is over", not "sign out".
- The `finally` clear is the load-bearing Octane guarantee. Whatever happened, nothing bleeds into the next request on the same worker.

What is stored never carries a credential: a `CredentialsContainer` principal — the shipped `User` is one — is written without its encoded password.

!!! warning "Firefly filters are global; Laravel's session is not"
    Firefly filters are global kernel middleware, and Laravel starts the session in the `web` route group, *later*. When session security is on, a boot pass called `SessionSecurityBootstrap` pushes `EncryptCookies`, `AddQueuedCookiesToResponse` and `StartSession` onto the **global** stack ahead of the filter chain, removes them from the `web` group, and excludes them on the matched route at dispatch time. Without that, a route carrying the group would decrypt already-decrypted cookies, null them, and mint a fresh session — losing the login. A session driver is required; boot refuses without one.

### The form

`firefly.security.form_login.enabled` turns on two things at once. `FormLoginFilter` (`-92`) handles `POST /login` **before routing**, exactly as Spring's `UsernamePasswordAuthenticationFilter` does — so it needs no route, no CSRF group and no controller. Its order is: the session CSRF token first (a login CSRF is an attack, so this check is independent of `CsrfFilter`), then the `AuthenticationManager`, then, on success, a regenerated session id, the context stored, the events published, the remember-me cookie when it was asked for, and a redirect to whatever the entry point saved.

The capstone test walks that whole path, and it is the clearest description of the mechanism there is:

<!-- source: packages/security/tests/Web/Login/FormLoginFlowTest.php -->
```php
it('signs in with the right password: a NEW session id, the context in the session, the saved request honoured, the page then accessible', function () {
    // …
    // 1. Refused at /home → redirected to /login with /home saved.
    $refused = $this->get('/home');
    $refused->assertRedirect('/login');

    // 2. The login page, in that same session.
    $this->forgetSession();
    $page = $this->followSession($refused)->get('/login');
    $page->assertOk();
    $before = (string) $page->getCookie($this->sessionCookieName())?->getValue();

    // 3. The POST: right password → 302 to the SAVED request, with a regenerated session id.
    $this->forgetSession();
    $login = $this->followSession($page)->post('/login', ['username' => 'ada', 'password' => 'secret', '_token' => $this->csrfTokenFrom($page)]);
    $login->assertRedirect('/home');
    $after = (string) $login->getCookie($this->sessionCookieName())?->getValue();

    expect($after)->not->toBe($before)
        ->and($this->events->interactive())->toHaveCount(1)
        ->and($this->events->interactive()[0]->mechanism)->toBe(InteractiveAuthenticationSuccessEvent::FORM)
        ->and($this->events->interactive()[0]->authentication->getName())->toBe('ada')
        ->and($this->events->successes())->toHaveCount(1)
        ->and(SecurityContextHolder::getContext()->isAuthenticated())->toBeFalse();

    // 4. The protected page, from the session alone.
    $this->forgetSession();
    $this->followSession($login)->get('/home')->assertOk()->assertSee('Signed in as ada');
    $this->followSession($login)->getJson('/whoami')->assertJson(['name' => 'ada', 'authorities' => ['ROLE_USER']]);
```

The session id changes on every interactive sign-in — session-fixation protection, and it is not optional. A failure publishes an `AuthenticationFailure*Event` carrying the username and the source IP and **never the password**, then redirects to `/login?error`.

### The page the framework ships, and the one it refuses to replace

The framework renders its own login page, in the error page's design. It is a real class, `LoginPage`, mounted by a boot pass — and that boot pass carries the single most considerate rule in the package:

<!-- source: packages/security/src/Web/Login/LoginRouteRegistrar.php -->
```php
 * A LOGIN PAGE THE APPLICATION ALREADY OWNS IS LEFT ALONE. Laravel's RouteCollection keeps one route per
 * method and URI and the LAST registration wins, and this pass runs after the application's routes — so
 * mounting unconditionally would replace a Breeze/Fortify-style controller, or any #[GetMapping('/login')],
 * the moment `form_login.enabled` went on, with no log, no refusal and no failing test: the framework page
 * would simply appear where the application's used to be. Spring's rule is the one applied instead: a custom
 * login page belongs to the application (formLogin().loginPage() switches the default page generator off),
 * and the framework generates a page only when nobody else answers that address. So when a GET route at
 // …
```

So: if a `GET` route at the configured login path already exists when the pass runs — an attribute route, a routes file, Breeze, Fortify, anything — **nothing is mounted**. Everything else still works: `HttpSecurityFilter` permits the page by path (or the redirect would loop), the entry point redirects to it, and `FormLoginFilter` still answers the POST before routing. Your own form needs only to post the session token to the login processing URL.

Between those two extremes there is a middle option: point `view` at a Blade template of your own and the framework will render that instead, handing it a `LoginPageModel` with the form's fields and every provider link.

### Where the users come from

`AuthenticationManager` asks a `UserDetailsService`, and the one most applications want reads their own table:

<!-- source: packages/security/src/User/EloquentUserDetailsService.php -->
```php
 * The expected schema (every name configurable under firefly.security.users):
 *   email       the username (unique)
 *   password    the ENCODED password, `{id}`-prefixed for the delegating encoder (`{bcrypt}$2y$…`)
 *   enabled     boolean, optional (enabled_column; empty means every account is enabled)
 *   locked      boolean, optional (locked_column; empty means no account is locked)
 *   authorities a JSON list of strings — `["ROLE_USER", "orders:read"]` — or a relation (`roles.name`);
 *               optional (authorities; empty means the model carries none and every account gets [])
```

One query per lookup, and the row is mapped to the immutable `User` value object rather than handed out as the model — the principal ends up in the session and inside event objects, and a model there would drag its connection, its relations and its attributes along with it.

One detail is worth more than it looks. A configured column name the row does not carry is **refused**, not read as null. Laravel's `Model::getAttribute()` answers `null` for an attribute a row does not have, and null is the wrong answer in every position here — for the locked column it is the dangerous one, because `locked_column: 'is_locked'` against a table whose column is `locked` would make every row unlocked and switch account lockout off with no error anywhere. Opting out of an optional column is the **empty string**, which a typo never is.

Laravel's stock `users` table (`id`, `name`, `email`, `password`) works with no schema change at all: set `authorities` to `''`.

---

## Logging out, and staying logged in

### Logging out

<!-- source: packages/security/tests/Web/Logout/LogoutFlowTest.php -->
```php
it('ends the session on POST /logout, expires the named cookies, publishes the event, and lands on ?logout', function () {
    // …
    $logout = $this->followSession($login)->withCookie('theme', 'dark')->post('/logout', ['_token' => $this->csrfTokenFrom($this->followSession($login)->get('/login'))]);
    $logout->assertRedirect('/login?logout');

    $theme = $logout->getCookie('theme');
    expect($theme)->not->toBeNull()
        ->and($theme?->getExpiresTime())->toBeLessThan(time())
        ->and($this->events->logouts())->toHaveCount(1)
        ->and($this->events->logouts()[0]->authentication?->getName())->toBe('ada');

    // The OLD session is gone: its cookie no longer authenticates anyone.
    $this->forgetSession();
    $this->followSession($login)->getJson('/whoami')->assertStatus(401);
    $this->followSession($login)->get('/home')->assertRedirect('/login');
});
```

`LogoutFilter` (`-93`) handles `POST /logout` — **POST only, CSRF-checked**; a `GET` falls through to whatever route is there, which is what you want, because a link that logs people out is a CSRF vector. It expires the remember-me cookie and every name listed in `delete_cookies`, invalidates the session, publishes `LogoutSuccessEvent`, and redirects.

That sequence lives in one bean, `LogoutHandler`, and **every path that ends a session calls it** — the filter above, and OpenID Connect RP-initiated logout when the authorization-server package is on. So the three settings that govern signing out govern both by construction; a second path cannot quietly obey a subset of them.

### Staying logged in

`TokenBasedRememberMeServices` is Spring's, including the signature:

```
username : expiry : HMAC-SHA256(username : expiry : password-hash, key)
```

That the **password hash** is inside the signature is the whole design. Change the password and every outstanding cookie stops verifying, everywhere, with no server-side token table to clean up. The key is held to the same rule as the JWT secret and the boot refuses a weak one.

<!-- source: packages/security/tests/Web/RememberMe/RememberMeFlowTest.php -->
```php
    // The session is gone; the cookie ALONE signs ada back in — through the remember-me mechanism.
    $this->destroySessionOf($remembered);
    $this->forgetCookies();
    $this->events->reset();
    $back = $this->withCookie('remember-me', $token)->get('/home');
    $back->assertOk()->assertSee('Signed in as ada');

    expect($this->events->interactive())->toHaveCount(1)
        ->and($this->events->interactive()[0]->mechanism)->toBe(InteractiveAuthenticationSuccessEvent::REMEMBER_ME);

    // And the NEW session it opened carries ada from now on, without the cookie.
    $this->forgetSession();
    $this->followSession($back)->getJson('/whoami')->assertJson(['name' => 'ada']);

    // A tampered cookie is anonymous, not an error.
    $this->forgetSession();
    $this->forgetCookies();
    $this->withCookie('remember-me', strrev($token))->get('/home')->assertRedirect('/login');
```

`RememberMeAuthenticationFilter` (`-83`) only runs when the session holds no principal, and when it succeeds it performs a **full interactive sign-in**: a new session id, the context stored, an `InteractiveAuthenticationSuccessEvent`. So the cookie is consulted once per *session*, not once per request. A tampered cookie is anonymous rather than an error — there is nothing to report, because anyone can send bytes.

A disabled or locked account is refused even on a genuine cookie, and logout expires it.

!!! warning "Remembered is not the same as authenticated"
    A cookie sign-in is **marked remembered**. It is evidence that this browser signed in at some point in the past, not evidence that the person is at the keyboard now. Spring draws exactly this line, and it is why a "change password" or "delete account" page should require a fresh authentication rather than accepting a remembered one.

---

## Redirect or 401: the entry point decides

One deny-by-default rule has to produce two completely different responses. A person browsing to `/orders` should end up at a login page. A JavaScript client calling `/api/orders` should get a 401 it can act on — and a login page rendered into a `fetch()` is the single most confusing failure mode a web framework can produce.

`HttpSecurityFilter` hands every **unauthenticated** denial to the `AuthenticationEntryPoint` named by `firefly.security.http.entry_point`:

| Mode | What an anonymous denial gets |
|---|---|
| `auto` (the default) | a browser gets the login page with the request saved; a Basic-enabled app answers `401` + `WWW-Authenticate: Basic`; otherwise a 401 for firefly/web to render |
| `login` | always the login-page redirect — **refused at boot** when no login is enabled, because there would be nowhere to send anyone |
| `challenge` | always `401` + `WWW-Authenticate: Basic` |
| `problem` | always the 401, as `application/problem+json` or the HTML 401 page |

"A browser" is not a guess about the `User-Agent`. It is the error page's own content negotiation, `ErrorPageRenderer::prefersHtml()`: the request names `text/html` (or `application/xhtml+xml`), is neither an `XMLHttpRequest` nor a `wantsJson()` call, and is not under `firefly.web.error-page.json-paths`. The same test the error page uses, reused rather than re-invented — so the two can never disagree:

<!-- source: packages/security/tests/Web/EntryPoint/EntryPointFlowTest.php -->
```php
it('redirects a browser to the login page and answers a JSON client with a 401 problem', function () {
    // …
    $this->get('/home')->assertRedirect('/login');

    $this->getJson('/api/orders')
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson(['code' => 'AUTHENTICATION_FAILED']);

    // An api/* path is a machine surface whatever the Accept header says (firefly.web.error-page.json-paths).
    $this->get('/api/orders', ['Accept' => 'text/html'])->assertStatus(401);

    expect($this->events->denials())->toBe([]);
});
```

Read the last line. An **anonymous** denial publishes nothing — it is not a security event, it is a visitor who has not signed in yet, and a log full of them is noise that hides the real thing. An **authenticated** denial is the real thing: it stays a 403 and it publishes `AuthorizationDeniedEvent` naming the principal, the subject and the expression that refused it:

<!-- source: packages/security/tests/Web/EntryPoint/EntryPointFlowTest.php -->
```php
    $this->followSession($signedIn)->getJson('/admin/panel')->assertStatus(403)->assertJson(['code' => 'ACCESS_DENIED']);

    expect($this->events->denials())->toHaveCount(1)
        ->and($this->events->denials()[0]->authentication->getName())->toBe('ada')
        ->and($this->events->denials()[0]->subject)->toBe('GET /admin/panel')
        ->and($this->events->denials()[0]->expression)->toBe("hasRole('ADMIN')");
```

Two callers reach the entry point: `HttpSecurityFilter`'s anonymous denial, and the authorization server's `/oauth2/authorize` when it needs the person signed in first. `HttpBasicFilter` deliberately does **not** — it answers with its own `BasicAuthenticationEntryPoint` whatever `entry_point` says, exactly as Spring's `BasicAuthenticationFilter` keeps its own. A caller that presented Basic credentials has already chosen its mechanism, and redirecting it to an HTML page is the wrong answer to an API client.

---

## Method security: the closed-whitelist expression grammar

This is the section the rest of the chapter has been building toward. `#[PreAuthorize]`, `#[Secured]`, and `#[RolesAllowed]` all guard a method with a boolean expression:

<!-- source: packages/security/src/Access/Attributes/PreAuthorize.php -->
```php
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class PreAuthorize
{
    /**
     * @param  ?string  $code  the error code a refusal carries instead of ACCESS_DENIED
     * @param  ?string  $message  the sentence a refusal carries instead of the framework's
     */
    public function __construct(
        public string $expression,
        public ?string $code = null,
        public ?string $message = null,
    ) {}
}
```

<!-- source: packages/security/src/Access/Attributes/Secured.php -->
```php
/** Requires ANY of the listed authorities (JSR-250 / Spring @Secured). Compiled to hasAnyAuthority(...). */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class Secured
{
    /** @var list<string> */
    public array $authorities;

    public function __construct(string ...$authorities)
    {
        $this->authorities = array_values($authorities);
    }
}
```

<!-- source: packages/security/src/Access/Attributes/RolesAllowed.php -->
```php
/** Requires ANY of the listed roles (JSR-250 @RolesAllowed). Compiled to hasAnyRole(...). */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class RolesAllowed
{
    /** @var list<string> */
    public array $roles;

    public function __construct(string ...$roles)
    {
        $this->roles = array_values($roles);
    }
}
```

`#[PreAuthorize]`'s `code:` and `message:` are what the **client** reads when the expression refuses. Without them the guard answers `403 ACCESS_DENIED` with the framework's own sentence and the authorities the expression named; with them it answers the application's product code and wording. `#[Secured]` and `#[RolesAllowed]` have no slot for words and always use the framework's.

`#[Secured]`/`#[RolesAllowed]` are convenience sugar `MethodSecurityScanner` compiles down to a `hasAnyAuthority(...)`/`hasAnyRole(...)` expression — everything ultimately reduces to the one grammar `#[PreAuthorize]` writes directly. And that grammar is deliberately, provably closed: `SecurityExpressionEvaluator` is a hand-written tokenizer plus a recursive-descent parser, and it **never** calls `eval()`, `create_function()`, or `call_user_func()` on anything derived from the expression string. Function dispatch is a hard-coded `match`:

<!-- source: packages/security/src/Access/Expression/SecurityExpressionEvaluator.php -->
```php
final class SecurityExpressionEvaluator
{
    // …
    private function dispatch(string $name, array $args): bool
    {
        // parse()-only mode has no root: validate the whitelist but return a placeholder bool.
        $root = $this->root;
        if ($root === null) {
            return match ($name) {
                'hasRole', 'hasAnyRole', 'hasAuthority', 'hasAnyAuthority', 'hasScope', 'hasAnyScope', 'hasPermission',
                'isAuthenticated', 'permitAll', 'denyAll' => true,
                default => throw new ExpressionParseException("Unknown function '{$name}'."),
            };
        }

        return match ($name) {
            'hasRole' => $root->hasRole($this->str($args, 0, $name)),
            'hasAnyRole' => $root->hasAnyRole(...$this->strings($args, $name)),
            'hasAuthority' => $root->hasAuthority($this->str($args, 0, $name)),
            'hasAnyAuthority' => $root->hasAnyAuthority(...$this->strings($args, $name)),
            'hasScope' => $root->hasScope($this->str($args, 0, $name)),
            'hasAnyScope' => $root->hasAnyScope(...$this->strings($args, $name)),
            'hasPermission' => $root->hasPermission($args[0] ?? null, $this->str($args, 1, $name)),
            'isAuthenticated' => $root->isAuthenticated(),
            'permitAll' => $root->permitAll(),
            'denyAll' => $root->denyAll(),
            default => throw new ExpressionParseException("Unknown function '{$name}'."),
        };
    }
// …
}
```

Ten function names, total — `hasRole`, `hasAnyRole`, `hasAuthority`, `hasAnyAuthority`, `hasScope`, `hasAnyScope`, `hasPermission`, `isAuthenticated`, `permitAll`, `denyAll`, plus `#param` references — and nothing else is reachable. The same ten are spelled twice on purpose: the first `match` is the whitelist tokenizer's parse-only mode, which `HttpSecurity` and `MethodSecurityScanner` use to reject a bad expression at BOOT rather than on the request that happens to hit it, and the second is the evaluation proper. The grammar the tokenizer accepts is boolean `and`/`or`/`not` (spelled `&&`/`||`/`!` at the character level), parentheses, string literals in single quotes, and `#identifier` parameter references — and nothing else:

<!-- source: packages/security/src/Access/Expression/SecurityExpressionEvaluator.php -->
```php
final class SecurityExpressionEvaluator
{
    // …
    public function evaluate(string $expression, SecurityExpressionRoot $root): bool
    {
        // …
        try {
            $this->tokens = $this->tokenize($expression);
            $this->pos = 0;
            $this->root = $root;
            $result = $this->parseExpression();
            $this->expect('eof');

            return $result;
        } catch (\Throwable) {
            // …
            return false;
        // …
        }
    }
// …
}
```

!!! warning "What is NOT in the grammar"
    There is no `==`, no `!=`, no comparison operator of any kind, and no `.property` navigation. You cannot write `#command.ownerId == authentication.name` — the tokenizer has no notion of a dot-access or an equality operator at all, so that text either fails to tokenize or fails to parse, and either way `evaluate()` catches the `ExpressionParseException` and returns `false`. This is not an oversight; it is what makes the grammar provably closed. If a comparison like that seems like exactly what you need, the intended escape hatch is `hasPermission(#target, 'action')` backed by a custom `PermissionEvaluator` bean that does the actual field comparison in real PHP code — the grammar routes to your logic, it never becomes a general-purpose expression language itself.

    Every failure mode — an unknown function name, a stray character, a malformed string literal, an exception thrown by a custom `PermissionEvaluator` mid-evaluation — is caught by the same `catch (\Throwable)` and turned into `false`. A hostile or broken expression can only ever deny; it can never accidentally grant.

`SecurityExpressionRoot` is the **only** object an expression's function calls can ever reach — there is no way to call anything else, because there is no property-access syntax and no way to obtain a reference to any other object:

<!-- source: packages/security/src/Access/Expression/SecurityExpressionRoot.php -->
```php
final class SecurityExpressionRoot
{
    // …
    public function hasRole(string $role): bool
    {
        return $this->hasAuthority(str_starts_with($role, 'ROLE_') ? $role : 'ROLE_'.$role);
    }
    // …
    public function hasAuthority(string $authority): bool
    {
        return in_array($authority, $this->reachable, true);
    }
    // …
    public function arg(string $name): mixed
    {
        return $this->args[$name] ?? null;
    }
}
```

`hasRole('ADMIN')` and `hasRole('ROLE_ADMIN')` are equivalent — a bare role name is normalised by prepending `ROLE_` if it isn't already there, exactly matching Spring's own convention. `#param` — tokenized as a bare `#` followed by an identifier — resolves through `arg()` against the guarded method's own parameter names, bound positionally by `MethodSecurityScanner` at scan time.

Here is the whole point made concrete, in real, shipped code — `WithdrawHandler`, real, from `samples/lumen/src/Application/Command/WithdrawHandler.php`:

<!-- source: samples/lumen/src/Application/Command/WithdrawHandler.php -->
```php
<?php

declare(strict_types=1);

namespace Lumen\Application\Command;

use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Security\Access\Attributes\PreAuthorize;
use Lumen\Domain\Money;
use Lumen\Infrastructure\WalletRepository;

/**
 * Handles Withdraw: loads the aggregate, debits the amount (the domain enforces no-overdraw — an over-balance debit
 * raises a ConflictException that rolls the transaction back and surfaces as a CommandProcessingException), persists,
 * and returns the new balance in minor units. #[Transactional] for the same reason as the sibling handlers.
 *
 * Guarded by #[PreAuthorize]: only an ADMIN or the WALLET_OWNER may debit. SecurityExpressionEvaluator is a CLOSED
 * whitelist (booleans and/or/not, string literals, #param, and eight fixed functions — no `==`, no `.property`
 * navigation), so ownership is modelled as a granted WALLET_OWNER authority rather than an `#command.ownerId ==
 * authentication.name` comparison, which the parser cannot express. SecurityCommandAuthorizer enforces this at the bus
 // …
 * (The faithful owner-only alternative — hasPermission(#command, 'withdraw') backed by a custom PermissionEvaluator
 * bean that loads the wallet and compares its owner_id to the current principal — is left to a later step.)
 *
 * Intentionally NOT final: the generated transactional proxy subclasses this handler.
 */
#[CommandHandler]
class WithdrawHandler
{
    public function __construct(private readonly WalletRepository $wallets) {}

    #[PreAuthorize("hasRole('ADMIN') or hasRole('WALLET_OWNER')")]
    #[Transactional]
    public function handle(Withdraw $command): int
    {
        $wallet = $this->wallets->findById($command->walletId)
            ?? throw new ResourceNotFoundException("Wallet [{$command->walletId}] not found");

        $wallet->withdraw(new Money($command->amountMinor, $wallet->currency()));
        $this->wallets->save($wallet);

        return $wallet->balanceMoney()->minorUnits;
    }
}
```

Read that docblock's second paragraph carefully — it is the whole lesson in miniature. The *naive* way to express "only the wallet's own owner may withdraw" would be something like `#command.ownerId == authentication.name`, and the grammar simply cannot say that: there is no `.` operator and no `==`. The real, shipped alternative is coarser but genuinely expressible: grant a `WALLET_OWNER` authority to whoever should be allowed, and check `hasRole('WALLET_OWNER')`. A true per-resource ownership check, when you need one, routes through `hasPermission(#command, 'withdraw')` and a custom `PermissionEvaluator` bean that does the actual comparison in ordinary PHP — the grammar dispatches to your code, it does not try to become expressive enough to write the comparison itself.

---

## Enforcement at the bus: closing Chapter 7's `AllowAllAuthorizer` loop

Chapter 7 showed you `CommandBus`/`QueryBus`'s pipeline running an `authorize()` stage that defaults to `AllowAllAuthorizer` — allow everything. `firefly/security` is what actually replaces it, and does so only when you opt in: `SecurityAutoConfiguration` is `#[Order(500)]`, strictly below `CqrsAutoConfiguration`'s `#[Order(1000)]`, so — the same win-the-race pattern Chapter 8 showed you for the Postgres outbox at `#[Order(900)]` — its beans register first and satisfy `CqrsAutoConfiguration`'s own `#[ConditionalOnMissingBean(CommandAuthorizer::class)]` before the allow-all default ever gets a chance to bind, but **only** when `firefly.security.enabled=true` (every security bean here is additionally gated on that master flag):

<!-- source: packages/security/src/Cqrs/SecurityCommandAuthorizer.php -->
```php
final class SecurityCommandAuthorizer implements CommandAuthorizer
{
    public function __construct(private readonly MethodSecurityMessageEnforcer $enforcer) {}

    public function authorize(object $command): void
    {
        $this->enforcer->enforce($command, HandlerKind::Command);
    }
}
```

`MethodSecurityMessageEnforcer` is the shared join both `SecurityCommandAuthorizer` and its query-side twin `SecurityQueryAuthorizer` delegate to. It resolves the message's handler class and method from the **same `HandlerManifest`** Chapter 7 introduced, looks up any compiled `#[PreAuthorize]`/`#[Secured]`/`#[RolesAllowed]` rule for that `Class::method`, and — only if one exists — evaluates it:

<!-- source: packages/security/src/Cqrs/MethodSecurityMessageEnforcer.php -->
```php
public function enforce(object $message, HandlerKind $kind): void
{
    if (! $this->config->bool('firefly.security.enabled', false)) {
        return;
    }

    $handler = $this->handlerByMessage[$kind->value.':'.$message::class] ?? null;
    if ($handler === null) {
        return;
    }

    $rule = $this->methods->ruleFor($handler['class'], $handler['method']);
    if ($rule === null) {
        return;
    }

    $this->evaluator->before($rule, isset($rule->params[0]) ? [$rule->params[0] => $message] : []);
}
```


Read it as four early returns and one line of work. Security off, no registered handler for this message, or no compiled rule for that handler — each passes through unchecked, because method security here is **additive**, never a second deny-by-default gate (that job belongs entirely to `HttpSecurityFilter`). When a rule *is* found, the last line hands it to `MethodSecurityEvaluator::before()`, the single evaluator every enforcement seam shares, with the message itself bound to the rule's one `#param`.

So when `WithdrawHandler::handle()` carries a rule, this is what runs it, **before** `handle()` itself ever executes. A refusal becomes an `AuthorizationException`, which `DefaultCommandBus::send()` (Chapter 7) wraps in `CommandProcessingException`, exactly like any other handler-side fault.

---

## Method security everywhere, not just at the bus

The bus was the *first* place these rules were enforced. It is no longer the only one, and the difference matters: a `#[PreAuthorize]` on a plain `#[Service]` used to be a comment.

There are now three enforcement seams, and all three share one `MethodSecurityEvaluator`, so they cannot disagree about what an expression means:

| Seam | Guards | Runs |
|---|---|---|
| The CQRS bus | a command or query handler | before `handle()`, via `SecurityCommandAuthorizer`/`SecurityQueryAuthorizer` |
| The controller dispatcher | a controller action | `ControllerSecurityGuard::check()` before, `afterInvocation()` after |
| **The proxy chain** | **any stereotyped bean** — `#[Service]`, `#[Component]`, `#[Repository]` | as the outermost interceptor on the generated proxy |

The third is the new one, and it is not a new mechanism: it is Chapter 9's proxy, with a second kind of advice on it. `MethodSecurityAdviceSource` contributes its rows to the same `ProxyPlan`, and declares where in the chain its interceptor runs:

<!-- source: packages/security/src/Access/Method/MethodSecurityAdviceSource.php -->
```php
public function advice(): Advice
{
    // inertWhenUnbound: the interceptor bean exists only under the master flag, and its absence is the
    // documented "annotations are inert until security is enabled" state, not a misconfiguration — so the
    // InterceptorRegistry may hand the proxy a PassThroughInterceptor instead of refusing to boot.
    return new Advice(self::ID, MethodSecurityInterceptor::class, SecurityMethodDescriptor::class, 100, inertWhenUnbound: true);
}
```

**Order `100`, against the transactional advice's `1000`.** Lower runs outer, so security refuses *before* a transaction is ever opened — which is the only sensible order, because opening and rolling back a transaction to discover the caller was never allowed is work done for nothing.

`inertWhenUnbound` is the other half of the same thought. `MethodSecurityInterceptor` only exists as a bean under `firefly.security.enabled`, and its absence is the framework's documented "annotations are inert until security is on" state rather than a misconfiguration — so the registry hands the proxy a `PassThroughInterceptor` rather than failing the boot, which is what it does for any *other* advice whose interceptor is missing.

### The rules that need the returned value

Once the seam can run *after* the call, two more attributes become possible — and this is exactly what the bus could never do:

<!-- source: packages/security/tests/Fixtures/Advice/ReportService.php -->
```php
#[Service]
class ReportService
{
    /** @return array<string, int> */
    #[PreAuthorize("hasRole('ADMIN')")]
    public function totals(): array
    {
        return ['total' => 42];
    }

    #[PostAuthorize("hasPermission(#returnObject, 'READ')", code: 'REPORT_NOT_YOURS', message: 'That report belongs to someone else.')]
    public function find(int $id): Report
    {
        return new Report($id, $id % 2 === 0 ? 'ada' : 'bob');
    }

    /** @return list<Report> */
    #[PostFilter("hasPermission(#filterObject, 'READ')")]
    public function all(): array
    {
        return [new Report(1, 'bob'), new Report(2, 'ada'), new Report(3, 'bob'), new Report(4, 'ada')];
    }
```

- `#[PostAuthorize]` runs after `proceed()` with **`#returnObject` bound to what the method returned**. "You may call this, but only if what came back is yours" is a rule you cannot express before the call, because the answer is what decides it.
- `#[PostFilter]` runs over an iterable result with **`#filterObject` bound to each element**, keeping the ones the expression accepts. Arrays keep their keys; an Enumerable is filtered in kind; a non-iterable return is refused rather than quietly passed through.
- `#[PreFilter]` is the mirror image on the way in, naming which parameter it filters with `filterTarget:`.

Notice `code:` and `message:` on the `#[PostAuthorize]`. A refusal's product code and the sentence a person reads live on the same line as the rule that produced them, beside the method they guard — rather than in some service the caller has to remember to invoke first. Without them the framework's own wording applies: a 403 reading *"You do not have permission to do this."*, with the authorities the rule asked for carried as an RFC 9457 extension member, and the guarded class and method sent to the **log** rather than to the wire, because a PHP class name is not something a stranger should read on a panel.

!!! warning "Two boot refusals you want, and one you must opt into"
    A `final` bean carrying rules only a proxy could enforce is refused at scan time — the proxy cannot extend it, so compiling the row would produce a rule nothing runs. And `SecurityWiringPass` refuses to boot over a `security-methods.php` written without a `proxy-plan.php` beside it: the rules would compile and the bean-level ones would silently not be enforced. The one you must ask for is `firefly.security.method.strict`, which turns "no compiled artifact found" from a fallback into a startup failure. Set it in any image that runs `firefly:cache`; it is the only defence against a build that ships without the manifest.

Method security remains **additive**. "No rule recorded for this method" means allow, at every one of the three seams — deny-by-default is `HttpSecurityFilter`'s job and nothing else's. That is the right semantics and it has a sharp edge, which is what `strict` exists for: an empty manifest is indistinguishable from an application that declares no rules.

---

## The principal, injected

A controller that needs to know who is calling should say so in its signature. It should not reach into a holder, and it should certainly not receive a `principal` that a query string could set.

<!-- source: packages/security/tests/Fixtures/Principal/ProfileController.php -->
```php
#[GetMapping('/profile')]
public function profile(Authentication $auth, ?UserDetails $user, #[AuthenticationPrincipal] mixed $principal, #[CurrentSecurityContext] SecurityContext $context): array
{
    return [
        'name' => $auth->getName(),
        'authorities' => $auth->authorityStrings(),
        'user' => $user?->getUsername(),
        'principal' => is_string($principal) ? $principal : ($principal instanceof UserDetails ? 'details:'.$principal->getUsername() : get_debug_type($principal)),
        'authenticated' => $context->isAuthenticated(),
    ];
}
```

Four parameters, four ways of asking, and `firefly/security` binds all of them before the container is ever consulted — because of a port `firefly/web` exposes for exactly this:

<!-- source: packages/web/src/Dispatch/HandlerMethodArgumentResolver.php -->
```php
interface HandlerMethodArgumentResolver
{
    /**
     * @param  array<string, mixed>  $binding
     */
    public function supports(array $binding): bool;

    /**
     * @param  array<string, mixed>  $binding
     */
    public function resolve(array $binding, Request $request): mixed;
}
```

A resolver sees the compiled binding plan — the parameter's name, kind, type, attributes and nullability — and claims it with `supports()`. `ArgumentResolver` asks the registered resolvers **before** its own kinds, so a class-typed parameter a resolver understands never reaches `$container->make()`. `SecurityArgumentResolver` claims `Authentication`, `UserDetails`, `#[AuthenticationPrincipal]` and `#[CurrentSecurityContext]`.

The nullability rules are worth stating precisely, because they are what let one action serve two mechanisms:

| Declaration | Signed in | Anonymous |
|---|---|---|
| `Authentication $auth` | the authentication | **401** |
| `?Authentication $auth` | the authentication | `null` |
| `?UserDetails $user` | the principal when it *is* one | `null` |
| `#[AuthenticationPrincipal] mixed $principal` | whatever the principal is | the anonymous principal |
| `#[CurrentSecurityContext] SecurityContext $context` | the context | the anonymous context |

An **attributed** principal is handed over only when it *is* what the parameter declares, and `null` otherwise. That is what stops a `TypeError` — a JWT's principal is its `sub` **string**, not a `UserDetails` — and it is what lets the same action serve a form login and a bearer token:

<!-- source: packages/security/tests/Web/Argument/PrincipalInjectionFlowTest.php -->
```php
    // A JWT's principal is its `sub` string, not a UserDetails: `#[AuthenticationPrincipal] ?UserDetails` is the
    // null it allowed for, not the string a `?UserDetails` parameter would refuse with a TypeError (a 500).
    $this->withHeader('Authorization', $this->bearerFor('svc-42'))->getJson('/open/principal-user')->assertOk()->assertJson(['user' => null]);
```

Two more facts, both defensive. The resolver is registered **whether or not the master flag is on**, so with security off the annotations are inert — a `null`, an anonymous context, an honest 401 — rather than being misread as query parameters by the ordinary binding plan. And nothing here ever reads a principal from the request:

<!-- source: packages/security/tests/Web/Argument/PrincipalInjectionFlowTest.php -->
```php
it('never reads a principal from the query string, whoever asks', function () {
    // …
    $this->getJson('/open/whoami?principal=admin')->assertOk()->assertJson(['principal' => null]);
```

---

## The events

Every mechanism in this chapter reports what it did, through `AuthenticationEventPublisher` over the context's ordinary `ApplicationEventPublisher`. So an `#[AsEventListener]` method receives them exactly like any other application event from Chapter 8, and `Event::fake()` sees them in a test.

The family is small and complete — this is every class in `packages/security/src/Event/`, and there are no others:

| Event | Published when |
|---|---|
| `AuthenticationSuccessEvent` | any successful authentication, interactive or not |
| `InteractiveAuthenticationSuccessEvent` | a person signed in: `form`, `basic` or `remember-me` |
| `AuthenticationFailureBadCredentialsEvent` | a wrong password — **and an unknown username** |
| `AuthenticationFailureLockedEvent` | the credentials were right, the account is locked |
| `AuthenticationFailureDisabledEvent` | the credentials were right, the account is disabled |
| `LogoutSuccessEvent` | a session ended through `LogoutHandler` |
| `AuthorizationDeniedEvent` | a URL rule or a method rule refused an **authenticated** principal |

Three of those rows carry a decision rather than a fact.

**An unknown user is reported as bad credentials, on purpose.** A distinct "no such user" event would be a username oracle, and the whole point of `DaoAuthenticationProvider` equalising the two paths — the same timing, the same answer — would be undone by the very listener written to monitor it.

**A locked or disabled account is only reported after the right password.** Otherwise the events would tell an attacker which accounts exist, which is the same oracle by a slower route.

**`AuthorizationDeniedEvent` fires only for someone who is signed in.** An anonymous denial is a visitor who has not logged in yet, published nowhere; an authenticated denial is a person who tried something they are not allowed to do, and it arrives with the principal, the subject (`GET /admin/users`, or `App\Reports::totals`) and the expression that refused them.

What none of them carry is a credential. A failure event names the username and the source IP; the password is not in it, and the abstract base class the three failures share has no slot for one.

!!! tip "The three listeners worth writing on day one"
    Count `AuthenticationFailureBadCredentialsEvent` per source IP and you have brute-force detection. Log `InteractiveAuthenticationSuccessEvent` with its `mechanism` and you have a sign-in audit that distinguishes a password from a cookie. Alert on `AuthorizationDeniedEvent` and you are watching the only denial that means somebody who *is* known tried something they should not have.

---

## Proof end-to-end

Two real, shipped test files exercise the entire chain — HTTP request in, `SecurityCommandAuthorizer` enforcing `WithdrawHandler`'s `#[PreAuthorize]` at the bus, out to an RFC-9457 response. And the first thing they prove is that **a denial is not one answer but two.**

`samples/lumen/tests/Web/WalletRestTest.php`, with no principal set at all:

<!-- source: samples/lumen/tests/Web/WalletRestTest.php -->
```php
    $this->postJson("/api/v1/wallets/{$id}/withdraw", ['amount_minor' => 1000])
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 401)
        ->assertJsonPath('code', 'AUTHENTICATION_FAILED')
        ->assertJsonPath('category', 'security');

    // Proof the refusal happened BEFORE the handler touched the balance.
    $this->getJson("/api/v1/wallets/{$id}/balance")->assertJson(['balance_minor' => 5000]);
```

**401, not 403** — and the distinction is the whole point. `MethodSecurityEvaluator` answers an **anonymous** caller with an `AuthenticationException`: *authenticate first*. It reserves 403 for a caller who *is* signed in and still lacks the authority, which is the next test in the same file:

<!-- source: samples/lumen/tests/Web/WalletRestTest.php -->
```php
    $this->postJson("/api/v1/wallets/{$id}/withdraw", ['amount_minor' => 1000])
        ->assertStatus(403)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 403)
        ->assertJsonPath('code', 'ACCESS_DENIED')
        ->assertJsonPath('category', 'security');
```

The three enforcement seams give the same pair of answers, because they share the same evaluator: 401 means *signing in would help*, 403 means *it would not*. A client can tell which, and act accordingly.

The bus wraps either one in `CommandProcessingException`, which copies the cause's error code, HTTP status and category — so the wire carries `AUTHENTICATION_FAILED` or `ACCESS_DENIED` rather than the bus's own generic code. And in both tests the balance assertion afterwards is doing real work: it proves the debit genuinely never happened, not merely that the HTTP response looked like a rejection.

Grant the right authority and the same command succeeds:

<!-- source: samples/lumen/tests/Web/WalletRestTest.php -->
```php
it('allows an authorized withdraw and renders an overdraw as 409 problem+json', function () {
    // …
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('owner-4', 'owner-4', [new SimpleGrantedAuthority('ROLE_WALLET_OWNER')])
    ));
    // …
    $id = $this->postJson('/api/v1/wallets', ['owner_id' => 'owner-4', 'currency' => 'EUR'])->json('wallet_id');
    $this->postJson("/api/v1/wallets/{$id}/deposit", ['amount_minor' => 3000]);

    // Authorized + within balance: succeeds and reflects the new balance.
    $this->postJson("/api/v1/wallets/{$id}/withdraw", ['amount_minor' => 1000])
        ->assertStatus(200)
        ->assertJson(['wallet_id' => $id, 'balance_minor' => 2000]);
    // …
    $this->postJson("/api/v1/wallets/{$id}/withdraw", ['amount_minor' => 999999])
        ->assertStatus(409)
        // …
        ->assertJsonPath('category', 'business');
```

The granted authority is `'ROLE_WALLET_OWNER'` — already carrying the `ROLE_` prefix — and `WithdrawHandler`'s expression calls `hasRole('WALLET_OWNER')`, which `SecurityExpressionRoot::hasRole()` normalises to the identical `'ROLE_WALLET_OWNER'` string before checking membership; the two spellings meet in the middle. The `409` in the second call is the important contrast: authorization and domain validation are two genuinely independent layers — being *allowed* to withdraw says nothing about whether the withdrawal itself is *valid*, and `Wallet::withdraw()`'s own no-overdraw invariant (Chapter 6) still applies exactly as before.

`samples/lumen/tests/Application/TransferSecurityTest.php` proves the same guard directly against the `CommandBus`, with the `getPrevious()` unwrap pattern Chapter 7 introduced:

<!-- source: samples/lumen/tests/Application/TransferSecurityTest.php -->
```php
it('enforces #[PreAuthorize] on withdraw: denies without the owner role, allows with it', function () {
    // …
    $commands = $this->fireflyContext()->get(CommandBus::class);
    // …
    $queries = $this->fireflyContext()->get(QueryBus::class);
    // …
    $walletId = $commands->send(new OpenWallet('owner-C', Currency::EUR));
    $commands->send(new Deposit($walletId, 5000));
    // …
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('mallory', 'mallory', [new SimpleGrantedAuthority('ROLE_USER')])
    ));

    $denied = null;
    try {
        $commands->send(new Withdraw($walletId, 1000));
    } catch (CommandProcessingException $e) {
        $denied = $e;
    }
    // …
    expect($denied)->toBeInstanceOf(CommandProcessingException::class);
    expect($denied?->getPrevious())->toBeInstanceOf(AuthorizationException::class);
    expect($queries->ask(new GetBalance($walletId)))->toBe(5000);

    // Same withdraw, now with ROLE_WALLET_OWNER granted: the guard passes and the debit goes through.
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('owner-C', 'owner-C', [new SimpleGrantedAuthority('ROLE_WALLET_OWNER')])
    ));

    $commands->send(new Withdraw($walletId, 1000));

    expect($queries->ask(new GetBalance($walletId)))->toBe(4000);
```

`$denied?->getPrevious()` reaches straight past `CommandProcessingException` to the real `AuthorizationException` `MethodSecurityMessageEnforcer` threw — the exact unwrap pattern Chapter 7 showed you, now with its concrete security use.

!!! laravel "Laravel parity"
    Plain Laravel authorizes with Gates/Policies and a `$this->authorize()` call inside a controller — imperative, and only ever reachable from code that remembers to call it. `#[PreAuthorize]` is attribute-discovered and enforced automatically at the bus for *every* dispatch of a guarded command or query, with no call site able to forget it; `AuthorizationChecker::check()` (backed by the identical evaluator) is the closest LaraFly equivalent to a Gate check for the rarer case where you need to test an expression imperatively at an arbitrary call site.

---

## What you learned {.recap}

| Concept | What it does |
|---|---|
| `Authentication` | Immutable; `authenticated()`/`unauthenticated()` factories make the two states impossible to confuse |
| `SecurityContextHolder` | Request-scoped static holder; every auth filter clears it in a `finally` |
| `DaoAuthenticationProvider` | Constant-time-equivalent, enumeration-mitigated username/password check |
| `JwtService` | Refuses to boot with a weak/placeholder secret; refuses a token with no `exp` claim |
| `HttpSecurity` | Deny-by-default URL rules, first-match-wins, compiling to the same expression grammar as method security |
| `SecurityExpressionEvaluator` | A hand-rolled, closed-whitelist tokenizer/parser — no `eval`, no `==`, no `.property` navigation; ten functions total (`hasRole`, `hasAnyRole`, `hasAuthority`, `hasAnyAuthority`, `hasScope`, `hasAnyScope`, `hasPermission`, `isAuthenticated`, `permitAll`, `denyAll`) plus `#param` references |
| `hasRole('X')` | Normalises to `hasAuthority('ROLE_X')` — bare and `ROLE_`-prefixed spellings are equivalent |
| `SecurityCommandAuthorizer` / `SecurityQueryAuthorizer` | The real `#[Order(500)]` implementations that replace Chapter 7's `AllowAllAuthorizer`, only when `firefly.security.enabled=true` |
| `MethodSecurityMessageEnforcer` | Joins the CQRS `HandlerManifest` to the compiled `SecurityMethodManifest`; enforces before `handle()` ever runs |
| `SecurityContextPersistenceFilter` | Loads the context from the session at `-94`, saves what changed, and clears the holder in a `finally` |
| `FormLoginFilter` / `LoginRouteRegistrar` | `POST /login` handled before routing; the framework's page mounted **only** when nothing else claims that address |
| `EloquentUserDetailsService` | Your own users table as the user store — and a configured column the row lacks is refused, never read as `null` |
| `LogoutFilter` / `TokenBasedRememberMeServices` | POST-only, CSRF-checked logout; a cookie signed over the **password hash**, so a password change revokes every one |
| `AuthenticationEntryPoint` | The four `entry_point` modes; `ErrorPageRenderer::prefersHtml()` — the request names `text/html` or `application/xhtml+xml`, is no XHR and is not under `json-paths` — decides login page versus 401 |
| `MethodSecurityAdviceSource` | Method security on **any** stereotyped bean, at advice order 100 — outside the transaction at 1000 |
| `#[PostAuthorize]` / `#[PostFilter]` | Rules that need the returned value: `#returnObject` after the call, `#filterObject` per element |
| `SecurityArgumentResolver` | `Authentication`, `UserDetails`, `#[AuthenticationPrincipal]`, `#[CurrentSecurityContext]` bound before the container is asked |
| The event family | Seven events; an unknown user reports as bad credentials, and only an **authenticated** denial is published |

---

## Try it yourself {.exercises}

1. **Write a rejected expression.** Try compiling `#[PreAuthorize("#command.ownerId == authentication.name")]` on a scratch handler and run `php artisan firefly:cache`. Confirm `MethodSecurityScanner` fails loud with a `ConfigurationException` naming the exact class and method, rather than silently accepting an expression that would always deny (or fail to parse) at runtime.
2. **Add a real ownership check.** In a scratch copy of the project, implement a `PermissionEvaluator` bean that loads a wallet by id and compares its `owner_id` to `Authentication::getName()`, bind it to replace `DenyAllPermissionEvaluator`, and change `WithdrawHandler`'s expression to `hasPermission(#command, 'withdraw')`. Confirm a `WALLET_OWNER`-less principal who genuinely owns the wallet can now withdraw, while a different owner cannot.
3. **Own the login page.** Add a `#[GetMapping('/login')]` action of your own to a scratch project with `form_login.enabled` on, boot it, and confirm the framework's page does *not* replace yours — then confirm your form still signs people in by posting the session token to the processing URL, because `FormLoginFilter` answers before routing either way.
4. **Watch the entry point switch.** Against a protected path, send `Accept: text/html` and then `Accept: application/json`, and confirm you get a redirect to `/login` and a `401 application/problem+json` respectively. Then set `firefly.security.http.entry_point` to `problem` and confirm the browser request gets the 401 too.
5. **Put a rule on a plain service.** Add `#[PreAuthorize("hasRole('ADMIN')")]` to a method on a non-final `#[Service]` that is neither a controller nor a handler, run `php artisan firefly:cache`, and confirm the call is refused. Then mark the class `final`, re-run the cache, and confirm the scanner refuses at compile time rather than shipping a rule nothing could enforce.
6. **Reproduce the enumeration-mitigation timing.** Time `DaoAuthenticationProvider::authenticate()` for a known username with a wrong password against an unknown username, over enough iterations to smooth out noise, and confirm the two are statistically indistinguishable — proving the dummy-hash verify this chapter described is genuinely doing its job, not just documented as if it were.
