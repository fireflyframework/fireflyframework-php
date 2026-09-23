<span class="eyebrow">Parte III — Coordinar y Asegurar la Aplicación · Capítulo 10A</span>

# OAuth2 y OpenID Connect: Iniciar Sesión, y Ser el Proveedor {.chtitle}

Al terminar este capítulo sabrás cuál de los dos paquetes OAuth2 tienes entre manos y por qué son dos — `firefly/security-oauth2-client` convierte tu aplicación en un **relying party** que identifica a las personas a través de Google, Keycloak, Okta, Entra o cualquier proveedor conforme, y `firefly/security-oauth2-server` la convierte en un **servidor de autorización** que emite los tokens que consume otro. Sabrás qué dos filtros añade la mitad cliente en `-89` y `-88`, sobre qué dos URIs base responden, y qué mete el framework en una petición de autorización en lo que el login por formulario del Capítulo 10 nunca tuvo que pensar: un `state` de un solo uso, un `nonce` y un desafío PKCE S256. Sabrás qué es un `OidcUser` cuando llega a tu controlador, cómo un claim `groups` se convierte en un `ROLE_`, cómo el cierre de sesión alcanza también la sesión del proveedor, y cómo un job en cola llama a una API como la aplicación sin que haya ninguna persona de por medio. Después el cuadro se da la vuelta: el mismo viaje de ida y vuelta, desde el lado que acuña el código — la página de consentimiento, el endpoint de token, la rotación de refresh tokens con detección de reutilización, la introspección, la revocación, userinfo, el JWKS, y el único comando que genera la clave de la que todo cuelga.

!!! note "Término nuevo: relying party"
    En OpenID Connect la aplicación que envía a una persona a un proveedor para que la identifique es el **relying party** (el RP) — *se apoya* en la afirmación de otro sobre quién es esa persona, en lugar de guardar ella misma la contraseña. El proveedor es el **OpenID Provider** o, en términos de OAuth2 puro, el **servidor de autorización**. Los dos paquetes de este capítulo son esos dos papeles, y las palabras importan porque un mismo despliegue es habitualmente los dos: un portal interno que identifica a su plantilla contra el SSO corporativo *y* emite tokens para la aplicación móvil que le pertenece.

---

## Dos paquetes, dos direcciones

El Capítulo 10 terminó con una cadena de filtros y con el aviso de que tres de sus números pertenecían a paquetes que aquel capítulo no instalaba. Aquí están. Ninguno de los dos paquetes depende del otro — ambos dependen solo de `firefly/security` — e instalar uno nunca arrastra al otro:

```bash
composer require firefly/security-oauth2-client   # ser un relying party: identificar personas en un proveedor
composer require firefly/security-oauth2-server   # ser el proveedor: emitir tú mismo los tokens
```

`firefly/security-oauth2-client` aporta `OAuth2AuthorizationRequestRedirectFilter` (`-89`) y `OAuth2LoginAuthenticationFilter` (`-88`), que se sitúan entre el filtro JWT y el filtro de servidor de recursos de la cadena que ya conoces. `firefly/security-oauth2-server` aporta exactamente uno, `OAuth2AuthorizationServerFilter` (`-82`), que responde **todos** los endpoints que el servidor posee. Las dos mitades vienen apagadas, clave por clave, y las dos se configuran bajo `firefly.security.oauth2.*` — el mismo bloque que el Capítulo 10 usó para `resource_server`, que es el tercer papel de OAuth2 y el único que solo *verifica* un token acuñado por otro.

Una combinación se rechaza de plano en el arranque, y merece la pena leer el rechazo en lugar de su resumen, porque la razón es un orden de filtro y ahora sabes leer uno:

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

`HttpBasicFilter` (`-91`) corre antes que `OAuth2AuthorizationServerFilter` (`-82`), y un cliente OAuth2 que se autentica con `client_secret_basic` envía exactamente la cabecera que el filtro Basic fue construido para consumir. Con los dos interruptores encendidos, cada cliente confidencial de tu despliegue recibiría un `401` como login de *usuario* fallido — con su evento de fallo publicado — antes de que el filtro del servidor de autorización llegara siquiera a ver la credencial. Nada de ese fallo mencionaría OAuth2. Así que el arranque rechaza la pareja y nombra las dos claves, que es la regla permanente del framework ante una combinación que solo podría producir un error de ejecución desconcertante. La misma estática rechaza tres más: el servidor sin `firefly.security.enabled`, el servidor sin nada que lleve un principal entre peticiones, y el servidor junto a `firefly.security.jwt.enabled` (cuyo filtro HMAC en `-90` rechazaría cada token RS256 que este servidor emite).

