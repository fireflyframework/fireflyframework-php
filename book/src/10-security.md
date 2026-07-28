<span class="eyebrow">Part III — Coordinating and Securing the Application · Chapter 10</span>

# Security: Authentication and Authorization {.chtitle}

By the end of this chapter you will know `firefly/security`'s immutable principal model (`Authentication`, `SecurityContext`, `SecurityContextHolder`), how `DaoAuthenticationProvider` authenticates a username/password pair while defeating enumeration attacks, how `JwtService` refuses to boot with a weak secret and refuses to accept a token with no expiry, the deny-by-default `HttpSecurity` URL DSL, and — the centrepiece of this chapter — exactly how `#[PreAuthorize]` is evaluated by a hand-rolled, **closed-whitelist** expression grammar that never calls `eval()`, is enforced at the CQRS bus you met in Chapter 7, and normalises `hasRole('X')` to a check against the granted authority `'ROLE_X'`.

!!! note "New term: authentication vs. authorization"
    **Authentication** answers "who is making this request?" — it produces a principal. **Authorization** answers "is that principal allowed to do *this specific thing*?" `firefly/security` keeps the two strictly separate: `Authentication`/`SecurityContextHolder` carry the answer to the first question; `HttpSecurity`, `#[PreAuthorize]`, and `AuthorizationChecker` all answer the second, by evaluating an expression **against** whatever the first question already settled.

---

## The principal model

`Authentication` is an immutable token built exclusively through two named factories, so the two states it can be in — an unauthenticated request still carrying raw credentials, and an authenticated principal carrying granted authorities — can never be confused:

```php
final class Authentication
{
    private function __construct(
        public readonly string $name,
        public readonly mixed $principal,
        public readonly mixed $credentials,
        public readonly array $authorities,
        public readonly bool $authenticated,
        public readonly array $attributes,
    ) {}

    public static function authenticated(string $name, mixed $principal, array $authorities, array $attributes = []): self
    {
        return new self($name, $principal, null, $authorities, true, $attributes);
    }

    public static function unauthenticated(string $name, mixed $principal, mixed $credentials): self
    {
        return new self($name, $principal, $credentials, [], false, []);
    }

    public function eraseCredentials(): self
    {
        return new self($this->name, $this->principal, null, $this->authorities, $this->authenticated, $this->attributes);
    }
}
```

The private constructor means the only way to build one is `authenticated()` (credentials always `null`, `authenticated` always `true`) or `unauthenticated()` (authorities always `[]`, `authenticated` always `false`) — there is no path that lets you construct an "authenticated" token with leftover raw credentials still attached. `GrantedAuthority`/`SimpleGrantedAuthority` wrap a bare authority string (`'ROLE_ADMIN'`, `'orders:read'`):

```php
interface GrantedAuthority
{
    public function getAuthority(): string;
}

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

```php
final class SecurityContextHolder
{
    private const KEY = 'firefly.security.context';

