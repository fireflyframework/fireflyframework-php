<span class="eyebrow">Parte III — Coordinar y Asegurar la Aplicación · Capítulo 10</span>

# Seguridad: Autenticación y Autorización {.chtitle}

Al terminar este capítulo conocerás el modelo de principal inmutable de `firefly/security` (`Authentication`, `SecurityContext`, `SecurityContextHolder`), cómo `DaoAuthenticationProvider` autentica un par de usuario/contraseña mientras neutraliza ataques de enumeración, cómo `JwtService` se niega a arrancar con un secreto débil y se niega a aceptar un token sin caducidad, el DSL de URL `HttpSecurity` que deniega por defecto, y — la pieza central de este capítulo — exactamente cómo se evalúa `#[PreAuthorize]` mediante una gramática de expresiones hecha a mano, de **lista blanca cerrada**, que nunca llama a `eval()`, que se hace cumplir en el bus de CQRS que conociste en el Capítulo 7, y que normaliza `hasRole('X')` a una comprobación contra la autoridad concedida `'ROLE_X'`.

!!! note "Término nuevo: autenticación frente a autorización"
    La **autenticación** responde "¿quién está haciendo esta petición?" — produce un principal. La **autorización** responde "¿le está permitido a ese principal hacer *esto en concreto*?" `firefly/security` mantiene ambas estrictamente separadas: `Authentication`/`SecurityContextHolder` llevan la respuesta a la primera pregunta; `HttpSecurity`, `#[PreAuthorize]` y `AuthorizationChecker` responden todos a la segunda, evaluando una expresión **contra** lo que ya haya resuelto la primera pregunta.

---

## El modelo de principal

`Authentication` es un token inmutable construido exclusivamente mediante dos fábricas con nombre, de modo que los dos estados en los que puede estar — una petición no autenticada que aún lleva credenciales en bruto, y un principal autenticado que lleva autoridades concedidas — nunca puedan confundirse:

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

El constructor privado significa que la única forma de construir uno es `authenticated()` (las credenciales siempre `null`, `authenticated` siempre `true`) o `unauthenticated()` (las autoridades siempre `[]`, `authenticated` siempre `false`) — no existe ningún camino que permita construir un token "autenticado" con credenciales en bruto todavía pegadas. `GrantedAuthority`/`SimpleGrantedAuthority` envuelven una cadena de autoridad desnuda (`'ROLE_ADMIN'`, `'orders:read'`):

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

`SecurityContext` es una instantánea inmutable de la `Authentication` actual (`anonymous()` es el valor cero no autenticado), y `SecurityContextHolder` es donde una petición lo encuentra:

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

Es `static` a propósito — la misma forma que tiene el propio `SecurityContextHolder` de Spring — porque es un accesor local a la petición, no un colaborador inyectado, respaldado por la propia fachada `Context` de Laravel (por petición, seguro bajo Octane). `getContext()` nunca exige una comprobación de nulos en el sitio de la llamada: un contexto no establecido resuelve automáticamente a `SecurityContext::anonymous()`. La garantía que sostiene todo lo demás, contra la fuga del principal de una petición hacia la siguiente, es que cada filtro de autenticación llama a `clearContext()` en un bloque `finally` al salir — PHP siempre ejecuta un `finally`, así que ningún camino de excepción puede saltárselo.

---

## Autenticar una petición: `DaoAuthenticationProvider`

`DaoAuthenticationProvider` comprueba un par de usuario/contraseña contra un `UserDetailsService`, y está escrito específicamente para neutralizar ataques de temporización por enumeración de nombres de usuario:

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