---

## Iniciar sesión con un proveedor

Todo lo de esta sección es la mitad relying party. Su forma es la de Spring Boot, clave por clave, y la referencia distribuida es donde viven los valores por defecto:

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

Dos bloques comentados llevan todo el modelo. Una **registration** es una relación con un proveedor: un client id, un secreto, los scopes que pedir, el grant que usar. Un **provider** son los endpoints de un emisor. Una registration nombra a su provider (y cuando no lo hace, su propio id se toma como nombre del provider), y cinco nombres son **presets** — `google`, `github`, `okta`, `keycloak` y `microsoft` (alias `entra`) — cuyos endpoints, scopes por defecto, `client_name` y método de autenticación de cliente vienen integrados. Google y GitHub están completos tal cual; los otros tres son por inquilino y necesitan `provider.{id}.issuer_uri`, a partir del cual el **descubrimiento OIDC** lee el resto de `{issuer}/.well-known/openid-configuration`. El descubrimiento es perezoso — una registration se resuelve en el primer uso y queda memoizada para el proceso, así que `firefly:cache` y los arranques de consola nunca necesitan que el proveedor esté accesible — salvo que `discovery.eager` esté encendido, que resuelve todas las registrations en el arranque y hace fallar el arranque ante un emisor caído, exactamente como hace Spring Boot.

Todo lo que puede comprobarse sin una petición **se** comprueba sin ella: un `client_id` ausente, una registration sin secreto sobre un método que necesita uno, un grant desconocido, un provider que no es ni preset ni está configurado, un preset por inquilino sin emisor, un provider sin `issuer_uri` que no deletrea sus endpoints, una clave mal escrita (`client-id` se rechaza, no se ignora) y `login.enabled` sin ninguna registration son cada uno una `ConfigurationException` que nombra la clave, en el arranque.

Con `login.enabled` encendido existen dos URLs. `GET /oauth2/authorization/{id}` inicia un login y `GET /login/oauth2/code/{id}` lo termina — los valores por defecto de `login.authorization_endpoint_base_uri` y `login.redirection_endpoint_base_uri`, y el segundo es además la ruta a la que apunta la plantilla `redirect_uri` por defecto. Las dos las responde su propio filtro, *antes* de `HttpSecurityFilter` (`-70`), así que ninguna necesita una regla de URL propia. La página de login del framework del Capítulo 10 gana un botón "Sign in with {client_name}" por cada registration de tipo `authorization_code` — detrás del formulario de contraseña cuando el login por formulario también está encendido, y sola cuando no lo está.

::: figure art/figures/oauth2-authorization-code.svg | Figura 10A.1 — El viaje de ida y vuelta del código de autorización con PKCE: el navegador, el relying party y el servidor de autorización, con todas las rutas de endpoint reales.

Lee la figura de izquierda a derecha y fíjate en lo que el navegador nunca lleva. La URL de inicio construye una petición de autorización y la guarda en la sesión; solo viajan por el navegador sus mitades públicas — el `state`, el `nonce`, el `code_challenge`. El verificador del que se derivó el desafío se queda atrás, y se gasta en un `POST` de canal trasero que el navegador nunca hace. Tres valores aleatorios, un generador:

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

El trayecto de vuelta es donde viven los rechazos, y el orden en que corren es en sí mismo una decisión. La petición de autorización guardada se **saca** de la sesión primero — un solo uso, pase lo que pase después — de modo que un callback reproducido no encuentra nada contra lo que comparar. Después, en orden: un proveedor que respondió `?error=` devuelve su propio código RFC 6749; no haber petición guardada (o que sea de otra registration) es `authorization_request_not_found`; un `state` que no coincide, comparado con `hash_equals`, es `invalid_state_parameter`; una URL de callback que no es exactamente el `redirect_uri` de la petición es `invalid_redirect_uri`; un código ausente es `invalid_request`. Solo entonces se canjea el código.

Lo que vuelve se examina antes de identificar a nadie. Para una registration `openid`, la firma del id token se verifica contra el JWKS del proveedor (cacheado durante `jwk_set.cache_ttl`), y después se comprueban `iss`, `aud`, `azp`, `iat`, `sub` y el `nonce` según OIDC Core §3.1.3.7 con `clock_skew` segundos de holgura; userinfo se consulta cuando el proveedor tiene ese endpoint y se concedió un scope `profile`, `email`, `address` o `phone`, y su `sub` debe coincidir con el del id token. Una registration OAuth2 plana — sin `openid` — no tiene id token que comprobar y se identifica solo con `user_info_uri`. Después se regenera el id de sesión, se guarda el `SecurityContext` igual que lo guarda el login por formulario del Capítulo 10, y se envía al navegador a la petición que se guardó cuando fue rechazada por primera vez, o a `login.default_success_url`.

