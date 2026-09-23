<span class="eyebrow">Parte III — Coordinar y Asegurar la Aplicación · Capítulo 10</span>

# Seguridad: Autenticación y Autorización {.chtitle}

Al terminar este capítulo conocerás el modelo de principal inmutable de `firefly/security` (`Authentication`, `SecurityContext`, `SecurityContextHolder`), cómo `DaoAuthenticationProvider` autentica un par de usuario/contraseña mientras neutraliza ataques de enumeración, cómo `JwtService` se niega a arrancar con un secreto débil y se niega a aceptar un token sin caducidad, el DSL de URL `HttpSecurity` que deniega por defecto, cómo se identifica de verdad una persona a través de `SecurityContextPersistenceFilter` y `FormLoginFilter` — incluida la página de login que el framework entrega y la que se niega a reemplazar —, cómo funcionan cerrar la sesión y seguir dentro, y por qué una petición con recuérdame queda *marcada* en lugar de recién autenticada, cómo `firefly.security.http.entry_point` decide entre una redirección al login y un `401` pelado, cómo se inyecta el principal en la firma de un controlador, la familia de eventos de seguridad que publican un inicio de sesión, un cierre de sesión y una denegación, y — la pieza central de este capítulo — exactamente cómo se evalúa `#[PreAuthorize]` mediante una gramática de expresiones hecha a mano, de **lista blanca cerrada**, que nunca llama a `eval()`, que normaliza `hasRole('X')` a una comprobación contra la autoridad concedida `'ROLE_X'`, que se hace cumplir en el bus de CQRS que conociste en el Capítulo 7, y que ahora rige sobre *cualquier* bean estereotipado a través del proxy del Capítulo 9 y no solo en el bus.

!!! note "Término nuevo: autenticación frente a autorización"
    La **autenticación** responde "¿quién está haciendo esta petición?" — produce un principal. La **autorización** responde "¿le está permitido a ese principal hacer *esto en concreto*?" `firefly/security` mantiene ambas estrictamente separadas: `Authentication`/`SecurityContextHolder` llevan la respuesta a la primera pregunta; `HttpSecurity`, `#[PreAuthorize]` y `AuthorizationChecker` responden todos a la segunda, evaluando una expresión **contra** lo que ya haya resuelto la primera pregunta.

---

## El modelo de principal

`Authentication` es un token inmutable construido exclusivamente mediante dos fábricas con nombre, de modo que los dos estados en los que puede estar — una petición no autenticada que aún lleva credenciales en bruto, y un principal autenticado que lleva autoridades concedidas — nunca puedan confundirse:

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

El constructor privado significa que la única forma de construir uno es `authenticated()` (las credenciales siempre `null`, `authenticated` siempre `true`) o `unauthenticated()` (las autoridades siempre `[]`, `authenticated` siempre `false`) — no existe ningún camino que permita construir un token "autenticado" con credenciales en bruto todavía pegadas.

`eraseCredentials()` es el mismo argumento repetido una vez más, un nivel más abajo, y sus dos líneas merecen una lectura atenta. Devuelve una instancia **nueva** en vez de limpiar un campo, así que una referencia que otra persona ya sostenga jamás puede observar un token que se limpió y luego se repobló. Y no se detiene en sus propias `credentials`: a un principal que sea a su vez un `CredentialsContainer` — el `User` distribuido, cuyo `getPassword()` es el hash codificado — se le pide su propia copia libre de credenciales, porque un hash de contraseña alcanzable a través de `$authentication->getPrincipal()` es un hash de contraseña sobre el cable en cuanto algo serializa el contexto. Esta es la forma que `SessionSecurityContextRepository` escribe en la sesión. Fíjate en lo que *no* la llama: el gestor de autenticación, al tener éxito. El token que recibe un filtro sigue llevando la contraseña codificada del principal, porque la firma de la cookie de recuérdame se calcula a partir de ella — así que solo un **almacén** necesita jamás la copia borrada. `GrantedAuthority`/`SimpleGrantedAuthority` envuelven una cadena de autoridad desnuda (`'ROLE_ADMIN'`, `'orders:read'`):

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

`SecurityContext` es una instantánea inmutable de la `Authentication` actual (`anonymous()` es el valor cero no autenticado), y `SecurityContextHolder` es donde una petición lo encuentra:

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

Es `static` a propósito — la misma forma que tiene el propio `SecurityContextHolder` de Spring — porque es un accesor local a la petición, no un colaborador inyectado, respaldado por la propia fachada `Context` de Laravel (por petición, seguro bajo Octane). `getContext()` nunca exige una comprobación de nulos en el sitio de la llamada: un contexto no establecido resuelve automáticamente a `SecurityContext::anonymous()`. La garantía que sostiene todo lo demás, contra la fuga del principal de una petición hacia la siguiente, es que cada filtro de autenticación llama a `clearContext()` en un bloque `finally` al salir — PHP siempre ejecuta un `finally`, así que ningún camino de excepción puede saltárselo.

---

## Autenticar una petición: `DaoAuthenticationProvider`

`DaoAuthenticationProvider` comprueba un par de usuario/contraseña contra un `UserDetailsService`, y está escrito específicamente para neutralizar ataques de temporización por enumeración de nombres de usuario:

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

Un nombre de usuario desconocido se hace deliberadamente **indistinguible** de una contraseña incorrecta: ante `UsernameNotFoundException`, el proveedor igualmente ejecuta una verificación real de contraseña — contra un hash ficticio fijo, precalculado en el momento de la construcción con el *mismo* codificador y el mismo factor de coste que usan los usuarios reales — antes de lanzar exactamente la misma `BadCredentialsException` genérica, con el mismo mensaje, que produce una contraseña genuinamente incorrecta. Ninguna diferencia de temporización, ninguna diferencia de contenido. Los fallos de estado de cuenta (`locked`/`disabled`) se comprueban solo **después** de una contraseña correcta — el orden recomendado por OWASP — de modo que un atacante sin la contraseña correcta no aprende nada sobre si la cuenta siquiera existe, y mucho menos sobre su estado. Cuando tiene éxito, el token devuelto se construye de nuevo mediante `Authentication::authenticated()`, que nunca arrastra consigo la contraseña en bruto.

---

## JWT: caducidad obligatoria, arranque rechazado ante un secreto débil

`JwtService` hace cumplir dos invariantes de fallo seguro que un desarrollador nunca tiene que recordar por su cuenta:

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