Un nombre de usuario desconocido se hace deliberadamente **indistinguible** de una contraseña incorrecta: ante `UsernameNotFoundException`, el proveedor igualmente ejecuta una verificación real de contraseña — contra un hash ficticio fijo, precalculado en el momento de la construcción con el *mismo* codificador y el mismo factor de coste que usan los usuarios reales — antes de lanzar exactamente la misma `BadCredentialsException` genérica, con el mismo mensaje, que produce una contraseña genuinamente incorrecta. Ninguna diferencia de temporización, ninguna diferencia de contenido. Los fallos de estado de cuenta (`locked`/`disabled`) se comprueban solo **después** de una contraseña correcta — el orden recomendado por OWASP — de modo que un atacante sin la contraseña correcta no aprende nada sobre si la cuenta siquiera existe, y mucho menos sobre su estado. Cuando tiene éxito, el token devuelto se construye de nuevo mediante `Authentication::authenticated()`, que nunca arrastra consigo la contraseña en bruto.

---

## JWT: caducidad obligatoria, arranque rechazado ante un secreto débil

`JwtService` hace cumplir dos invariantes de fallo seguro que un desarrollador nunca tiene que recordar por su cuenta:

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

La primera invariante se dispara en la **construcción**, no en el primer uso: un secreto más corto de 32 bytes, o uno de una lista fija de marcadores de posición comunes (`'changeme'`, `'secret'`, `'password'`, una cadena vacía…), lanza `WeakSigningSecretException` — la aplicación se niega directamente a arrancar en vez de firmar tokens con una clave adivinable. La segunda se dispara en cada `decode()`: incluso una firma criptográficamente válida se rechaza sin más si el token no lleva un claim `exp`, de modo que una caducidad olvidada nunca puede producir un token eternamente válido. `firefly.security.jwt.enabled` y `firefly.security.oauth2.resource_server.enabled` son además **mutuamente excluyentes** — habilitar ambos se rechaza en el arranque, igualando el modelo de Spring de un único mecanismo de portador.

---

## Reglas de URL que deniegan por defecto: `HttpSecurity`

`HttpSecurity` es un DSL fluido que construye una lista ordenada de reglas de URL, evaluada primera-coincidencia-gana por `HttpSecurityFilter`, con **ninguna coincidencia en absoluto denegando la petición**:

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

Cada regla compila hacia la **exacta misma gramática de expresiones** que usa `#[PreAuthorize]` más abajo — `HttpSecurity` es un constructor que emite cadenas con la forma `permitAll()`/`hasRole('ADMIN')`, no un segundo motor de autorización. `HttpSecurityFilter` evalúa las reglas compiladas contra la ruta de la petición y, en la primera coincidencia de patrón, comprueba la expresión de la regla; una petición que no coincide con **ninguna** regla se deniega — de fallo seguro, no de apertura por defecto. Una denegación se renderiza como `401` cuando el contexto es anónimo (autentícate primero) y como `403` cuando está autenticado pero sin privilegios suficientes.

`assertSafeValue()` rechaza cualquier valor de rol/autoridad que contenga una comilla simple, por una razón que importa muchísimo en cuanto leas la siguiente sección: una cadena de rol o autoridad legítima nunca contiene una, pero un valor que sí lo hiciera podría, si no, empalmar gramática adicional dentro del literal de expresión fijo en el que se interpola.

---

## Seguridad de método: la gramática de expresiones de lista blanca cerrada

Esta es la sección hacia la que ha estado construyendo el resto del capítulo. `#[PreAuthorize]`, `#[Secured]` y `#[RolesAllowed]` protegen todos un método con una expresión booleana:

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

`#[Secured]`/`#[RolesAllowed]` son azúcar de conveniencia que `MethodSecurityScanner` compila hacia una expresión `hasAnyAuthority(...)`/`hasAnyRole(...)` — todo se reduce en última instancia a la única gramática que `#[PreAuthorize]` escribe directamente. Y esa gramática es deliberada, demostrablemente cerrada: `SecurityExpressionEvaluator` es un tokenizador hecho a mano más un analizador de descenso recursivo, y **nunca** llama a `eval()`, `create_function()` ni `call_user_func()` sobre nada derivado de la cadena de expresión. El despacho de funciones es un `match` fijo de antemano:

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