!!! warning "Un 503 del proveedor no es un login rechazado"
    El descubrimiento, la descarga del JWKS y userinfo pasan todos por el cliente Http de Laravel con los dos timeouts acotados en cinco segundos — el valor por defecto de Laravel es treinta, que es el límite de ejecución de PHP, así que un emisor lento sería un error fatal en lugar de una página de error. Cuando uno de ellos es genuinamente inalcanzable el resultado es un **503**, no un 401 ni una redirección a `/login?error`: no se examinó nada de las credenciales de la persona, y decirle "no se pudo iniciar sesión" la mandaría a probar una contraseña que no tiene. La página de login omite el proveedor cuyo descubrimiento esté caído en ese momento, con un aviso en el log, en lugar de tumbar con él todas las demás formas de entrar.

---

## La persona en tu controlador

Tras un login correcto el principal de la sesión es un `OidcUser` (o un `OAuth2User` para una registration sin `openid`), y el `SecurityArgumentResolver` del Capítulo 10 lo inyecta sin cableado adicional. El controlador de fixture del propio paquete recoge todas las formas de leer uno:

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

`OidcUser` extiende `OAuth2User` y `ClaimAccessor`: además de `getName()`, `getAttributes()` y `getAuthorities()` tiene `getIdToken()`, `getUserInfo()`, `getSubject()`, `getEmail()`, `getFullName()`, `getPreferredUsername()` y el par `getClaim()`/`hasClaim()`. Sus claims son los del id token con los de userinfo escritos encima — la precedencia de Spring — y `getName()` lee el claim que nombre `provider.{id}.user_name_attribute` (`sub` por defecto; `id` para GitHub).

Tres de esos valores devueltos merecen una pausa, y el test que los fija lo dice en voz alta:

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

`'idTokenValue' => ''` es el primero. `DefaultOidcUser` es un `CredentialsContainer`, exactamente igual que el `User` distribuido del Capítulo 10, así que la copia que guarda la sesión lleva el valor **crudo del id token en blanco** mientras sobrevive cada claim decodificado de él. El id token crudo, el access token y el refresh token viven en un único sitio — la entrada de cliente autorizado — y esa entrada está **cifrada** con la clave de la aplicación. Un principal en una sesión es un conjunto de hechos sobre una persona, no una credencial portadora que alguien pudiera sacar de un fichero de sesión.

El segundo es que `/api/engineers` responde `403`. El proveedor le concedió a `ada` un claim `groups` con `['engineering']`, y nada en OAuth2 dice qué significa un grupo para tu aplicación — así que por defecto no significa nada, y `hasRole('ENGINEERING')` es falso. Las autoridades que lleva un login OAuth2 son `OIDC_USER` (una `OidcUserAuthority` que carga los claims) u `OAUTH2_USER`, más un `SCOPE_x` por cada scope concedido — la misma grafía que produce el filtro de servidor de recursos del Capítulo 10, y por eso `hasScope('email')` lee las dos sin saber qué filtro autenticó la petición. Convertir un claim en un rol es una decisión de la aplicación, y tiene un puerto:

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

Enlaza ese `#[Bean]` y el `/api/engineers` de ese mismo test responde `200`, porque `GrantedAuthoritiesMapper` recibe la lista concedida — con la autoridad que carga los claims primero — y devuelve la lista que llevará la `Authentication`. El principal conserva la concedida, así que un mapper puede añadir sin borrar lo que el proveedor dijo realmente.

El tercero es que la `Authentication` recuerda además **qué** registration identificó a la persona, en sus atributos, y se lee de vuelta con `OAuth2AuthenticationToken::registrationId($authentication)`. Eso no es contabilidad: cerrar sesión y llamar a una API necesitan las dos saber con qué proveedor hablar, y las dos secciones siguientes son esas dos necesidades.

---

## Cerrar la sesión también fuera

`logout.oidc_initiated` enlaza un bean sobre el puerto `LogoutSuccessHandler` que presentó el Capítulo 10. `LogoutFilter` (`-93`) pregunta a su manejador de éxito **antes** de invalidar la sesión — que es la única razón por la que el manejador todavía puede leer el cliente autorizado cifrado y encontrar el id token crudo que enviar:

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

Cada rama que devuelve `null` es una rama en la que la persona sigue quedando fuera de *esta* aplicación — el manejador solo decide si el navegador visita además al proveedor. Esa es la forma correcta para un manejador en esta posición: un proveedor sin `end_session_endpoint`, una registration que alguien borró de la configuración esta mañana, o un proveedor con el descubrimiento caído no pueden convertir "cerrar sesión" en una página de error. Tres de los cuatro casos se registran a nivel de aviso, para que un operador vea que la sesión del proveedor está sobreviviendo a la de la aplicación en lugar de adivinarlo.