La primera invariante se dispara en la **construcción**, no en el primer uso: un secreto más corto de 32 bytes, o uno de una lista fija de marcadores de posición comunes (`'changeme'`, `'secret'`, `'password'`, una cadena vacía…), lanza `WeakSigningSecretException` — la aplicación se niega directamente a arrancar en vez de firmar tokens con una clave adivinable. La segunda se dispara en cada `decode()`: incluso una firma criptográficamente válida se rechaza sin más si el token no lleva un claim `exp`, de modo que una caducidad olvidada nunca puede producir un token eternamente válido. `firefly.security.jwt.enabled` y `firefly.security.oauth2.resource_server.enabled` son además **mutuamente excluyentes** — habilitar ambos se rechaza en el arranque, igualando el modelo de Spring de un único mecanismo de portador.

---

## Reglas de URL que deniegan por defecto: `HttpSecurity`

`HttpSecurity` es un DSL fluido que construye una lista ordenada de reglas de URL, evaluada primera-coincidencia-gana por `HttpSecurityFilter`, con **ninguna coincidencia en absoluto denegando la petición**:

<!-- source: packages/security/src/Access/HttpSecurity.php -->
```php
public function requestMatcher(string $pattern): self
{
    $this->pending = self::normalisePattern($pattern);

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
// …
private static function normalisePattern(string $pattern): string
{
    $normalised = ltrim($pattern, '/');

    return $normalised === '' ? '/' : $normalised;
}
```

Cada regla compila hacia la **exacta misma gramática de expresiones** que usa `#[PreAuthorize]` más abajo — `HttpSecurity` es un constructor que emite cadenas con la forma `permitAll()`/`hasRole('ADMIN')`, no un segundo motor de autorización. `HttpSecurityFilter` evalúa las reglas compiladas contra la ruta de la petición y, en la primera coincidencia de patrón, comprueba la expresión de la regla; una petición que no coincide con **ninguna** regla se deniega — de fallo seguro, no de apertura por defecto. Una denegación se renderiza como `401` cuando el contexto es anónimo (autentícate primero) y como `403` cuando está autenticado pero sin privilegios suficientes.

`requestMatcher()` es la única puerta por la que pasa toda regla — `anyRequest()` y `fromConfig()` la llaman las dos — y normaliza el patrón antes de guardarlo. El filtro compara con `Str::is($rule->pattern, $request->path())`, y el `path()` de Laravel nunca lleva barra inicial, así que `normalisePattern()` la quita: `/api/*` y `api/*` son la **misma** regla. Un `'/actuator/health'` escrito como lo escribe medio mundo — como lo deletrea cada ruta de un `RouteManifest` — es una regla viva, no la regla silenciosamente muerta que antes era, en la que la petición no coincidía con nada, la denegación por defecto la rechazaba y el operador leía un `401` en la única ruta que había abierto explícitamente. La ruta raíz es la única excepción que conserva su barra, porque `path()` responde `'/'` para ella y nunca `''`. Lo que la normalización **no** hace es reescribir una plantilla de ruta: un patrón se compara contra `api/orders/7`, nunca contra `api/orders/{id}`, así que un patrón que lleva un marcador de posición sigue siendo una regla muerta que ninguna normalización puede rescatar — `api/orders/*` es la grafía que lo cubre.

`assertSafeValue()` rechaza cualquier valor de rol/autoridad que contenga una comilla simple, por una razón que importa muchísimo en cuanto leas la siguiente sección: una cadena de rol o autoridad legítima nunca contiene una, pero un valor que sí lo hiciera podría, si no, empalmar gramática adicional dentro del literal de expresión fijo en el que se interpola.

::: figure art/figures/security-filter-chain.svg | Figura 10.1 — HttpSecurityFilter es el último eslabón de una cadena ordenada: el valor #[Order] real de cada filtro, los dos filtros de framework antepuestos a todos ellos, y el DelegatingAuthenticationEntryPoint al que llega una denegación anónima — una redirección al login, un desafío Basic o un 401.

Tres de esos números hacen un solo trabajo entre los tres. `OAuth2AuthorizationRequestRedirectFilter` (`-89`) inicia un inicio de sesión con un proveedor, `OAuth2LoginAuthenticationFilter` (`-88`) lo termina, y `OAuth2AuthorizationServerFilter` (`-82`) es lo que encuentra una petición cuando el proveedor eres *tú*. Los dos primeros son `firefly/security-oauth2-client`, el tercero es `firefly/security-oauth2-server`, y ninguno de los dos paquetes necesita al otro: cada mitad funciona contra cualquier contraparte conforme al otro lado.

La Figura 10.2 sigue un inicio de sesión a través de ambas mitades a la vez, porque lo que vale la pena entender del flujo de código de autorización no es ningún paso aislado, sino qué secreto tiene cada parte en qué momento. Empieza arriba a la izquierda con una regla que ya has escrito: `GET /orders` no coincide con ningún `permitAll()`, `HttpSecurityFilter` (`-70`) la deniega, y el punto de entrada hace exactamente lo que hace para el login por formulario — guarda el GET y redirige a `/login`. Lo que cambia es que la página de login lleva ahora un botón por registro, y ese botón va a `/oauth2/authorization/{id}`. A partir de ahí el navegador no vuelve a llevar ningún secreto: el código que trae de vuelta no vale nada sin el verificador PKCE, que nunca salió de la sesión de la parte confiante, y que se gasta en un `POST /oauth2/token` de canal trasero que ningún navegador llega a ver.

::: figure art/figures/oauth2-authorization-code.svg | Figura 10.2 — Un inicio de sesión, de principio a fin: HttpSecurityFilter deniega la petición y el punto de entrada la guarda y redirige a /login, el botón de proveedor de la página de login arranca el viaje de ida y vuelta en /oauth2/authorization/{id} con un state de un solo uso, un nonce y un desafío de código S256, el servidor de autorización ejecuta sus propias páginas de login y consentimiento antes de acuñar un código de un solo uso, y la parte confiante gasta ese código y el verificador en un intercambio de tokens por canal trasero cuyo id token se verifica contra /oauth2/jwks.

---

## Autenticar a una persona: la sesión, el formulario y la página

Todo lo visto hasta ahora en este capítulo autentica una petición que llega llevando su propia credencial — un JWT, una cabecera Basic. Esa es la forma correcta para una API y la equivocada para una persona, que inicia sesión una vez y luego navega. Tres filtros y una página hacen que eso funcione, y los tres se encienden con una sola clave.

### El contexto que sobrevive a una petición

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

`SecurityContextPersistenceFilter` corre en `#[Order(-94)]` — justo después del filtro de cabeceras y **por delante de todos los filtros de autenticación**, así que un principal sostenido por la sesión es lo que ven `HttpSecurityFilter` y las guardas de seguridad de método. Veinte líneas, y cada una de ellas es deliberada:

- **No** carga cuando el portador ya lleva un contexto autenticado. El principal actuante de un test, o un middleware exterior, estableció uno a propósito.
- A la salida guarda solo un contexto que **cambió** durante la petición y que **sigue en el portador**. Nunca borra el almacenado: un filtro de autenticación interior que limpia el portador en su propio `finally` lo hace antes de que corra esta salida, y un portador vacío en ese punto significa «la petición ha terminado», no «cerrar sesión».
- La limpieza del `finally` es la garantía estructural para Octane. Pase lo que pase, nada se filtra a la petición siguiente en el mismo trabajador.

Lo que se almacena nunca lleva una credencial: un principal `CredentialsContainer` — el `User` distribuido lo es — se escribe sin su contraseña codificada.

!!! warning "Los filtros de Firefly son globales; la sesión de Laravel no"
    Los filtros de Firefly son middleware global de kernel, y Laravel arranca la sesión en el grupo de rutas `web`, *más tarde*. Cuando la seguridad de sesión está encendida, un paso de arranque llamado `SessionSecurityBootstrap` empuja `EncryptCookies`, `AddQueuedCookiesToResponse` y `StartSession` a la pila **global** por delante de la cadena de filtros, los quita del grupo `web`, y los excluye en la ruta coincidente en tiempo de despacho. Sin eso, una ruta que llevara el grupo descifraría cookies ya descifradas, las anularía, y acuñaría una sesión nueva — perdiendo el inicio de sesión. Hace falta un driver de sesión; el arranque se niega sin uno.

### El formulario

`firefly.security.form_login.enabled` enciende dos cosas a la vez. `FormLoginFilter` (`-92`) atiende `POST /login` **antes del enrutado**, exactamente como hace el `UsernamePasswordAuthenticationFilter` de Spring — así que no necesita ruta, ni grupo CSRF, ni controlador. Su orden es: primero el token CSRF de sesión (un CSRF de login es un ataque, así que esta comprobación es independiente de `CsrfFilter`), luego el `AuthenticationManager`, luego, si tiene éxito, un identificador de sesión regenerado, el contexto almacenado, los eventos publicados, la cookie de recuérdame cuando se pidió, y una redirección a lo que el punto de entrada guardara.

El test capstone recorre esa vía entera, y es la descripción más clara del mecanismo que hay:

!!! note "`$this->events` en los listados de este capítulo"
    Cada listado de más abajo que lee `$this->events->interactive()`, `->successes()`, `->logouts()` o `->denials()` está sosteniendo un `Firefly\Testing\Double\RecordingAuthenticationEvents` — el doble de grabación consciente de la seguridad que cataloga el Capítulo 12, y que responde a los cinco tipos de evento por nombre en vez de obligar a un test a filtrar una lista mixta por clase. Reemplaza al puerto `ApplicationEventPublisher` y debe vincularse **antes del arranque**, desde `defineFireflyEnvironment()`; el Capítulo 12 explica además el argumento `$forwardTo` que mantiene a los listeners reales oyendo el evento.

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

El identificador de sesión cambia en cada inicio de sesión interactivo — protección contra fijación de sesión, y no es opcional. Un fallo publica un `AuthenticationFailure*Event` que lleva el nombre de usuario y la IP de origen y **nunca la contraseña**, y luego redirige a `/login?error`.

### La página que el framework distribuye, y la que se niega a reemplazar

El framework renderiza su propia página de login, con el diseño de la página de error. Es una clase real, `LoginPage`, montada por un paso de arranque — y ese paso de arranque lleva la regla más considerada del paquete:

<!-- source: packages/security/src/Web/Login/LoginRouteRegistrar.php -->
```php
/**
 // …
 * A LOGIN PAGE THE APPLICATION ALREADY OWNS IS LEFT ALONE. Laravel's RouteCollection keeps one route per
 * method and URI and the LAST registration wins, and this pass runs after the application's routes — so
 * mounting unconditionally would replace a Breeze/Fortify-style controller, or any #[GetMapping('/login')],
 * the moment `form_login.enabled` went on, with no log, no refusal and no failing test: the framework page
 * would simply appear where the application's used to be. Spring's rule is the one applied instead: a custom
 * login page belongs to the application (formLogin().loginPage() switches the default page generator off),
 * and the framework generates a page only when nobody else answers that address. So when a GET route at
 // …
 */
final class LoginRouteRegistrar implements BootPass
```

Así que: si ya existe una ruta `GET` en la ruta de login configurada cuando el paso corre — una ruta por atributo, un fichero de rutas, Breeze, Fortify, lo que sea — **no se monta nada**. Todo lo demás sigue funcionando: `HttpSecurityFilter` permite la página por ruta (o la redirección entraría en bucle), el punto de entrada redirige a ella, y `FormLoginFilter` sigue respondiendo al POST antes del enrutado. Tu propio formulario solo necesita enviar el token de sesión a la URL de procesamiento del login.

Entre esos dos extremos hay una opción intermedia: apunta `view` a una plantilla Blade tuya y el framework renderizará esa en su lugar, entregándole un `LoginPageModel` con los campos del formulario y cada enlace de proveedor.

### De dónde salen los usuarios

`AuthenticationManager` pregunta a un `UserDetailsService`, y el que la mayoría de aplicaciones quiere lee su propia tabla:

<!-- source: packages/security/src/User/EloquentUserDetailsService.php -->
```php
/**
 // …
 * The expected schema (every name configurable under firefly.security.users):
 *   email       the username (unique)
 *   password    the ENCODED password, `{id}`-prefixed for the delegating encoder (`{bcrypt}$2y$…`)
 *   enabled     boolean, optional (enabled_column; empty means every account is enabled)
 *   locked      boolean, optional (locked_column; empty means no account is locked)
 *   authorities a JSON list of strings — `["ROLE_USER", "orders:read"]` — or a relation (`roles.name`);
 *               optional (authorities; empty means the model carries none and every account gets [])
 // …
 */
final class EloquentUserDetailsService implements UserDetailsService
{
    public function __construct(private readonly UserStoreSettings $settings) {}

    public function loadUserByUsername(string $username): UserDetails
```

Una consulta por búsqueda, y la fila se mapea al objeto de valor inmutable `User` en vez de entregarse como el modelo — el principal acaba en la sesión y dentro de objetos de evento, y un modelo ahí arrastraría consigo su conexión, sus relaciones y sus atributos.