Ocho nombres de función, en total, y no hay nada más alcanzable. La gramática que acepta el tokenizador es booleana `and`/`or`/`not` (escrita `&&`/`||`/`!` a nivel de carácter), paréntesis, literales de cadena entre comillas simples y referencias a parámetros `#identificador` — y nada más:

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

!!! warning "Lo que NO está en la gramática"
    No hay `==`, no hay `!=`, ningún operador de comparación de ningún tipo, y ninguna navegación `.propiedad`. No puedes escribir `#command.ownerId == authentication.name` — el tokenizador no tiene noción alguna de un acceso por punto ni de un operador de igualdad, así que ese texto o falla al tokenizar o falla al analizar, y en cualquiera de los dos casos `evaluate()` captura la `ExpressionParseException` y devuelve `false`. Esto no es un descuido; es lo que hace que la gramática sea demostrablemente cerrada. Si una comparación así parece justo lo que necesitas, la vía de escape prevista es `hasPermission(#target, 'action')` respaldada por un bean `PermissionEvaluator` a medida que hace la comparación de campo real en código PHP de verdad — la gramática despacha hacia tu propia lógica, nunca intenta convertirse ella misma en un lenguaje de expresiones de propósito general.

    Todo modo de fallo — un nombre de función desconocido, un carácter suelto, un literal de cadena malformado, una excepción lanzada por un `PermissionEvaluator` a medida a mitad de la evaluación — se captura con el mismo `catch (\Throwable)` y se convierte en `false`. Una expresión hostil o rota solo puede denegar; nunca puede conceder por accidente.

`SecurityExpressionRoot` es el **único** objeto que las llamadas a función de una expresión pueden alcanzar jamás — no hay forma de llamar a ninguna otra cosa, porque no existe sintaxis de acceso a propiedad ni forma de obtener una referencia a ningún otro objeto:

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

`hasRole('ADMIN')` y `hasRole('ROLE_ADMIN')` son equivalentes — un nombre de rol desnudo se normaliza anteponiendo `ROLE_` si todavía no lo lleva, igualando exactamente la propia convención de Spring. `#param` — tokenizado como un `#` desnudo seguido de un identificador — se resuelve mediante `arg()` contra los propios nombres de los parámetros del método protegido, enlazados posicionalmente por `MethodSecurityScanner` en el momento del escaneo.

Aquí está toda la idea hecha concreta, en código real y distribuido — `WithdrawHandler`, real, de `samples/lumen/src/Application/Command/WithdrawHandler.php`:

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

Lee con atención el segundo párrafo de ese docblock — es toda la lección en miniatura. La forma *ingenua* de expresar "solo el propio dueño del monedero puede retirar" sería algo como `#command.ownerId == authentication.name`, y la gramática sencillamente no puede decir eso: no hay operador `.` ni `==`. La alternativa real, distribuida, es más burda pero genuinamente expresable: conceder una autoridad `WALLET_OWNER` a quien deba tener permiso, y comprobar `hasRole('WALLET_OWNER')`. Una comprobación de propiedad genuina por recurso, cuando la necesitas, pasa por `hasPermission(#command, 'withdraw')` y un bean `PermissionEvaluator` a medida que hace la comparación real en PHP ordinario — la gramática despacha hacia tu código, no intenta volverse lo bastante expresiva como para escribir ella misma la comparación.

---

## Aplicación en el bus: cerrando el círculo del `AllowAllAuthorizer` del Capítulo 7