`post_logout_redirect_uri` vale por defecto `{baseUrl}/login?logout` — la página de login del framework con su aviso de sesión cerrada, la misma a la que aterriza el login por formulario — y tiene que estar **registrada en el proveedor**, que es el único paso de todo esto que no está en tu propio fichero de configuración. El viaje completo se conduce de extremo a extremo en Chromium:

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

## Llamar a una API como la aplicación

Identificar a una persona es una de las dos cosas que hace un cliente OAuth2. La otra es guardar un token para que *tu* código pueda llamar a la API de otro — un job nocturno que publica facturas, un controlador que lee el catálogo de un socio. Ahí no hay navegador alguno, y por eso `firefly.security.oauth2.client.enabled` deliberadamente **no** exige `firefly.security.enabled`: un worker de colas con un token saliente no tiene seguridad entrante de la que hablar.

`OAuth2AuthorizedClientManager::authorize()` es el único punto de entrada, y son los dos managers de Spring en una clase porque lo único que los distingue es dónde se busca el cliente:

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

Una registration `client_credentials` es el token propio de la aplicación: se busca en el `OAuth2AuthorizedClientService` (la caché de Laravel, cifrada), se pide cuando falta o cuando está a menos de `clock_skew` segundos de caducar, y se cachea — un token por pool de procesos, no uno por petición. Una registration `authorization_code` es el token de una *persona*: se lee primero de la sesión cuando la petición en curso tiene una (el login lo dejó ahí, y la petición es lo que lo ata a ese navegador), si no del servicio de caché bajo el nombre del principal — el camino que toma un job en cola — y se refresca con el refresh token cuando está a punto de caducar, conservando el refresh token rotado y el id token y escribiendo el resultado en los dos almacenes. Sin nada que devolver y sin nada con lo que refrescar, la respuesta es una `ClientAuthorizationRequiredException`: un **401 `CLIENT_AUTHORIZATION_REQUIRED`** que dice, en la práctica, *inicia sesión primero con esa registration*.

Fíjate en lo que guarda un `OAuth2AuthorizedClient`: el **id** de la registration, el nombre del principal y los tokens — nunca la `ClientRegistration` en sí. Un secreto de cliente, por tanto, nunca entra en una sesión ni en una entrada de caché, haga después la aplicación lo que haga con el objeto.

Rara vez llamarás tú mismo al manager, porque hay una macro por encima:

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

`Http::oauth2Client('billing')->get($url)` es `Http::get($url)` con un bearer encima, y sigue siendo una llamada Http de Laravel corriente en todo lo que le importa a un test: `Http::fake()` la intercepta, el middleware de cliente de la ola de observabilidad la traza, y `->retry()` sigue funcionando. `Http::oauth2Client('corp', 'ada')` nombra al principal para una registration ligada a una persona. Lo único que hay que recordar del almacén respaldado por caché es que `authorized_client.cache_ttl` (un día, por defecto) es cuánto sobrevive ahí un cliente renovable; una registration cuyos tokens deban durar más — un worker actuando como una persona durante semanas — quiere un enlace propio de `OAuth2AuthorizedClientService` sobre una tabla, que es un solo `#[Bean]`.

---

## Levantar tu propio servidor de autorización

Dale la vuelta al cuadro. `firefly/security-oauth2-server` es el modelo de Spring Authorization Server dentro de tu aplicación: clientes registrados, el grant de código de autorización con PKCE y una página de consentimiento, client credentials, refresh tokens, access tokens JWT u opacos, y el documento de descubrimiento que le cuenta a todos los demás dónde está todo eso. Su bloque de configuración es más largo que el del cliente porque es una superficie de protocolo y no una relación, pero cada valor de abajo es un valor por defecto que puedes ignorar hasta que lo necesites:

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

Esas rutas — más los dos documentos `/.well-known/` que las anuncian — son lo que responde `OAuth2AuthorizationServerFilter` (`-82`), y su posición en la cadena contesta dos preguntas que la gente hace de inmediato. Corre *después* del filtro de sesión en `-94` y de los filtros de bearer — porque el endpoint de autorización necesita el principal que haya — y *antes* de `CsrfFilter` (`-80`) y `HttpSecurityFilter` (`-70`), que es por lo que a un `POST /oauth2/token` de una máquina nunca se le pide un token de sesión y por lo que ninguna regla de URL que deniegue por defecto puede cerrar el servidor sin querer. Tus `http.rules` pueden terminar con `*` → `authenticated` y los metadatos, el JWKS y el endpoint de token siguen respondiendo; los endpoints de autorización y userinfo comprueban ellos mismos el principal. No hay que añadir nada a `http.rules` ni a `csrf.except`, y el único formulario de navegador que el servidor posee — el POST de consentimiento — lo comprueba contra el token de sesión el propio endpoint, exactamente como comprueban el suyo los filtros de login y logout. Una petición cuya ruta no sea ninguna del servidor cuesta una comparación de ruta y sigue de largo.