Un detalle vale más de lo que parece. Un nombre de columna configurado que la fila no lleva se **rechaza**, no se lee como nulo. El `Model::getAttribute()` de Laravel responde `null` para un atributo que una fila no tiene, y nulo es la respuesta equivocada en todas las posiciones aquí — para la columna de bloqueo es la peligrosa, porque `locked_column: 'is_locked'` contra una tabla cuya columna es `locked` dejaría cada fila desbloqueada y apagaría el bloqueo de cuentas sin error en ninguna parte. Renunciar a una columna opcional es la **cadena vacía**, que una errata nunca es.

La tabla `users` de serie de Laravel (`id`, `name`, `email`, `password`) funciona sin ningún cambio de esquema: pon `authorities` a `''`.

---

## Cerrar sesión, y seguir dentro

### Cerrar sesión

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

`LogoutFilter` (`-93`) atiende `POST /logout` — **solo POST, con CSRF comprobado**; un `GET` cae hacia la ruta que haya ahí, que es lo que quieres, porque un enlace que cierra la sesión de la gente es un vector de CSRF. Expira la cookie de recuérdame y cada nombre listado en `delete_cookies`, invalida la sesión, publica `LogoutSuccessEvent`, y redirige.

Esa secuencia vive en un solo bean, `LogoutHandler`, y **cada camino que termina una sesión lo llama** — el filtro de arriba, y el cierre de sesión iniciado por la parte confiante de OpenID Connect cuando el paquete de servidor de autorización está encendido. Así que los tres ajustes que gobiernan el cierre de sesión gobiernan ambos por construcción; un segundo camino no puede obedecer calladamente un subconjunto de ellos.

### Seguir dentro

`TokenBasedRememberMeServices` es el de Spring, incluida la firma:

```
username : expiry : HMAC-SHA256(username : expiry : password-hash, key)
```

Que el **hash de la contraseña** esté dentro de la firma es todo el diseño. Cambia la contraseña y cada cookie pendiente deja de verificar, en todas partes, sin ninguna tabla de tokens del lado del servidor que limpiar. La clave se sostiene con la misma regla que el secreto del JWT y el arranque rechaza una débil.

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

`RememberMeAuthenticationFilter` (`-83`) solo corre cuando la sesión no sostiene ningún principal, y cuando tiene éxito realiza un **inicio de sesión interactivo completo**: un identificador de sesión nuevo, el contexto almacenado, un `InteractiveAuthenticationSuccessEvent`. Así que la cookie se consulta una vez por *sesión*, no una vez por petición. Una cookie manipulada es anónima en vez de un error — no hay nada que reportar, porque cualquiera puede enviar bytes.

Una cuenta deshabilitada o bloqueada se rechaza incluso con una cookie legítima, y el cierre de sesión la expira.

!!! warning "Recordado no es lo mismo que autenticado"
    Un inicio de sesión por cookie está **marcado como recordado**. Es evidencia de que este navegador inició sesión en algún momento del pasado, no evidencia de que la persona esté ahora mismo ante el teclado. Spring traza exactamente esta línea, y es por lo que una página de «cambiar contraseña» o «borrar cuenta» debería exigir una autenticación fresca en vez de aceptar una recordada.

---

## Redirección o 401: lo decide el punto de entrada

Una sola regla de denegación por defecto tiene que producir dos respuestas completamente distintas. Una persona que navega a `/orders` debería acabar en una página de login. Un cliente JavaScript que llama a `/api/orders` debería obtener un 401 sobre el que pueda actuar — y una página de login renderizada dentro de un `fetch()` es el modo de fallo más confuso que un framework web puede producir.

`HttpSecurityFilter` entrega cada denegación **no autenticada** al `AuthenticationEntryPoint` que nombre `firefly.security.http.entry_point`:

| Modo | Qué recibe una denegación anónima |
|---|---|
| `auto` (el de por defecto) | un navegador recibe la página de login con la petición guardada; una aplicación con Basic habilitado responde `401` + `WWW-Authenticate: Basic`; si no, un 401 para que lo renderice firefly/web |
| `login` | siempre la redirección a la página de login — **rechazado en el arranque** cuando no hay ningún login habilitado, porque no habría a dónde mandar a nadie |
| `challenge` | siempre `401` + `WWW-Authenticate: Basic` |
| `problem` | siempre el 401, como `application/problem+json` o la página HTML de 401 |

«Un navegador» no es una conjetura sobre el `User-Agent`. Es la propia negociación de contenido de la página de error, `ErrorPageRenderer::prefersHtml()`: la petición nombra `text/html` (o `application/xhtml+xml`), no es ni una `XMLHttpRequest` ni una llamada con `wantsJson()`, y no está bajo `firefly.web.error-page.json-paths`. La misma comprobación que usa la página de error, reutilizada en vez de reinventada — así que las dos nunca pueden discrepar:

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

Lee la última línea. Una denegación **anónima** no publica nada — no es un evento de seguridad, es alguien que todavía no ha iniciado sesión, y un log lleno de ellas es ruido que esconde lo de verdad. Una denegación **autenticada** sí es lo de verdad: sigue siendo un 403 y publica `AuthorizationDeniedEvent` nombrando el principal, el sujeto y la expresión que lo rechazó:

<!-- source: packages/security/tests/Web/EntryPoint/EntryPointFlowTest.php -->
```php
    $this->followSession($signedIn)->getJson('/admin/panel')->assertStatus(403)->assertJson(['code' => 'ACCESS_DENIED']);

    expect($this->events->denials())->toHaveCount(1)
        ->and($this->events->denials()[0]->authentication->getName())->toBe('ada')
        ->and($this->events->denials()[0]->subject)->toBe('GET /admin/panel')
        ->and($this->events->denials()[0]->expression)->toBe("hasRole('ADMIN')");
```

Dos llamadores alcanzan el punto de entrada: la denegación anónima de `HttpSecurityFilter`, y el `/oauth2/authorize` del servidor de autorización cuando necesita a la persona con la sesión iniciada. `HttpBasicFilter` deliberadamente **no** — responde con su propio `BasicAuthenticationEntryPoint` diga lo que diga `entry_point`, exactamente como el `BasicAuthenticationFilter` de Spring conserva el suyo. Un llamador que presentó credenciales Basic ya ha elegido su mecanismo, y redirigirlo a una página HTML es la respuesta equivocada para un cliente de API.

---

## Seguridad de método: la gramática de expresiones de lista blanca cerrada

Esta es la sección hacia la que ha estado construyendo el resto del capítulo. `#[PreAuthorize]`, `#[Secured]` y `#[RolesAllowed]` protegen todos un método con una expresión booleana:

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