El Capítulo 7 te mostró la tubería de `CommandBus`/`QueryBus` ejecutando una etapa `authorize()` que por defecto usa `AllowAllAuthorizer` — permitir todo. `firefly/security` es lo que de verdad lo reemplaza, y lo hace solo cuando tú decides activarlo: `SecurityAutoConfiguration` está en `#[Order(500)]`, estrictamente por debajo del `#[Order(1000)]` de `CqrsAutoConfiguration`, así que — el mismo patrón de ganar-la-carrera que te mostró el Capítulo 8 para el outbox de Postgres en `#[Order(900)]` — sus beans se registran primero y satisfacen el propio `#[ConditionalOnMissingBean(CommandAuthorizer::class)]` de `CqrsAutoConfiguration` antes de que el valor por defecto de permitir-todo tenga siquiera oportunidad de enlazarse, pero **solo** cuando `firefly.security.enabled=true` (cada bean de seguridad aquí está además condicionado a ese indicador maestro):

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

`MethodSecurityMessageEnforcer` es el punto de encuentro compartido al que delegan tanto `SecurityCommandAuthorizer` como su gemelo del lado de consulta `SecurityQueryAuthorizer`: resuelve la clase + método manejador del mensaje a partir del **mismo `HandlerManifest`** que introdujo el Capítulo 7, busca cualquier regla `#[PreAuthorize]`/`#[Secured]`/`#[RolesAllowed]` compilada para ese `Class::method`, y — solo si existe una — la evalúa contra el estado actual de `SecurityContextHolder`, con el propio mensaje enlazado al único `#param` de la regla:

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

Un mensaje sin manejador registrado, o un manejador sin regla de seguridad compilada, pasa sin comprobación alguna — la seguridad de método aquí es **aditiva**, nunca una segunda puerta que deniegue por defecto (ese trabajo pertenece por entero a `HttpSecurityFilter`). Pero cuando `WithdrawHandler::handle()` sí lleva una regla, este es el código que la ejecuta, **antes** de que `handle()` mismo llegue a ejecutarse — una denegación lanza `AuthorizationException`, que `DefaultCommandBus::send()` (Capítulo 7) envuelve en `CommandProcessingException`, exactamente como cualquier otro fallo del lado del manejador.

---

## Prueba de extremo a extremo

Dos archivos de prueba reales y distribuidos ejercitan la cadena completa — petición HTTP de entrada, `SecurityCommandAuthorizer` haciendo cumplir el `#[PreAuthorize]` de `WithdrawHandler` en el bus, salida como una respuesta RFC-7807. `samples/lumen/tests/Web/WalletRestTest.php`, sin ningún principal establecido en absoluto:

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

Un `SecurityContextHolder::getContext()` anónimo falla tanto `hasRole('ADMIN')` como `hasRole('WALLET_OWNER')`, así que `MethodSecurityMessageEnforcer` lanza antes de que `WithdrawHandler::handle()` llegue a ejecutarse — la aserción sobre el saldo, después, demuestra que el débito genuinamente nunca ocurrió, no solo que la respuesta HTTP tuviera apariencia de rechazo. Concede la autoridad correcta y el mismo comando tiene éxito:

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

La autoridad concedida es `'ROLE_WALLET_OWNER'` — ya con el prefijo `ROLE_` incorporado — y la expresión de `WithdrawHandler` llama a `hasRole('WALLET_OWNER')`, que `SecurityExpressionRoot::hasRole()` normaliza a la cadena idéntica `'ROLE_WALLET_OWNER'` antes de comprobar la pertenencia; las dos formas de escribirlo se encuentran en el medio. El `409` de la segunda llamada es el contraste importante: la autorización y la validación de dominio son dos capas genuinamente independientes — que se te *permita* retirar no dice nada sobre si la retirada en sí es *válida*, y la propia invariante de no-sobregiro de `Wallet::withdraw()` (Capítulo 6) sigue aplicándose exactamente igual que antes.

`samples/lumen/tests/Application/TransferSecurityTest.php` demuestra el mismo guardián directamente contra el `CommandBus`, con el patrón de desenvolver mediante `getPrevious()` que introdujo el Capítulo 7:

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

`$denied?->getPrevious()` alcanza, más allá de `CommandProcessingException`, la `AuthorizationException` real que lanzó `MethodSecurityMessageEnforcer` — el mismo patrón de desenvolver que te mostró el Capítulo 7, ahora con su uso concreto en seguridad.