| Ruta (por defecto) | Método | Responde |
|---|---|---|
| `/.well-known/openid-configuration`, `/.well-known/oauth-authorization-server` | GET | Un documento de descubrimiento (el de OIDC es un superconjunto del de RFC 8414): cada URL de endpoint, los grants, los métodos de autenticación de cliente, `S256` y el algoritmo de firma |
| `/oauth2/jwks` | GET | Las claves públicas — la actual primero, las anteriores después — con `Cache-Control: public, max-age=3600` |
| `/oauth2/authorize` | GET, POST | El endpoint de autorización y el formulario de consentimiento |
| `/oauth2/token` | POST | `authorization_code` (con `code_verifier`), `client_credentials`, `refresh_token`; las credenciales se leen solo del cuerpo, nunca de la query string |
| `/oauth2/introspect` | POST | RFC 7662: `{active: false}` para todo lo desconocido, revocado, caducado o que sea un código |
| `/oauth2/revoke` | POST | RFC 7009: un refresh token se lleva consigo su access token; lo desconocido es `200` |
| `/userinfo` | GET, POST | Los claims del `OidcUserInfoMapper`, para un bearer que lleve `openid` |
| `/connect/logout` | GET, POST | Cierre de sesión iniciado por el RP: la otra cara de la sección anterior |
| `/connect/register` (apagado) | POST | Registro dinámico RFC 7591, detrás de un bearer de un solo uso que lleve `client.create` |

El endpoint de autorización es el que tiene una persona dentro, y corre en un orden fijo: primero el `client_id` y un `redirect_uri` registrado que coincida exactamente — rechazados **en la página**, nunca redirigidos, porque el RFC 6749 §4.1.2.1 dice que un destino de redirección no verificado es justo donde a un atacante le gustaría que fuese el error — y después `response_type=code`, los grants del cliente, los scopes pedidos y PKCE, cada uno de los cuales *sí* es una redirección con `error=`. Solo entonces busca un dueño del recurso, y lo busca en un sitio: el principal que el `SecurityContextRepository` guarda **entre** peticiones. Un bearer autenticado por el filtro de servidor de recursos en `-85` no es un dueño del recurso y no puede canjearse a sí mismo por un código. Sin nadie identificado, toma el relevo el `AuthenticationEntryPoint` del Capítulo 10 — la página de login, con la petición de autorización guardada — y la visita de vuelta aterriza otra vez en `/oauth2/authorize` para encontrarse con la página de consentimiento.

El consentimiento es la página propia del framework, construida sobre el diseño de la página de login y sin JavaScript, salvo que `consent.view` nombre una vista Blade tuya; en cualquier caso el modelo es un `ConsentPageModel` con el nombre del cliente, los scopes como tripletes `{scope, description, approved}`, el token CSRF y la acción del formulario. Aprobar registra un `OAuth2AuthorizationConsent` (fusionado con lo que ya se hubiera aprobado), así que la siguiente petición de autorización con los mismos scopes se salta la página; denegar es `access_denied` de vuelta en el cliente.

Lo que el endpoint de token entrega entonces es un access token de vida corta — por defecto un JWT con los claims del RFC 9068, o 32 bytes aleatorios opacos cuando `access_token.format` es `reference` — y, para un cliente confidencial con el grant, un refresh token. El refresh token es donde vive la regla interesante, y se lee mejor como test que como párrafo:

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

Cuatro reglas en un test. Cada refresco **rota** el token e invalida el access token al que sustituye. Un refresco puede pedir un scope *más estrecho*, y la autorización sigue recordando la concesión original, así que el siguiente refresco puede volver a ensancharse hasta ella. Presentar un refresh token ya superado se trata como un robo y no como un despiste: se revoca la autorización entera — que es por lo que la última línea, usando el token *bueno*, también falla. Y un cliente que genuinamente no pueda rotar puede poner `token_settings.reuse_refresh_tokens`, en cuyo caso recibe de vuelta su propio token, porque un servidor que solo guarda hashes no tiene ningún valor que devolver como eco.