Los `code:` y `message:` de `#[PreAuthorize]` son lo que lee el **cliente** cuando la expresión rechaza. Sin ellos la guarda responde `403 ACCESS_DENIED` con la frase propia del framework y las autoridades que la expresión nombró; con ellos responde el código de producto y la redacción de la aplicación. `#[Secured]` y `#[RolesAllowed]` no tienen hueco para palabras y usan siempre las del framework.

`#[Secured]`/`#[RolesAllowed]` son azúcar de conveniencia que `MethodSecurityScanner` compila hacia una expresión `hasAnyAuthority(...)`/`hasAnyRole(...)` — todo se reduce en última instancia a la única gramática que `#[PreAuthorize]` escribe directamente. Y esa gramática es deliberada, demostrablemente cerrada: `SecurityExpressionEvaluator` es un tokenizador hecho a mano más un analizador de descenso recursivo, y **nunca** llama a `eval()`, `create_function()` ni `call_user_func()` sobre nada derivado de la cadena de expresión. El despacho de funciones es un `match` fijo de antemano:

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

Diez nombres de función, en total — `hasRole`, `hasAnyRole`, `hasAuthority`, `hasAnyAuthority`, `hasScope`, `hasAnyScope`, `hasPermission`, `isAuthenticated`, `permitAll`, `denyAll`, más las referencias `#param` — y no hay nada más alcanzable. Los mismos diez se escriben dos veces a propósito: el primer `match` es el modo de solo análisis del tokenizador de lista blanca, el que `HttpSecurity` y `MethodSecurityScanner` usan para rechazar una expresión inválida en el ARRANQUE y no en la petición que da la casualidad de alcanzarla, y el segundo es la evaluación propiamente dicha. La gramática que acepta el tokenizador es booleana `and`/`or`/`not` (escrita `&&`/`||`/`!` a nivel de carácter), paréntesis, literales de cadena entre comillas simples y referencias a parámetros `#identificador` — y nada más:

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

!!! warning "Lo que NO está en la gramática"
    No hay `==`, no hay `!=`, ningún operador de comparación de ningún tipo, y ninguna navegación `.propiedad`. No puedes escribir `#command.ownerId == authentication.name` — el tokenizador no tiene noción alguna de un acceso por punto ni de un operador de igualdad, así que ese texto o falla al tokenizar o falla al analizar, y en cualquiera de los dos casos `evaluate()` captura la `ExpressionParseException` y devuelve `false`. Esto no es un descuido; es lo que hace que la gramática sea demostrablemente cerrada. Si una comparación así parece justo lo que necesitas, la vía de escape prevista es `hasPermission(#target, 'action')` respaldada por un bean `PermissionEvaluator` a medida que hace la comparación de campo real en código PHP de verdad — la gramática despacha hacia tu propia lógica, nunca intenta convertirse ella misma en un lenguaje de expresiones de propósito general.

    Todo modo de fallo — un nombre de función desconocido, un carácter suelto, un literal de cadena malformado, una excepción lanzada por un `PermissionEvaluator` a medida a mitad de la evaluación — se captura con el mismo `catch (\Throwable)` y se convierte en `false`. Una expresión hostil o rota solo puede denegar; nunca puede conceder por accidente.

`SecurityExpressionRoot` es el **único** objeto que las llamadas a función de una expresión pueden alcanzar jamás — no hay forma de llamar a ninguna otra cosa, porque no existe sintaxis de acceso a propiedad ni forma de obtener una referencia a ningún otro objeto:

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

`hasRole('ADMIN')` y `hasRole('ROLE_ADMIN')` son equivalentes — un nombre de rol desnudo se normaliza anteponiendo `ROLE_` si todavía no lo lleva, igualando exactamente la propia convención de Spring. `#param` — tokenizado como un `#` desnudo seguido de un identificador — se resuelve mediante `arg()` contra los propios nombres de los parámetros del método protegido, enlazados posicionalmente por `MethodSecurityScanner` en el momento del escaneo.

Aquí está toda la idea hecha concreta, en código real y distribuido — `WithdrawHandler`, real, de `samples/lumen/src/Application/Command/WithdrawHandler.php`:

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

Lee con atención el segundo párrafo de ese docblock — es toda la lección en miniatura. La forma *ingenua* de expresar "solo el propio dueño del monedero puede retirar" sería algo como `#command.ownerId == authentication.name`, y la gramática sencillamente no puede decir eso: no hay operador `.` ni `==`. La alternativa real, distribuida, es más burda pero genuinamente expresable: conceder una autoridad `WALLET_OWNER` a quien deba tener permiso, y comprobar `hasRole('WALLET_OWNER')`. Una comprobación de propiedad genuina por recurso, cuando la necesitas, pasa por `hasPermission(#command, 'withdraw')` y un bean `PermissionEvaluator` a medida que hace la comparación real en PHP ordinario — la gramática despacha hacia tu código, no intenta volverse lo bastante expresiva como para escribir ella misma la comparación.

---

## Aplicación en el bus: cerrando el círculo del `AllowAllAuthorizer` del Capítulo 7

El Capítulo 7 te mostró la tubería de `CommandBus`/`QueryBus` ejecutando una etapa `authorize()` que por defecto usa `AllowAllAuthorizer` — permitir todo. `firefly/security` es lo que de verdad lo reemplaza, y lo hace solo cuando tú decides activarlo: `SecurityAutoConfiguration` está en `#[Order(500)]`, estrictamente por debajo del `#[Order(1000)]` de `CqrsAutoConfiguration`, así que — el mismo patrón de ganar-la-carrera que te mostró el Capítulo 8 para el outbox de Postgres en `#[Order(900)]` — sus beans se registran primero y satisfacen el propio `#[ConditionalOnMissingBean(CommandAuthorizer::class)]` de `CqrsAutoConfiguration` antes de que el valor por defecto de permitir-todo tenga siquiera oportunidad de enlazarse, pero **solo** cuando `firefly.security.enabled=true` (cada bean de seguridad aquí está además condicionado a ese indicador maestro):

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

`MethodSecurityMessageEnforcer` es el punto de encuentro compartido al que delegan tanto `SecurityCommandAuthorizer` como su gemelo del lado de consulta `SecurityQueryAuthorizer`: resuelve la clase + método manejador del mensaje a partir del **mismo `HandlerManifest`** que introdujo el Capítulo 7, busca cualquier regla `#[PreAuthorize]`/`#[Secured]`/`#[RolesAllowed]` compilada para ese `Class::method`, y — solo si existe una — la evalúa contra el estado actual de `SecurityContextHolder`, con el propio mensaje enlazado al único `#param` de la regla:

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