!!! laravel "Paridad con Laravel"
    Laravel puro autoriza con Gates/Policies y una llamada `$this->authorize()` dentro de un controlador — imperativo, y solo alcanzable desde código que se acuerde de llamarlo. `#[PreAuthorize]` se descubre por atributo y se hace cumplir automáticamente en el bus para *cada* despacho de un comando o consulta protegido, sin ningún sitio de llamada capaz de olvidarlo; `AuthorizationChecker::check()` (respaldado por el mismo evaluador) es el equivalente más cercano en LaraFly a una comprobación de Gate, para el caso más raro en que necesitas probar una expresión de forma imperativa en un sitio de llamada arbitrario.

---

## Lo que aprendiste {.recap}

| Concepto | Qué hace |
|---|---|
| `Authentication` | Inmutable; las fábricas `authenticated()`/`unauthenticated()` hacen imposible confundir los dos estados |
| `SecurityContextHolder` | Contenedor estático con alcance de petición; cada filtro de autenticación lo limpia en un `finally` |
| `DaoAuthenticationProvider` | Comprobación de usuario/contraseña equivalente en tiempo, con mitigación de enumeración |
| `JwtService` | Se niega a arrancar con un secreto débil/marcador de posición; rechaza un token sin claim `exp` |
| `HttpSecurity` | Reglas de URL que deniegan por defecto, primera-coincidencia-gana, compilando hacia la misma gramática de expresiones que la seguridad de método |
| `SecurityExpressionEvaluator` | Un tokenizador/analizador hecho a mano, de lista blanca cerrada — sin `eval`, sin `==`, sin navegación `.propiedad`, 8 funciones en total |
| `hasRole('X')` | Normaliza a `hasAuthority('ROLE_X')` — las formas desnuda y con prefijo `ROLE_` son equivalentes |
| `SecurityCommandAuthorizer` / `SecurityQueryAuthorizer` | Las implementaciones reales en `#[Order(500)]` que reemplazan al `AllowAllAuthorizer` del Capítulo 7, solo cuando `firefly.security.enabled=true` |
| `MethodSecurityMessageEnforcer` | Une el `HandlerManifest` de CQRS con el `SecurityMethodManifest` compilado; hace cumplir la regla antes de que `handle()` llegue a ejecutarse |

---

## Ponlo en práctica {.exercises}

1. **Escribe una expresión rechazada.** Intenta compilar `#[PreAuthorize("#command.ownerId == authentication.name")]` en un manejador de prueba y ejecuta `php artisan firefly:cache`. Confirma que `MethodSecurityScanner` falla de forma ruidosa con una `ConfigurationException` que nombra la clase y el método exactos, en vez de aceptar silenciosamente una expresión que siempre denegaría (o fallaría al analizar) en tiempo de ejecución.
2. **Añade una comprobación real de propiedad.** En una copia de prueba del proyecto, implementa un bean `PermissionEvaluator` que cargue un monedero por id y compare su `owner_id` con `Authentication::getName()`, enlázalo para que reemplace a `DenyAllPermissionEvaluator`, y cambia la expresión de `WithdrawHandler` a `hasPermission(#command, 'withdraw')`. Confirma que un principal sin `WALLET_OWNER` que genuinamente sea dueño del monedero ahora puede retirar, mientras que un dueño distinto no puede.
3. **Reproduce la temporización de la mitigación de enumeración.** Mide el tiempo de `DaoAuthenticationProvider::authenticate()` para un nombre de usuario conocido con una contraseña incorrecta frente a un nombre de usuario desconocido, con suficientes iteraciones para suavizar el ruido, y confirma que ambos son estadísticamente indistinguibles — demostrando que la verificación con hash ficticio que describió este capítulo realmente hace su trabajo, y no solo está documentada como si lo hiciera.