Dos cosas más sobre el almacenamiento, porque deciden si los valores por defecto sobreviven al contacto con un despliegue real. Los tokens se guardan **por hash SHA-256**, nunca por valor, así que un volcado de `oauth2_authorizations` no es un conjunto de credenciales. Y `clients.driver` y `authorizations.driver` valen por defecto `memory`, que quiere decir *este proceso* — bien para un test y para un único servidor de desarrollo, y mal en cuanto hay dos workers, porque un código emitido por uno tiene que poder canjearlo el otro. Ahí la respuesta es `eloquent`, y `php artisan vendor:publish --tag=firefly-oauth2-server-migrations` publica las tablas.

---

## Las claves, y cómo rotarlas

Todo lo anterior cuelga de una clave privada. El framework la genera:

```bash
php artisan firefly:oauth2:keys                              # RSA 2048 → storage/oauth2/private.pem, chmod 0600
php artisan firefly:oauth2:keys --algorithm=ES256            # una clave EC P-256 en su lugar
php artisan firefly:oauth2:keys --out=/run/secrets/oauth2.pem --force
php artisan firefly:oauth2:keys --print                      # a stdout, para un gestor de secretos
```

El comando imprime las dos líneas que necesitas a continuación — la variable de entorno que poner, y el `kid` que publicará el JWKS — y sus opciones son exactamente estas:

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

`--force` existe para que sobrescribir sea deliberado: una clave reemplazada por accidente invalida todos los tokens en vuelo, y ningún mensaje de error de ningún sitio diría por qué. `jwt.key_id` vale por defecto la huella RFC 7638 de la clave pública, lo que significa que cada nodo que corra la misma clave publica el mismo `kid` sin ninguna coordinación.

**Rotar** son dos movimientos y ninguna parada. Genera una clave nueva; mueve la vieja a `jwt.previous_keys` como `[{key, key_id}]` — basta con su mitad pública, puesto que ahora solo necesita *verificar* — y apunta `jwt.signing_key` a la nueva. Los tokens firmados con la clave vieja siguen verificando hasta que caduquen, el JWKS publica las dos, y un servidor de recursos que lea el origen local de claves ve las dos. Publicar dos claves bajo un mismo `kid` se rechaza en el arranque, porque un verificador guarda una clave por id y la pareja haría fallar cada token nuevo en su primer uso — así que un `key_id` explícito tiene que cambiar con la clave.

!!! tip "Sé tu propio servidor de recursos"
    El despliegue más habitual de este paquete es una aplicación que emite tokens *y* los acepta. Enciende `firefly.security.oauth2.resource_server` del Capítulo 10 con `jwks_source: local`, y el filtro leerá el conjunto de claves a través del bean `JwksDocumentSource` que el servidor ya enlaza — sin ninguna descarga HTTP a sí mismo, que en un servidor de desarrollo de un solo proceso sería un interbloqueo. Di `local` explícitamente en lugar de dejar `auto`: `auto` reconoce como "de esta aplicación" únicamente `/.well-known/jwks.json`, y el servidor publica en `/oauth2/jwks`.

---

## Verlo funcionar

Las dos mitades traen una forma de ejercitarlas que no involucra ningún proveedor externo ni red alguna.

Para la mitad cliente es `Firefly\Testing\Security\OAuth2\FakeAuthorizationServer` — un proveedor OpenID Connect entero en una clase. `install()` monta su **canal frontal** como rutas reales sobre la aplicación bajo prueba (`/authorize`, renderizando opcionalmente una página de consentimiento; `/end-session`) y falsea su **canal trasero** con `Http::fake()` para exactamente cuatro URLs: el documento de descubrimiento, `/token`, `/jwks` y `/userinfo`. Cualquier otra cosa queda sin falsear, así que una petición saliente perdida sigue siendo una petición saliente perdida. Los tokens son JWTs RS256 reales firmados con un par de claves generado una vez por proceso; los códigos son de un solo uso, atados a su `redirect_uri` y comprobados contra PKCE; el reloj es `Date::now()`, así que `$this->travel()` caduca tokens. Cada salto queda registrado y cada fallo está a un método de distancia — `overrideIdTokenClaims()`, `signWithUnknownKey()`, `refuseToken()`, `refuseAuthorization()`, `withoutUserInfo()`, `takeDiscoveryDown()`. Y como el plugin de navegador sirve la aplicación en el mismo proceso, ese mismo falso conduce un Chromium de verdad:

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