Léelo como cuatro retornos tempranos y una línea de trabajo. Seguridad apagada, ningún manejador registrado para este mensaje, o ninguna regla compilada para ese manejador — cada caso pasa sin comprobación, porque la seguridad de método aquí es **aditiva**, nunca una segunda puerta que deniegue por defecto (ese trabajo pertenece por entero a `HttpSecurityFilter`). Cuando *sí* se encuentra una regla, la última línea se la entrega a `MethodSecurityEvaluator::before()`, el único evaluador que comparten todas las costuras de aplicación, con el propio mensaje vinculado al único `#param` de la regla.

Así que cuando `WithdrawHandler::handle()` lleva una regla, esto es lo que la ejecuta, **antes** de que `handle()` mismo llegue a ejecutarse. Una denegación se convierte en `AuthorizationException`, que `DefaultCommandBus::send()` (Capítulo 7) envuelve en `CommandProcessingException`, exactamente como cualquier otro fallo del lado del manejador.

---

## Seguridad de método en todas partes, no solo en el bus

El bus fue el *primer* sitio donde estas reglas se hicieron cumplir. Ya no es el único, y la diferencia importa: un `#[PreAuthorize]` sobre un `#[Service]` corriente solía ser un comentario.

Ahora hay tres costuras de aplicación, y las tres comparten un único `MethodSecurityEvaluator`, así que no pueden discrepar sobre lo que significa una expresión:

| Costura | Protege | Se ejecuta |
|---|---|---|
| El bus CQRS | un manejador de comando o consulta | antes de `handle()`, vía `SecurityCommandAuthorizer`/`SecurityQueryAuthorizer` |
| El despachador de controladores | una acción de controlador | `ControllerSecurityGuard::check()` antes, `afterInvocation()` después |
| **La cadena de proxy** | **cualquier bean estereotipado** — `#[Service]`, `#[Component]`, `#[Repository]` | como el interceptor más externo del proxy generado |

La tercera es la nueva, y no es un mecanismo nuevo: es el proxy del Capítulo 9, con una segunda clase de advice encima. `MethodSecurityAdviceSource` aporta sus filas al mismo `ProxyPlan`, y declara en qué punto de la cadena corre su interceptor:

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

**Orden `100`, frente al `1000` del advice transaccional.** Menor corre por fuera, así que la seguridad rechaza *antes* de que se abra transacción alguna — que es el único orden sensato, porque abrir y revertir una transacción para descubrir que el llamador nunca tuvo permiso es trabajo hecho para nada.

`inertWhenUnbound` es la otra mitad de la misma idea. `MethodSecurityInterceptor` existe como bean solo bajo `firefly.security.enabled`, y su ausencia es el estado documentado del framework de «las anotaciones son inertes hasta que la seguridad esté encendida» y no una mala configuración — así que el registro entrega al proxy un `PassThroughInterceptor` en vez de hacer fallar el arranque, que es lo que hace con cualquier *otro* advice cuyo interceptor falte.

### Las reglas que necesitan el valor devuelto

Una vez que la costura puede correr *después* de la llamada, dos atributos más se vuelven posibles — y esto es exactamente lo que el bus nunca pudo hacer:

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

- `#[PostAuthorize]` corre después de `proceed()` con **`#returnObject` vinculado a lo que el método devolvió**. «Puedes llamar a esto, pero solo si lo que volvió es tuyo» es una regla que no puedes expresar antes de la llamada, porque la respuesta es lo que la decide.
- `#[PostFilter]` corre sobre un resultado iterable con **`#filterObject` vinculado a cada elemento**, conservando los que la expresión acepta. Los arrays conservan sus claves; un Enumerable se filtra en su misma especie; un retorno no iterable se rechaza en vez de dejarse pasar calladamente.
- `#[PreFilter]` es la imagen especular a la entrada, nombrando con `filterTarget:` qué parámetro filtra.

Fíjate en `code:` y `message:` sobre el `#[PostAuthorize]`. El código de producto de un rechazo y la frase que lee una persona viven en la misma línea que la regla que los produjo, junto al método que protegen — en vez de en algún servicio que quien llama tenga que acordarse de invocar primero. Sin ellos se aplica la redacción propia del framework: un 403 que dice *«You do not have permission to do this.»*, con las autoridades que la regla pedía transportadas como miembro de extensión del RFC 9457, y la clase y el método protegidos enviados al **log** en vez de al cable, porque el nombre de una clase PHP no es algo que un desconocido deba leer en un panel.

!!! warning "Dos rechazos de arranque que quieres, y uno al que tienes que apuntarte"
    Un bean `final` que lleva reglas que solo un proxy podría hacer cumplir se rechaza en tiempo de escaneo — el proxy no puede extenderlo, así que compilar la fila produciría una regla que nada ejecuta. Y `SecurityWiringPass` se niega a arrancar sobre un `security-methods.php` escrito sin un `proxy-plan.php` al lado: las reglas compilarían y las de nivel de bean no se harían cumplir en silencio. El que tienes que pedir es `firefly.security.method.strict`, que convierte «no se encontró artefacto compilado» de un recurso de reserva en un fallo de arranque. Ponlo en cualquier imagen que ejecute `firefly:cache`; es la única defensa contra una construcción que se distribuya sin el manifiesto.

La seguridad de método sigue siendo **aditiva**. «Ninguna regla registrada para este método» significa permitir, en cada una de las tres costuras — denegar por defecto es tarea de `HttpSecurityFilter` y de nadie más. Esa es la semántica correcta y tiene un filo, que es para lo que existe `strict`: un manifiesto vacío es indistinguible de una aplicación que no declara ninguna regla.

---

## El principal, inyectado

Un controlador que necesita saber quién llama debería decirlo en su firma. No debería meter la mano en un portador, y desde luego no debería recibir un `principal` que una cadena de consulta pudiera fijar.

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

Cuatro parámetros, cuatro formas de preguntar, y `firefly/security` vincula todos ellos antes de que se consulte siquiera al contenedor — gracias a un puerto que `firefly/web` expone exactamente para esto:

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

Un resolutor ve el plan de vinculación compilado — el nombre del parámetro, su clase, su tipo, sus atributos y su nulabilidad — y lo reclama con `supports()`. `ArgumentResolver` pregunta a los resolutores registrados **antes** que a sus propias clases, así que un parámetro de tipo clase que un resolutor entiende nunca llega a `$container->make()`. `SecurityArgumentResolver` reclama `Authentication`, `UserDetails`, `#[AuthenticationPrincipal]` y `#[CurrentSecurityContext]`.

Las reglas de nulabilidad merecen enunciarse con precisión, porque son las que permiten que una sola acción sirva a dos mecanismos:

| Declaración | Con sesión iniciada | Anónimo |
|---|---|---|
| `Authentication $auth` | la autenticación | **401** |
| `?Authentication $auth` | la autenticación | `null` |
| `?UserDetails $user` | el principal cuando *lo es* | `null` |
| `#[AuthenticationPrincipal] mixed $principal` | lo que sea el principal | el principal anónimo |
| `#[CurrentSecurityContext] SecurityContext $context` | el contexto | el contexto anónimo |

Un principal **con atributo** se entrega solo cuando *es* lo que el parámetro declara, y `null` en caso contrario. Eso es lo que evita un `TypeError` — el principal de un JWT es su **cadena** `sub`, no un `UserDetails` — y es lo que permite que la misma acción sirva a un login por formulario y a un token portador:

<!-- source: packages/security/tests/Web/Argument/PrincipalInjectionFlowTest.php -->
```php
    // A JWT's principal is its `sub` string, not a UserDetails: `#[AuthenticationPrincipal] ?UserDetails` is the
    // null it allowed for, not the string a `?UserDetails` parameter would refuse with a TypeError (a 500).
    $this->withHeader('Authorization', $this->bearerFor('svc-42'))->getJson('/open/principal-user')->assertOk()->assertJson(['user' => null]);
```

Dos hechos más, ambos defensivos. El resolutor se registra **esté o no encendido el interruptor maestro**, así que con la seguridad apagada las anotaciones son inertes — un `null`, un contexto anónimo, un 401 honesto — en vez de que el plan de vinculación ordinario las malinterprete como parámetros de consulta. Y nada de esto lee jamás un principal de la petición:

<!-- source: packages/security/tests/Web/Argument/PrincipalInjectionFlowTest.php -->
```php
it('never reads a principal from the query string, whoever asks', function () {
    // …
    $this->getJson('/open/whoami?principal=admin')->assertOk()->assertJson(['principal' => null]);
```

---

## Los eventos

Cada mecanismo de este capítulo reporta lo que hizo, a través de `AuthenticationEventPublisher` sobre el `ApplicationEventPublisher` ordinario del contexto. Así que un método `#[AsEventListener]` los recibe exactamente igual que cualquier otro evento de aplicación del Capítulo 8, y `Event::fake()` los ve en un test.

La familia es pequeña y completa — estas son todas las clases de `packages/security/src/Event/`, y no hay otras:

| Evento | Cuándo se publica |
|---|---|
| `AuthenticationSuccessEvent` | cualquier autenticación con éxito, interactiva o no |
| `InteractiveAuthenticationSuccessEvent` | una persona inició sesión: `form`, `basic` o `remember-me` |
| `AuthenticationFailureBadCredentialsEvent` | una contraseña equivocada — **y un nombre de usuario desconocido** |
| `AuthenticationFailureLockedEvent` | las credenciales eran correctas, la cuenta está bloqueada |
| `AuthenticationFailureDisabledEvent` | las credenciales eran correctas, la cuenta está deshabilitada |
| `LogoutSuccessEvent` | una sesión terminó a través de `LogoutHandler` |
| `AuthorizationDeniedEvent` | una regla de URL o de método rechazó a un principal **autenticado** |

Tres de esas filas llevan una decisión en vez de un hecho.

**Un usuario desconocido se reporta como credenciales incorrectas, a propósito.** Un evento distinto de «no existe tal usuario» sería un oráculo de nombres de usuario, y todo el sentido de que `DaoAuthenticationProvider` iguale los dos caminos — el mismo tiempo, la misma respuesta — lo desharía justamente el listener escrito para vigilarlo.

**Una cuenta bloqueada o deshabilitada solo se reporta tras la contraseña correcta.** Si no, los eventos le dirían a un atacante qué cuentas existen, que es el mismo oráculo por una vía más lenta.

**`AuthorizationDeniedEvent` se dispara solo para alguien con la sesión iniciada.** Una denegación anónima es alguien que todavía no ha entrado, y no se publica en ninguna parte; una denegación autenticada es una persona que intentó algo que no tiene permitido, y llega con el principal, el sujeto (`GET /admin/users`, o `App\Reports::totals`) y la expresión que la rechazó.

Lo que ninguno de ellos lleva es una credencial. Un evento de fallo nombra el nombre de usuario y la IP de origen; la contraseña no está en él, y la clase base abstracta que comparten los tres fallos no tiene hueco para una.

!!! tip "Los tres listeners que merece la pena escribir el primer día"
    Cuenta `AuthenticationFailureBadCredentialsEvent` por IP de origen y tienes detección de fuerza bruta. Registra `InteractiveAuthenticationSuccessEvent` con su `mechanism` y tienes una auditoría de inicios de sesión que distingue una contraseña de una cookie. Alerta sobre `AuthorizationDeniedEvent` y estarás vigilando la única denegación que significa que alguien que *sí* es conocido intentó algo que no debía.

---

## Prueba de extremo a extremo

Dos archivos de prueba reales y distribuidos ejercitan la cadena completa — petición HTTP de entrada, `SecurityCommandAuthorizer` haciendo cumplir el `#[PreAuthorize]` de `WithdrawHandler` en el bus, salida como una respuesta RFC-9457. Y lo primero que demuestran es que **una denegación no es una respuesta, sino dos.**

`samples/lumen/tests/Web/WalletRestTest.php`, sin ningún principal establecido en absoluto:

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

**401, no 403** — y la distinción es justo el punto. `MethodSecurityEvaluator` responde a un llamante **anónimo** con una `AuthenticationException`: *autentícate primero*. Reserva el 403 para un llamante que *sí* ha iniciado sesión y aun así carece de la autoridad, que es la siguiente prueba del mismo archivo:

<!-- source: samples/lumen/tests/Web/WalletRestTest.php -->
```php
    $this->postJson("/api/v1/wallets/{$id}/withdraw", ['amount_minor' => 1000])
        ->assertStatus(403)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 403)
        ->assertJsonPath('code', 'ACCESS_DENIED')
        ->assertJsonPath('category', 'security');
```

Las tres costuras de aplicación dan el mismo par de respuestas, porque comparten el mismo evaluador: 401 significa *iniciar sesión ayudaría*, 403 significa *no ayudaría*. Un cliente puede distinguirlas y actuar en consecuencia.

El bus envuelve cualquiera de las dos en una `CommandProcessingException`, que copia el código de error, el estado HTTP y la categoría de la causa — así que el cable lleva `AUTHENTICATION_FAILED` o `ACCESS_DENIED` y no el código genérico del bus. Y en las dos pruebas la aserción sobre el saldo, después, hace trabajo real: demuestra que el débito genuinamente nunca ocurrió, no solo que la respuesta HTTP tuviera apariencia de rechazo.

Concede la autoridad correcta y el mismo comando tiene éxito:

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

La autoridad concedida es `'ROLE_WALLET_OWNER'` — ya con el prefijo `ROLE_` incorporado — y la expresión de `WithdrawHandler` llama a `hasRole('WALLET_OWNER')`, que `SecurityExpressionRoot::hasRole()` normaliza a la cadena idéntica `'ROLE_WALLET_OWNER'` antes de comprobar la pertenencia; las dos formas de escribirlo se encuentran en el medio. El `409` de la segunda llamada es el contraste importante: la autorización y la validación de dominio son dos capas genuinamente independientes — que se te *permita* retirar no dice nada sobre si la retirada en sí es *válida*, y la propia invariante de no-sobregiro de `Wallet::withdraw()` (Capítulo 6) sigue aplicándose exactamente igual que antes.

`samples/lumen/tests/Application/TransferSecurityTest.php` demuestra el mismo guardián directamente contra el `CommandBus`, con el patrón de desenvolver mediante `getPrevious()` que introdujo el Capítulo 7:

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
| `SecurityExpressionEvaluator` | Un tokenizador/analizador hecho a mano, de lista blanca cerrada — sin `eval`, sin `==`, sin navegación `.propiedad`; diez funciones en total (`hasRole`, `hasAnyRole`, `hasAuthority`, `hasAnyAuthority`, `hasScope`, `hasAnyScope`, `hasPermission`, `isAuthenticated`, `permitAll`, `denyAll`) más las referencias `#param` |
| `hasRole('X')` | Normaliza a `hasAuthority('ROLE_X')` — las formas desnuda y con prefijo `ROLE_` son equivalentes |
| `SecurityCommandAuthorizer` / `SecurityQueryAuthorizer` | Las implementaciones reales en `#[Order(500)]` que reemplazan al `AllowAllAuthorizer` del Capítulo 7, solo cuando `firefly.security.enabled=true` |
| `MethodSecurityMessageEnforcer` | Une el `HandlerManifest` de CQRS con el `SecurityMethodManifest` compilado; hace cumplir la regla antes de que `handle()` llegue a ejecutarse |
| `SecurityContextPersistenceFilter` | Carga el contexto desde la sesión en `-94`, guarda lo que cambió, y limpia el portador en un `finally` |
| `FormLoginFilter` / `LoginRouteRegistrar` | `POST /login` atendido antes del enrutado; la página del framework se monta **solo** cuando nadie más reclama esa dirección |
| `EloquentUserDetailsService` | Tu propia tabla de usuarios como almacén — y una columna configurada que la fila no tiene se rechaza, nunca se lee como `null` |
| `LogoutFilter` / `TokenBasedRememberMeServices` | Cierre de sesión solo por POST y con CSRF comprobado; una cookie firmada sobre el **hash de la contraseña**, así que un cambio de contraseña revoca todas |
| `AuthenticationEntryPoint` | Los cuatro modos de `entry_point`; `ErrorPageRenderer::prefersHtml()` — la petición nombra `text/html` o `application/xhtml+xml`, no es una XHR y no está bajo `json-paths` — decide página de login frente a 401 |
| `MethodSecurityAdviceSource` | Seguridad de método sobre **cualquier** bean estereotipado, en el orden de advice 100 — por fuera de la transacción en 1000 |
| `#[PostAuthorize]` / `#[PostFilter]` | Reglas que necesitan el valor devuelto: `#returnObject` después de la llamada, `#filterObject` por cada elemento |
| `SecurityArgumentResolver` | `Authentication`, `UserDetails`, `#[AuthenticationPrincipal]`, `#[CurrentSecurityContext]` vinculados antes de que se le pregunte al contenedor |
| La familia de eventos | Siete eventos; un usuario desconocido se reporta como credenciales incorrectas, y solo se publica una denegación **autenticada** |

---

## Ponlo en práctica {.exercises}

1. **Escribe una expresión rechazada.** Intenta compilar `#[PreAuthorize("#command.ownerId == authentication.name")]` en un manejador de prueba y ejecuta `php artisan firefly:cache`. Confirma que `MethodSecurityScanner` falla de forma ruidosa con una `ConfigurationException` que nombra la clase y el método exactos, en vez de aceptar silenciosamente una expresión que siempre denegaría (o fallaría al analizar) en tiempo de ejecución.
2. **Añade una comprobación real de propiedad.** En una copia de prueba del proyecto, implementa un bean `PermissionEvaluator` que cargue un monedero por id y compare su `owner_id` con `Authentication::getName()`, enlázalo para que reemplace a `DenyAllPermissionEvaluator`, y cambia la expresión de `WithdrawHandler` a `hasPermission(#command, 'withdraw')`. Confirma que un principal sin `WALLET_OWNER` que genuinamente sea dueño del monedero ahora puede retirar, mientras que un dueño distinto no puede.
3. **Adueñate de la página de login.** Añade una acción `#[GetMapping('/login')]` propia a un proyecto de prueba con `form_login.enabled` encendido, arráncalo, y confirma que la página del framework *no* reemplaza a la tuya — después confirma que tu formulario sigue dando acceso a la gente enviando el token de sesión a la URL de procesamiento, porque `FormLoginFilter` responde antes del enrutado de cualquier modo.
4. **Mira al punto de entrada cambiar de respuesta.** Contra una ruta protegida, envía `Accept: text/html` y después `Accept: application/json`, y confirma que obtienes una redirección a `/login` y un `401 application/problem+json` respectivamente. Después pon `firefly.security.http.entry_point` a `problem` y confirma que la petición del navegador también recibe el 401.
5. **Pon una regla sobre un servicio corriente.** Añade `#[PreAuthorize("hasRole('ADMIN')")]` a un método de un `#[Service]` no final que no sea ni controlador ni manejador, ejecuta `php artisan firefly:cache`, y confirma que la llamada se rechaza. Después marca la clase como `final`, vuelve a ejecutar la caché, y confirma que el escáner rechaza en tiempo de compilación en vez de distribuir una regla que nada podría hacer cumplir.
6. **Reproduce la temporización de la mitigación de enumeración.** Mide el tiempo de `DaoAuthenticationProvider::authenticate()` para un nombre de usuario conocido con una contraseña incorrecta frente a un nombre de usuario desconocido, con suficientes iteraciones para suavizar el ruido, y confirma que ambos son estadísticamente indistinguibles — demostrando que la verificación con hash ficticio que describió este capítulo realmente hace su trabajo, y no solo está documentada como si lo hiciera.