    public static function getContext(): SecurityContext
    {
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

```php
final class DaoAuthenticationProvider implements AuthenticationProvider
{
    private const DUMMY_PASSWORD = 'firefly-dummy-password-for-timing-mitigation';

    private readonly string $dummyHash;

    public function __construct(
        private readonly UserDetailsService $users,
        private readonly PasswordEncoder $encoder,
    ) {
        // Precompute with the REAL encoder (same algorithm/cost) so the user-not-found verify below is
        // timing-equivalent to a genuine credential check across any PasswordEncoder.
        $this->dummyHash = $encoder->encode(self::DUMMY_PASSWORD);
    }

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
        if (in_array(strtolower($secret), self::PLACEHOLDERS, true) || strlen($secret) < self::MIN_SECRET_BYTES) {
            throw new WeakSigningSecretException(
                'Refusing to boot: the JWT signing secret is a placeholder or shorter than '.self::MIN_SECRET_BYTES.' bytes. Set firefly.security.jwt.secret to a strong random value.'
            );
        }
    }

    public function decode(string $token): array
    {
        JWT::$leeway = $this->leewaySeconds;

        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));
        } catch (ExpiredException $e) {
            throw new TokenExpiredException('JWT has expired.', 'TOKEN_EXPIRED', $e);
        } catch (SignatureInvalidException $e) {
            throw new InvalidTokenException('JWT signature is invalid.', 'INVALID_TOKEN', $e);
        }

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

```php
final class HttpSecurity
{
    public function requestMatcher(string $pattern): self { /* ... */ }
    public function anyRequest(): self { return $this->requestMatcher('*'); }

    public function permitAll(): self { return $this->finalise('permitAll()'); }
    public function denyAll(): self { return $this->finalise('denyAll()'); }
    public function authenticated(): self { return $this->finalise('isAuthenticated()'); }

    public function hasRole(string $role): self
    {
        return $this->finalise("hasRole('".self::assertSafeValue($role)."')");
    }
}
```

Every rule compiles to the **exact same expression grammar** `#[PreAuthorize]` uses below — `HttpSecurity` is a builder that emits `permitAll()`/`hasRole('ADMIN')`-shaped strings, not a second authorization engine. `HttpSecurityFilter` evaluates the compiled rules against the request path and, on the first pattern match, checks the rule's expression; a request matching **no** rule at all is denied — fail-closed, not fail-open. A denial renders as a `401` when the context is anonymous (authenticate first) and a `403` when authenticated but under-privileged.

`assertSafeValue()` rejects any role/authority value containing a single quote, for a reason that matters a great deal once you've read the next section: a legitimate role or authority string never contains one, but a value that did could otherwise splice extra grammar into the fixed expression literal it gets interpolated into.

---

## Method security: the closed-whitelist expression grammar

This is the section the rest of the chapter has been building toward. `#[PreAuthorize]`, `#[Secured]`, and `#[RolesAllowed]` all guard a method with a boolean expression:

```php
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class PreAuthorize
{
    public function __construct(public string $expression) {}
}

/** Requires ANY of the listed authorities. Compiled to hasAnyAuthority(...). */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class Secured
{
    public array $authorities;
    public function __construct(string ...$authorities) { $this->authorities = array_values($authorities); }
}

/** Requires ANY of the listed roles (JSR-250 @RolesAllowed). Compiled to hasAnyRole(...). */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class RolesAllowed
{
    public array $roles;
    public function __construct(string ...$roles) { $this->roles = array_values($roles); }
}
```

`#[Secured]`/`#[RolesAllowed]` are convenience sugar `MethodSecurityScanner` compiles down to a `hasAnyAuthority(...)`/`hasAnyRole(...)` expression — everything ultimately reduces to the one grammar `#[PreAuthorize]` writes directly. And that grammar is deliberately, provably closed: `SecurityExpressionEvaluator` is a hand-written tokenizer plus a recursive-descent parser, and it **never** calls `eval()`, `create_function()`, or `call_user_func()` on anything derived from the expression string. Function dispatch is a hard-coded `match`:

```php
final class SecurityExpressionEvaluator
{
    private function dispatch(string $name, array $args): bool
    {
        $root = $this->root;

        return match ($name) {
            'hasRole' => $root->hasRole($this->str($args, 0, $name)),
            'hasAnyRole' => $root->hasAnyRole(...$this->strings($args, $name)),
            'hasAuthority' => $root->hasAuthority($this->str($args, 0, $name)),
            'hasAnyAuthority' => $root->hasAnyAuthority(...$this->strings($args, $name)),
            'hasPermission' => $root->hasPermission($args[0] ?? null, $this->str($args, 1, $name)),
            'isAuthenticated' => $root->isAuthenticated(),
            'permitAll' => $root->permitAll(),
            'denyAll' => $root->denyAll(),
            default => throw new ExpressionParseException("Unknown function '{$name}'."),
        };
    }
}
```

Eight function names, total, and nothing else is reachable. The grammar the tokenizer accepts is boolean `and`/`or`/`not` (spelled `&&`/`||`/`!` at the character level), parentheses, string literals in single quotes, and `#identifier` parameter references — and nothing else:

```php
final class SecurityExpressionEvaluator
{
    public function evaluate(string $expression, SecurityExpressionRoot $root): bool
    {
        try {
            $this->tokens = $this->tokenize($expression);
            $this->pos = 0;
            $this->root = $root;
            $result = $this->parseExpression();
            $this->expect('eof');

            return $result;
        } catch (\Throwable) {
            // Fail-closed on ANY error: a malformed/hostile expression OR a throw deeper in evaluation denies
            // with a clean 403 rather than surfacing a 500. Security errs to deny, never to allow.
            return false;
        }
    }
}
```

!!! warning "What is NOT in the grammar"
    There is no `==`, no `!=`, no comparison operator of any kind, and no `.property` navigation. You cannot write `#command.ownerId == authentication.name` — the tokenizer has no notion of a dot-access or an equality operator at all, so that text either fails to tokenize or fails to parse, and either way `evaluate()` catches the `ExpressionParseException` and returns `false`. This is not an oversight; it is what makes the grammar provably closed. If a comparison like that seems like exactly what you need, the intended escape hatch is `hasPermission(#target, 'action')` backed by a custom `PermissionEvaluator` bean that does the actual field comparison in real PHP code — the grammar routes to your logic, it never becomes a general-purpose expression language itself.

    Every failure mode — an unknown function name, a stray character, a malformed string literal, an exception thrown by a custom `PermissionEvaluator` mid-evaluation — is caught by the same `catch (\Throwable)` and turned into `false`. A hostile or broken expression can only ever deny; it can never accidentally grant.

`SecurityExpressionRoot` is the **only** object an expression's function calls can ever reach — there is no way to call anything else, because there is no property-access syntax and no way to obtain a reference to any other object:

```php
final class SecurityExpressionRoot
{
    public function hasRole(string $role): bool
    {
        return $this->hasAuthority(str_starts_with($role, 'ROLE_') ? $role : 'ROLE_'.$role);
    }

    public function hasAuthority(string $authority): bool
    {
        return in_array($authority, $this->reachable, true);
    }

    public function arg(string $name): mixed
    {
        return $this->args[$name] ?? null;
    }
}
```

`hasRole('ADMIN')` and `hasRole('ROLE_ADMIN')` are equivalent — a bare role name is normalised by prepending `ROLE_` if it isn't already there, exactly matching Spring's own convention. `#param` — tokenized as a bare `#` followed by an identifier — resolves through `arg()` against the guarded method's own parameter names, bound positionally by `MethodSecurityScanner` at scan time.

Here is the whole point made concrete, in real, shipped code — `WithdrawHandler`, real, from `samples/lumen/src/Application/Command/WithdrawHandler.php`:

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
 * BEFORE the handler runs; a denied withdraw surfaces as CommandProcessingException wrapping AuthorizationException.
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

`MethodSecurityMessageEnforcer` is the shared join both `SecurityCommandAuthorizer` and its query-side twin `SecurityQueryAuthorizer` delegate to: it resolves the message's handler class + method from the **same `HandlerManifest`** Chapter 7 introduced, looks up any compiled `#[PreAuthorize]`/`#[Secured]`/`#[RolesAllowed]` rule for that `Class::method`, and — only if one exists — evaluates it against the current `SecurityContextHolder` state, with the message itself bound to the rule's single `#param`:

```php
final class MethodSecurityMessageEnforcer
{
    public function enforce(object $message, HandlerKind $kind): void
    {
        $handler = $this->handlerByMessage[$kind->value.':'.$message::class] ?? null;
        if ($handler === null) {
            return;
        }

        $rule = $this->methods->ruleFor($handler['class'], $handler['method']);
        if ($rule === null) {
            return;
        }

        $args = isset($rule->params[0]) ? [$rule->params[0] => $message] : [];
        $authentication = SecurityContextHolder::getAuthentication() ?? Authentication::unauthenticated('anonymous', 'anonymous', null);
        $root = new SecurityExpressionRoot($authentication, $this->roleHierarchy, $this->permissionEvaluator, $args);

        if (! $this->evaluator->evaluate($rule->expression, $root)) {
            throw new AuthorizationException("Access is denied for [{$handler['class']}::{$handler['method']}].");
        }
    }
}
```

A message with no registered handler, or a handler with no compiled security rule, passes through unchecked — method security here is **additive**, never a second deny-by-default gate (that job belongs entirely to `HttpSecurityFilter`). But when `WithdrawHandler::handle()` *does* carry a rule, this is the code that runs it, **before** `handle()` itself ever executes — a denial throws `AuthorizationException`, which `DefaultCommandBus::send()` (Chapter 7) wraps in `CommandProcessingException`, exactly like any other handler-side fault.

---

## Proof end-to-end

Two real, shipped test files exercise the entire chain — HTTP request in, `SecurityCommandAuthorizer` enforcing `WithdrawHandler`'s `#[PreAuthorize]` at the bus, out to an RFC-7807 response. `samples/lumen/tests/Web/WalletRestTest.php`, with no principal set at all:

```php
it('denies an unauthenticated withdraw as 403 problem+json (the endpoint IS secured)', function () {
    $id = $this->postJson('/api/v1/wallets', ['owner_id' => 'owner-3', 'currency' => 'EUR'])->json('wallet_id');
    $this->postJson("/api/v1/wallets/{$id}/deposit", ['amount_minor' => 5000]);

    $this->postJson("/api/v1/wallets/{$id}/withdraw", ['amount_minor' => 1000])
        ->assertStatus(403)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'COMMAND_PROCESSING_ERROR')
        ->assertJsonPath('category', 'security');

    // Proof the denial happened BEFORE the handler touched the balance.
    $this->getJson("/api/v1/wallets/{$id}/balance")->assertJson(['balance_minor' => 5000]);
});
```

An anonymous `SecurityContextHolder::getContext()` fails both `hasRole('ADMIN')` and `hasRole('WALLET_OWNER')`, so `MethodSecurityMessageEnforcer` throws before `WithdrawHandler::handle()` ever runs — the balance assertion afterward proves the debit genuinely never happened, not merely that the HTTP response looked like a rejection. Grant the right authority and the same command succeeds:

```php
it('allows an authorized withdraw and renders an overdraw as 409 problem+json', function () {
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('owner-4', 'owner-4', [new SimpleGrantedAuthority('ROLE_WALLET_OWNER')])
    ));

    $id = $this->postJson('/api/v1/wallets', ['owner_id' => 'owner-4', 'currency' => 'EUR'])->json('wallet_id');
    $this->postJson("/api/v1/wallets/{$id}/deposit", ['amount_minor' => 3000]);

    // Authorized + within balance: succeeds and reflects the new balance.
    $this->postJson("/api/v1/wallets/{$id}/withdraw", ['amount_minor' => 1000])
        ->assertStatus(200)
        ->assertJson(['wallet_id' => $id, 'balance_minor' => 2000]);

    // Authorized but over-balance: the domain's no-overdraw invariant raises ConflictException (409) — a
    // business fault, not a 403. The guard and the domain invariant are two independent layers.
    $this->postJson("/api/v1/wallets/{$id}/withdraw", ['amount_minor' => 999999])
        ->assertStatus(409)
        ->assertJsonPath('category', 'business');
});
```

The granted authority is `'ROLE_WALLET_OWNER'` — already carrying the `ROLE_` prefix — and `WithdrawHandler`'s expression calls `hasRole('WALLET_OWNER')`, which `SecurityExpressionRoot::hasRole()` normalises to the identical `'ROLE_WALLET_OWNER'` string before checking membership; the two spellings meet in the middle. The `409` in the second call is the important contrast: authorization and domain validation are two genuinely independent layers — being *allowed* to withdraw says nothing about whether the withdrawal itself is *valid*, and `Wallet::withdraw()`'s own no-overdraw invariant (Chapter 6) still applies exactly as before.

`samples/lumen/tests/Application/TransferSecurityTest.php` proves the same guard directly against the `CommandBus`, with the `getPrevious()` unwrap pattern Chapter 7 introduced:

```php
it('enforces #[PreAuthorize] on withdraw: denies without the owner role, allows with it', function () {
    $commands = $this->fireflyContext()->get(CommandBus::class);
    $queries = $this->fireflyContext()->get(QueryBus::class);

    $walletId = $commands->send(new OpenWallet('owner-C', Currency::EUR));
    $commands->send(new Deposit($walletId, 5000));

    // A principal carrying NEITHER ROLE_ADMIN NOR ROLE_WALLET_OWNER.
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('mallory', 'mallory', [new SimpleGrantedAuthority('ROLE_USER')])
    ));

    $denied = null;
    try {
        $commands->send(new Withdraw($walletId, 1000));
    } catch (CommandProcessingException $e) {
        $denied = $e;
    }

    expect($denied)->toBeInstanceOf(CommandProcessingException::class);
    expect($denied?->getPrevious())->toBeInstanceOf(AuthorizationException::class);
    expect($queries->ask(new GetBalance($walletId)))->toBe(5000);

    // Same withdraw, now with ROLE_WALLET_OWNER granted: the guard passes and the debit goes through.
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('owner-C', 'owner-C', [new SimpleGrantedAuthority('ROLE_WALLET_OWNER')])
    ));

    $commands->send(new Withdraw($walletId, 1000));

    expect($queries->ask(new GetBalance($walletId)))->toBe(4000);
});
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
| `SecurityExpressionEvaluator` | A hand-rolled, closed-whitelist tokenizer/parser — no `eval`, no `==`, no `.property` navigation, 8 functions total |
| `hasRole('X')` | Normalises to `hasAuthority('ROLE_X')` — bare and `ROLE_`-prefixed spellings are equivalent |
| `SecurityCommandAuthorizer` / `SecurityQueryAuthorizer` | The real `#[Order(500)]` implementations that replace Chapter 7's `AllowAllAuthorizer`, only when `firefly.security.enabled=true` |
| `MethodSecurityMessageEnforcer` | Joins the CQRS `HandlerManifest` to the compiled `SecurityMethodManifest`; enforces before `handle()` ever runs |

---

## Try it yourself {.exercises}

1. **Write a rejected expression.** Try compiling `#[PreAuthorize("#command.ownerId == authentication.name")]` on a scratch handler and run `php artisan firefly:cache`. Confirm `MethodSecurityScanner` fails loud with a `ConfigurationException` naming the exact class and method, rather than silently accepting an expression that would always deny (or fail to parse) at runtime.
2. **Add a real ownership check.** In a scratch copy of the project, implement a `PermissionEvaluator` bean that loads a wallet by id and compares its `owner_id` to `Authentication::getName()`, bind it to replace `DenyAllPermissionEvaluator`, and change `WithdrawHandler`'s expression to `hasPermission(#command, 'withdraw')`. Confirm a `WALLET_OWNER`-less principal who genuinely owns the wallet can now withdraw, while a different owner cannot.
3. **Reproduce the enumeration-mitigation timing.** Time `DaoAuthenticationProvider::authenticate()` for a known username with a wrong password against an unknown username, over enough iterations to smooth out noise, and confirm the two are statistically indistinguishable — proving the dummy-hash verify this chapter described is genuinely doing its job, not just documented as if it were.