Para la mitad servidor es `Firefly\Testing\Security\OAuth2\OAuth2ServerTestClient`, que conduce tu *propio* servidor a través del cliente de pruebas de Laravel: `authorize()` con un par PKCE y un state nuevos, `approveConsent()`, `obtainCode()`, `exchangeCode()`, `clientCredentials()`, `refresh()`, `introspect()`, `revoke()` y `userInfo()`. Lee las rutas de endpoint del bean `AuthorizationServerSettings`, así que renombrar una no exige cambio alguno en un test. Lo único en lo que insiste es en que **identifiques a la persona a través de la página de login y lleves la cookie de sesión**: `actingAsPrincipal()` deliberadamente no basta en el endpoint de autorización, porque el endpoint exige el principal que el `SecurityContextRepository` guarda entre peticiones, de modo que ningún doble de prueba puede ser un dueño del recurso sin una sesión detrás. Los ayudantes de máquina no necesitan ninguna identificación.

Y el viaje completo del servidor se conduce también en Chromium, contra un fixture de relying party servido por la misma aplicación:

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

Las últimas cuatro líneas son la parte que conviene guardarse: un token que acuñó esta aplicación, enviado por un proceso que no tiene ninguna sesión, autentica en una ruta que esta aplicación protege — a través de `OAuth2ResourceServerFilter` (`-85`) y de nada más — y la misma ruta sin el token es un `401`. Ese es el círculo entero cerrado dentro de un solo test.

En producción esa misma pregunta la responde el actuator. `/actuator/oauth2clients` lista cada cliente registrado con sus métodos, grants, scopes, URIs de redirección y ajustes, más cuántas autorizaciones tiene vivas — y nunca un secreto, porque el payload se ensambla campo a campo y `clientSecret` no se nombra en ninguna parte de él. Viene sin exponer por defecto como todo lo que va más allá de `health` e `info`, y la página **OAuth2 clients** del panel de administración (`/firefly/oauth2`, en el grupo Wiring) lo renderiza en el mismo proceso. Dos detalles de esa página pagan el momento que cuestan: PKCE se informa dos veces — como lo registró el cliente y como lo imponen de verdad los endpoints, que difieren siempre que el `require_pkce` global esté encendido — y los recuentos de autorizaciones llevan una bandera `processLocal`, que es `true` con el driver `memory` y es la diferencia entre "este cliente no tiene autorizaciones vivas" y "este worker no ha emitido ninguna".

!!! laravel "Paridad con Laravel"
    La mitad cliente es el terreno que cubre Socialite, y lo cubre de otra manera: Socialite te da un driver por proveedor y espera que escribas tú el controlador, llames a `Auth::login()` y decidas qué hacer con el payload, y lee userinfo en lugar de validar un id token. Aquí el flujo es configuración con la forma de Spring Boot, el id token se comprueba firma y claim a claim, PKCE y un `state` de un solo uso comparado en tiempo constante no son opcionales, y el resultado es un principal que el resto del framework ya entiende. La mitad servidor es el terreno de Passport, y las diferencias son la superficie de protocolo: Passport no tiene documento de descubrimiento, ni endpoint de introspección, ni OIDC, mientras que este paquete trae RFC 8414, RFC 7662, RFC 7009, el userinfo de OIDC Core, RP-Initiated Logout y RFC 7591 — sobre la misma sesión, la misma página de login, el mismo entry point y el mismo `PasswordEncoder` que ya te dio el Capítulo 10, en lugar de sobre una pila paralela propia.

---

## Lo que aprendiste {.recap}

| Concepto | Qué hace |
|---|---|
| `firefly/security-oauth2-client` | Tu aplicación como **relying party**: registrations, descubrimiento, login por código de autorización, un principal `OidcUser`, cierre de sesión iniciado por el RP, tokens salientes |
| `firefly/security-oauth2-server` | Tu aplicación como **servidor de autorización**: clientes, consentimiento, `/oauth2/token`, introspección, revocación, userinfo, JWKS, descubrimiento |
| `OAuth2AuthorizationRequestRedirectFilter` (`-89`) | Responde `GET /oauth2/authorization/{id}`: construye la petición, la guarda en la sesión, redirige al proveedor |
| `OAuth2LoginAuthenticationFilter` (`-88`) | Responde `GET /login/oauth2/code/{id}`: saca la petición guardada (un solo uso), comprueba el `state`, canjea el código, identifica a la persona |
| `OAuth2AuthorizationServerFilter` (`-82`) | El único filtro del servidor — tras los filtros de sesión y bearer, antes de CSRF y de las reglas de URL, así que no hace falta ninguna regla ni entrada en `csrf.except` |
| Registrations y providers | `registration.{id}` es una relación, `provider.{id}` es un emisor; cinco presets, e `issuer_uri` descubre el resto |
| `OAuth2AuthorizationRequestResolver` | Un `state` nuevo de 43 caracteres cada vez, un `nonce` para cada petición `openid` y un verificador PKCE S256 — un generador, 256 bits cada uno |
| `OidcUser` | Claims fusionados (userinfo sobre id token), `SCOPE_*` por scope concedido, y el id token crudo **en blanco** en la copia de la sesión |
| `GrantedAuthoritiesMapper` | El `#[Bean]` que convierte un claim `groups`/`roles` en `ROLE_*`; añade a la lista concedida en lugar de reemplazarla |
| `OidcClientInitiatedLogoutSuccessHandler` | `logout.oidc_initiated`: el `end_session_endpoint` del proveedor con `id_token_hint`, leído antes de invalidar la sesión |
| `OAuth2AuthorizedClientManager` | `client_credentials` desde la caché, `authorization_code` desde la sesión o la caché, refrescado dentro de `clock_skew` de la caducidad |
| `Http::oauth2Client('{id}')` | Un `PendingRequest` corriente con el bearer de esa registration encima: falseable, trazado, reintentable |
| PKCE, `state`, `nonce` | S256 por defecto y obligatorio para un cliente público; `state` de un solo uso, atado a la sesión y comparado en tiempo constante; `nonce` siempre enviado y siempre comprobado |
| Rotación de refresh tokens | Cada refresco rota e invalida el access token al que sustituye; presentar un token superado revoca la autorización entera |
| `firefly:oauth2:keys` | RSA 2048 o P-256, `0600`, nunca sobrescrito sin `--force`; el `kid` vale por defecto la huella RFC 7638 |
| `jwt.previous_keys` | Rotación sin parada: la clave vieja sigue verificando y sigue apareciendo en el JWKS; dos claves bajo un `kid` es un rechazo de arranque |
| Drivers `memory` y `eloquent` | `memory` es un proceso — un test o un único servidor de desarrollo; con más de un worker hace falta `eloquent`, o un código emitido por un proceso será incanjeable por otro |
| `FakeAuthorizationServer` | Un proveedor OIDC entero en una clase: rutas reales para el canal frontal, `Http::fake()` para exactamente cuatro URLs del canal trasero |
| `OAuth2ServerTestClient` | Conduce tu propio servidor de extremo a extremo, sobre una identificación real — porque el endpoint de autorización exige un principal sostenido por la sesión |
| `/actuator/oauth2clients` | Cada cliente, sus grants y su recuento de autorizaciones vivas, nunca un secreto; `processLocal` dice si ese recuento describe el despliegue |

---

## Ponlo en práctica {.exercises}

1. **Inicia sesión con un proveedor real, dos veces.** Añade una registration `google` con nada más que `client_id` y `client_secret` y confirma que la página de login gana un botón; luego añade una segunda registration `corp` sobre `keycloak` con solo `provider.keycloak.issuer_uri` puesto, y observa cómo el descubrimiento rellena los cinco endpoints que nunca escribiste — autorización, token, JWKS, userinfo y end-session. Deja el emisor sin servicio y recarga `/login`: un botón desaparece con un aviso en el log, el otro sigue funcionando.
2. **Observa cómo se gasta el state.** Inicia un login, copia la URL de callback entera de la barra de direcciones antes de que la página termine, y reprodúcela. El segundo intento es `authorization_request_not_found` — no un desajuste de state — porque la petición guardada se saca de la sesión antes de comparar nada. Luego empieza de nuevo, manipula un carácter del `state`, y verás `invalid_state_parameter` en su lugar.
3. **Convierte un claim en un rol.** Enlaza un `GrantedAuthoritiesMapper` que mapee un claim `groups` a `ROLE_*`, pon `#[PreAuthorize("hasRole('ENGINEERING')")]` sobre una acción, y confirma que la misma persona que recibía un `403` antes de que existiera el bean recibe un `200` después. Luego confirma que `hasScope('email')` ya era cierto sin ningún mapper, y di por qué.
4. **Sé las dos mitades a la vez.** En una misma aplicación, enciende `oauth2.server` con un cliente `web-app`, y `oauth2.resource_server` con `jwks_source: local`. Acuña un token con `OAuth2ServerTestClient`, llama con él a una API protegida, después rota la clave de firma a `jwt.previous_keys` y vuelve a llamar a la API con el token *viejo*: sigue verificando. Ahora quita `previous_keys` y observa cómo ese mismo token deja de valer.
5. **Rompe la pareja a propósito.** Enciende `firefly.security.http_basic.enabled` junto al servidor de autorización y lee el rechazo del arranque. Luego razónalo solo con la tabla de filtros del Capítulo 10, sin volver a arrancar: qué filtro responde primero, qué responde, y por qué no se habría registrado nunca ningún error de OAuth2.
