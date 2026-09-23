<span class="eyebrow">Parte IV — Observabilidad, Pruebas y Entrega · Capítulo 12</span>

# Probar Aplicaciones LaraFly {.chtitle}

Al terminar este capítulo conocerás las dos familias de arnés de arranque de `firefly/testing` — `FireflyTestCase`/`FireflyDatabaseTestCase` para pruebas de clase al estilo de Testbench, y `bootFireflyApp()`/`fireflyApplication()` para arranques desnudos sin ninguna cola de framework de pruebas — los once dobles de grabación que permiten a una prueba afirmar sobre lo que un puerto de Firefly realmente vio (algo que los propios `Event::fake()`/`Bus::fake()` de Laravel no pueden hacer, porque nunca han oído hablar de los puertos de Firefly), las cinco expectativas de Pest con sabor a Firefly, el par `WebSliceTestCase`/`DataSliceTestCase` que arranca solo los beans que una prueba necesita, y — porque este libro solo enseña lo que realmente se entrega — una limitación real y honesta con la que se toparon las propias pruebas de `samples/lumen` y cómo la sortearon.

!!! note "Término nuevo: prueba de rebanada (slice test)"
    Una **prueba de rebanada** arranca solo la estrecha vertical del framework que una prueba realmente ejercita — el pipeline web sobre un controlador, o el pipeline de datos sobre un repositorio — en lugar de toda la aplicación. Es la idea de `@WebMvcTest`/`@DataJpaTest` de Spring Boot: arranques más rápidos, y una rebanada mal cableada falla de inmediato en lugar de funcionar silenciosamente por accidente porque algún bean no relacionado resultó estar presente también.

---

## El arnés de arranque: `FireflyTestCase`

Cada base de prueba de Firefly improvisada a mano por todo el monorepo solía volver a derivar el mismo puñado de reglas de orden de arranque a mano. `FireflyTestCase` las absorbe de una vez, como una subclase de `Testbench\TestCase` con exactamente tres hooks que sobreescribir:

<!-- source: packages/testing/src/FireflyTestCase.php -->
```php
/**
 * The Firefly capability providers this test needs, in registration order.
 * FireflyAutoConfigureServiceProvider is ALWAYS prepended by the harness — do NOT list it here.
 *
 * @return list<class-string<ServiceProvider>>
 */
protected function fireflyProviders(): array
{
    $attribute = $this->fireflyTestAttribute();

    return $attribute instanceof FireflyTest ? $attribute->providers : [];
}
// …
protected function configOverrides(): array
{
    $attribute = $this->fireflyTestAttribute();

    return $attribute instanceof FireflyTest ? $attribute->config : [];
}
// …
/**
 * Lazily-resolved bindings / manifest instances, bound AFTER config. Override to
 * $app->instance(SomeManifest::class, ...) or bind a port fake for the whole test class.
 */
protected function defineFireflyEnvironment(Application $app): void
{
    // no-op by default
}
```

`configOverrides()` devuelve configuración ansiosa, con claves punteadas, sembrada **antes** del arranque, para que una pasada de `#[ConditionalOnProperty]` o `#[ConditionalOnMissingBean]` — que escanea en tiempo de *registro* — la observe de verdad. Esa es la costura para `firefly.scan.paths` y para cualquier indicador `firefly.<feature>.*` que un test necesite encendido. Cada uno de los tres hooks tiene además una forma a nivel de clase: `#[FireflyTest(providers: […], config: […])]` sobre la clase de test hace el mismo trabajo sin una sobrescritura.

La clase base se encarga de las partes que cada prueba solía equivocar sutilmente: `FireflyAutoConfigureServiceProvider` se registra *siempre* primero (el propio `register()` de cada proveedor de capacidad asume que el kernel ya existe), `configOverrides()` se aplica antes del arranque de modo que un `#[ConditionalOnProperty]` que escanea en tiempo de registro realmente lo observa, y el canal de log se cambia a `errorlog` de modo que un sandbox de solo lectura nunca tropiece con un logger respaldado por el sistema de archivos. Una subclase mínima no necesita más que el único hook que realmente usa:

<!-- illustrative: a test the reader writes in their own application, which by definition is not a file in this repository -->
```php
<?php

declare(strict_types=1);

use Firefly\Testing\FireflyTestCase;

final class WidgetProbeTest extends FireflyTestCase
{
    protected function configOverrides(): array
    {
        return ['firefly.scan.paths' => [
            'App\\Widgets\\' => app_path('Widgets'),
        ]];
    }
}
```

Dos pequeñas barandillas rematan la base: `app(): Application` lanza una `LogicException` clara si la llamas antes del arranque, en lugar de devolverte un `null` sin tipo, y `fireflyContext(): ApplicationContext` te devuelve directamente la misma fachada del motor de arranque que presentó el Capítulo 2, ya resuelta.

---

## Pruebas de base de datos: `FireflyDatabaseTestCase`

`FireflyDatabaseTestCase` extiende `FireflyTestCase` y mezcla `UsesSqliteMemory`, que enlaza una conexión sqlite `:memory:` compartida como el `testing` por defecto — sin Docker, sin archivo de base de datos de fixtures, sin estado entre pruebas:

<!-- illustrative: the reader's own database test, with the schema their application owns -->
```php
<?php

declare(strict_types=1);

use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;

final class WidgetRepositoryTest extends FireflyDatabaseTestCase
{
    protected function defineFireflyEnvironment(Application $app): void
    {
        $this->createSchema('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }
}
```

`createSchema(string $table, Closure(Blueprint): void $blueprint): void` es un envoltorio fino sobre `Schema::create()` — nada nuevo que aprender si ya conoces las migraciones de Laravel, solo un camino más corto desde "necesito una tabla para esta única prueba" hasta un esquema en funcionamiento.

---

## Arranques desnudos: `bootFireflyApp()` y `fireflyApplication()`

No toda prueba quiere un `TestCase` de Testbench completo. `Firefly\Testing\functions.php` — autocargado con Composer `files`, de modo que estas están siempre disponibles sin ninguna sentencia `use` — trae una segunda familia para un arranque desnudo:

<!-- source: packages/testing/src/functions.php -->
```php
function fireflyApplication(array $config = [], array $providers = [], array $bindings = [], array $needs = []): Application
// …
function bootFireflyApp(array $config = [], array $providers = [], array $bindings = [], array $needs = []): ApplicationContext
```

`$config` es el array de configuración completo; `$providers` se registran **después** de `FireflyAutoConfigureServiceProvider`, que se registra siempre primero pase lo que pase; `$bindings` son sobrescrituras `$app->instance()` enlazadas *antes* del registro de proveedores — la costura para entregar un doble de puerto; y `$needs` es un pequeño "menú de enlaces faltantes" (`'cache'`, `'validation'`, `'http'`) que enlaza un respaldo fresco y aislado para una capacidad de la que un arranque desnudo carecería de otro modo por completo:

<!-- illustrative: the reader's own bare-boot test over their application's handlers -->
```php
<?php

declare(strict_types=1);

use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Cqrs\Handler\HandlerManifest;

it('boots a bare cqrs app with AutoConfigure first and an explicit binding', function () {
    $context = bootFireflyApp(
        config: ['firefly' => ['cqrs' => []]],
        providers: [CqrsServiceProvider::class, CqrsWiringProvider::class],
        bindings: [HandlerManifest::class => new HandlerManifest([], [])],
    );

    expect($context->has(HandlerManifest::class))->toBeTrue();
});
```

`bootFireflyApp()` es la llamada idéntica, que devuelve el `ApplicationContext` arrancado en lugar del `Application` en bruto — útil siempre que una prueba quiera alcanzar directamente un bean a través de la fachada del contexto en lugar de la propia API de contenedor de Laravel.

---

## Dobles de grabación: probar sin `Event::fake()`

Los `Event::fake()`/`Bus::fake()` de Laravel interceptan el despacho de eventos y jobs *propio de Laravel* — no tienen visibilidad alguna sobre el `EventPublisher` de `firefly/eda`, el `CommandBus` de `firefly/cqrs`, ni ningún otro puerto de Firefly. `firefly/testing` trae un doble de grabación o de stub para exactamente eso: una implementación sencilla del puerto real que graba lo que vio, de modo que una prueba puede afirmar sobre el comportamiento en lugar de reimplementar los propios internos del framework.

| Puerto | Doble (`Firefly\Testing\Double\*`) | Graba |
|---|---|---|
| `Firefly\Eda\EventPublisher` | `RecordingEventPublisher` | `$published` — `{destination, eventType, payload, headers}`; `publishedOf(string $eventType)` filtra |
| `Firefly\Context\Event\ApplicationEventPublisher` | `RecordingApplicationEventPublisher` | `$events` — cada objeto de evento publicado, en orden; `ofType(string $class)` filtra |
| `Firefly\Cqrs\Command\CommandBus` | `RecordingCommandBus` | `$sent`; `willReturn(string $commandClass, mixed $result)` programa un resultado enlatado; `handled(string $class)` filtra |
| `Firefly\Cqrs\Query\QueryBus` | `StubQueryBus` | `$asked`; `willReturn(string $queryClass, mixed $result)` programa un resultado enlatado |
| `Firefly\Cqrs\Metrics\CqrsMetrics` | `RecordingCqrsMetrics` | `$commandSuccesses`/`$commandFailures`/`$querySuccesses`/`$queryFailures` |
| `Firefly\Cqrs\Event\CommandEventPublisher` | `RecordingCommandEventPublisher` | `$published` (lista de `DomainEvent`); un constructor opcional `?Throwable $throw` ejercita el camino de fallo del puente |
| `Firefly\Messaging\MessageBrokerPort` | `RecordingMessageBroker` | `$published` — `{topic, value, key, headers}`; `publishedTo(string $topic)` filtra |
| `Firefly\Scheduling\Lock\DistributedLock` | `RecordingDistributedLock` | `$acquired`/`$released`; constructor `(bool $available = true)`; `setAvailable()` conmuta la concesión del cerrojo |
| `Firefly\Actuator\Health\HealthIndicator` | `FakeHealthIndicator` | Por defecto `Status::Up`; el constructor toma un `Health`, `setHealth()` lo reprograma |
| `Firefly\Observability\Tracing\Tracer` | `RecordingTracer` | `$spans` — cada nombre de span trazado, en orden de llamada; aún invoca y devuelve fielmente la clausura trazada |
| `Firefly\Context\Event\ApplicationEventPublisher` | `RecordingAuthenticationEvents` | `$events`, más la familia de eventos de seguridad por nombre: `successes()`, `interactive()`, `failures()`, `logouts()`, `denials()`; un constructor opcional `?ApplicationEventPublisher $forwardTo` vuelve a publicar de verdad cada evento grabado. Enlázalo **antes del arranque**, desde `defineFireflyEnvironment()` |

Dos filas nombran el mismo puerto, y la diferencia entre ellas es lo que necesita un test de seguridad. `RecordingApplicationEventPublisher` graba objetos; `RecordingAuthenticationEvents` conoce además los cinco tipos de evento de seguridad por nombre, así que un test de flujo de inicio de sesión lee `$this->events->failures()[0]->username` en vez de filtrar una lista mixta por clase. Es el doble al que llama `$this->events` cada listado de las secciones de inicio de sesión, cierre de sesión y denegación del Capítulo 10.

Su docblock lleva las dos reglas que lo hacen funcionar, y las dos son fáciles de equivocar:

<!-- source: packages/testing/src/Double/RecordingAuthenticationEvents.php -->
```php
/**
 // …
 * Bind it BEFORE boot — `$app->instance(ApplicationEventPublisher::class, $events)` from
 * defineFireflyEnvironment() — so firefly/security's AuthenticationEventPublisher bean wraps it; the
 * framework's own default is a bound()-guarded provider binding, which is why an instance() wins there.
 // …
 */
final class RecordingAuthenticationEvents implements ApplicationEventPublisher
{
    /** @var list<object> */
    public array $events = [];

    public function __construct(private readonly ?ApplicationEventPublisher $forwardTo = null) {}
```

La primera regla es el momento del enlace: un `instance()` registrado desde `defineFireflyEnvironment()` ya está ahí cuando habría corrido el valor por defecto de `firefly/security`, protegido por `bound()`, así que el espía gana — enlázalo después del arranque y lo que sostendrán los filtros será el publicador propio del framework.

La segunda es `$forwardTo`, y existe porque enlazar el espía **reemplaza** al puerto. Nada registrado contra el despachador vuelve a oír un evento de seguridad: un `#[AsEventListener]` sobre `InteractiveAuthenticationSuccessEvent` se queda mudo durante toda la suite, que es como se rompe sin error una suite cuya tubería incluye semejante listener (`firefly/security-oauth2-server` estampa así el instante de inicio de sesión). Entrégale el publicador propio del framework — `new RecordingAuthenticationEvents(new DispatcherEventPublisher($app))` — y cada evento se graba *y* se publica de verdad. El valor por defecto, `null`, mantiene al espía como puro grabador, que es lo correcto para una suite que solo lee.

`RecordingEventPublisher`, al completo, muestra la forma que sigue cada doble de la tabla — una implementación real del puerto, más un array sencillo que solo recuerda lo que ocurrió:

<!-- source: packages/testing/src/Double/RecordingEventPublisher.php -->
```php
final class RecordingEventPublisher implements EventPublisher
{
    /** @var list<array{destination: string, eventType: string, payload: array<string,mixed>, headers: array<string,string>}> */
    public array $published = [];

    public function subscribe(string $eventTypePattern, callable $handler): void {}

    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        $this->published[] = compact('destination', 'eventType', 'payload', 'headers');
    }

    public function start(): void {}

    public function stop(): void {}
    // …
    public function publishedOf(string $eventType): array
    {
        return array_values(array_filter($this->published, static fn (array $e): bool => $e['eventType'] === $eventType));
    }
}
```

<!-- illustrative: the four calls a reader makes against a recording double from their own test -->
```php
<?php

use Firefly\Testing\Double\RecordingEventPublisher;

$publisher = new RecordingEventPublisher;
$publisher->publish('accounts.events', 'AccountOpened', ['owner' => 'alice'], ['x' => '1']);

expect($publisher->published)->toHaveCount(1)
    ->and($publisher->published[0]['eventType'])->toBe('AccountOpened');
```

---

## Expectativas de Pest con sabor a Firefly

`Firefly\Testing\Pest\FireflyExpectations::register()` instala un pequeño conjunto de matchers `expect()` personalizados, invocado una vez desde el propio `tests/Pest.php` raíz del monorepo:

<!-- source: tests/Pest.php -->
```php
use Firefly\Testing\Pest\FireflyExpectations;
// …
FireflyExpectations::register();
```

Hay exactamente **cinco**:

- **`toHavePublished(string $eventType, array $payloadContains = [])`** — sobre un `RecordingEventPublisher`; al menos un evento publicado del tipo dado cuyo payload contiene el subconjunto clave/valor dado.
- **`toHaveHandledCommand(string $class)`** — sobre un `RecordingCommandBus`; al menos un comando enviado es una instancia de `$class`.
- **`toBeUp()`** — sobre un `Health` o un `HealthIndicator` (reducido vía `->health()` automáticamente); afirma `Status::Up`.
- **`toHaveRecordedMetric(string $name, array $tags = [])`** — sobre cualquier cosa que exponga `meters(): list<Meter>` (p. ej. un `MeterRegistry`); al menos un medidor grabado con el nombre y subconjunto de tags dados.
- **`toBeProblemDetails(int $status)`** — sobre un `TestResponse` (comprueba el content type `application/problem+json` y decodifica el cuerpo) o un array sencillo; afirma que la forma RFC-7807 tiene las claves `type`/`title`/`status` y que `status` coincide.

<!-- illustrative: the expectations a reader writes in their own test -->
```php
<?php

use Firefly\Testing\Double\RecordingCommandBus;

// @phpstan-ignore method.notFound
expect($commandBus)->toHaveHandledCommand(PlaceOrder::class);
```

!!! note "Los comentarios `@phpstan-ignore method.notFound`, explicados"
    Pest instala estas vía `expect()->extend(...)` **en tiempo de ejecución** — la reflexión estática de PHPStan sobre la clase `Pest\Expectation` de vendor no tiene forma de ver un método añadido de esta manera, de modo que cada llamada se marca como `method.notFound` aunque genuinamente exista una vez que `tests/Pest.php` se ha ejecutado. Mantén cada expectativa personalizada en su propia sentencia `expect()` en lugar de encadenar con `->and(...)`: encadenar degrada el `method.notFound` (precisamente ignorable) hasta el `method.nonObject` (no ignorable).

Para una aserción más llana, con sabor a PHPUnit, en lugar de las expectativas fluidas, dos helpers procedurales viven junto a `bootFireflyApp()` en el mismo `functions.php` autocargado:

<!-- source: packages/testing/src/functions.php -->
```php
function assertEventPublished(object $publisher, string $eventType, array $payloadContains = []): void
// …
function assertNoEventsPublished(object $publisher): void
{
    expect($publisher->published)->toBeEmpty('Expected no events to have been published.');
}
```

!!! warning "Una limitación real y honesta: `toBeProblemDetails()` y el campo `type`"
    El propio `Web\WalletRestTest.php` de `samples/lumen` documenta, en un comentario de código justo al lado de las pruebas que afecta, una brecha genuina: `toBeProblemDetails()` afirma que el cuerpo de la respuesta tiene una clave `type`, pero el renderizador RFC-7807 real del framework (`Firefly\Kernel\Error\ErrorResponse::fromException()`, renderizado por `Firefly\Web\Exception\ProblemDetailsRenderer`) nunca rellena `type` — es un campo opcional que solo se emite cuando se pasa explícitamente, cosa que el camino de excepción-a-respuesta nunca hace. Llamar a `toBeProblemDetails()` contra un `404`/`422`/`403`/`409` genuino que este framework realmente produce falla, siempre, por exactamente esa razón.

    Por eso las propias pruebas de Lumen — como la propia suite de pruebas de remate de `firefly/web` — afirman los campos RFC-7807 directamente en su lugar:

<!-- source: samples/lumen/tests/Web/WalletRestTest.php -->
```php
it('returns RFC-7807 problem+json for an unknown wallet', function () {
    // …
    $this->getJson('/api/v1/wallets/nope/balance')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 404)
        ->assertJsonPath('code', 'RESOURCE_NOT_FOUND')
        ->assertJsonPath('title', 'Not Found');
```

Un libro que solo enseñe lo que genuinamente funciona te haría un flaco favor ocultando esto: `toBeProblemDetails()` es real, se entrega, y la propia suite del paquete de pruebas lo ejercita contra un cuerpo que *sí* lleva una clave `type` — simplemente no es la aserción a la que Lumen recurre contra las respuestas de error reales del framework, y ahora sabes por qué, en lugar de toparte con el mismo fallo en frío.

---

## Pruebas de rebanada: `WebSliceTestCase` y `DataSliceTestCase`

Una prueba de rebanada arranca solo los beans que un escaneo PSR-4 descubre para una estrecha vertical, más cualesquiera sobrescrituras explícitas de puertos que suministres — la idea de `@WebMvcTest`/`@DataJpaTest` de Spring, portada. Ambas bases exponen un método, invocable directamente desde una clausura de prueba de Pest:

<!-- source: packages/testing/src/Slice/WebSliceTestCase.php -->
```php
abstract class WebSliceTestCase extends FireflyTestCase
{
    // …
    public function webSlice(array $scan = [], array $overrides = []): ApplicationContext
    {
        if ($this->sliceRequested && ($scan !== $this->sliceScan || $overrides !== $this->sliceOverrides)) {
            throw new LogicException('webSlice() is per-test-class; call it once with a single slice definition.');
        }
```

`DataSliceTestCase::dataSlice()` es el mismo método para la vertical de persistencia. Ambos son **por clase de test**: llamar a uno dos veces con definiciones de rebanada distintas es una `LogicException` en vez de un segundo arranque silencioso, porque la segunda definición se aplicaría a cada test de la clase y no solo a los escritos después de ella.

<!-- illustrative: the reader's own web-slice test over their own controller -->
```php
<?php

declare(strict_types=1);

use Firefly\Testing\Slice\WebSliceTestCase;

uses(WebSliceTestCase::class);

it('boots a web slice and serves a sliced controller route', function () {
    $this->webSlice(scan: [
        'App\\Http\\Slice\\' => base_path('tests/Fixtures/Slice'),
    ]);

    $response = $this->get('/slice/ping');

    expect($response->status())->toBe(200);
});
```

`WebSliceTestCase` arranca el pipeline real web + validación — incluido el cableado de rutas — sobre solo los controladores rebanados, de modo que `$this->get(...)` sirve un `TestResponse` genuino contra ellos. `DataSliceTestCase` arranca el pipeline de datos real sobre solo los beans rebanados, y luego resuelve con avidez cada uno de ellos, de modo que una rebanada mal cableada falla de inmediato en el arranque en lugar de pasar por accidente porque nada en la prueba resultó tocar el bean roto:

<!-- illustrative: the reader's own data-slice test over their own repository -->
```php
<?php

declare(strict_types=1);

use Firefly\Testing\Slice\DataSliceTestCase;

uses(DataSliceTestCase::class);

it('boots ONLY the sliced data beans and resolves them', function () {
    $context = $this->dataSlice(
        scan: ['App\\Domain\\Widgets\\' => base_path('tests/Fixtures/Widgets')],
        overrides: [PricingPort::class => new FakePricingPort],
    );

    expect($context->get(WidgetRepository::class))->toBeInstanceOf(WidgetRepository::class);
});
```

Ambos métodos son **por clase de prueba** — llamar a cualquiera de los dos una segunda vez con argumentos diferentes lanza una `LogicException`, de modo que la forma de una rebanada queda fijada para toda la clase de prueba en lugar de ser mutable a mitad de la prueba.

### Los atributos `#[FireflyTest]` / `#[WebSlice]` / `#[DataSlice]`

Para una **clase** de prueba escrita a mano, al estilo de PHPUnit, en lugar de un archivo de clausura de Pest, tres atributos con destino en clase dan las mismas tres formas de manera declarativa, leídos por `setUp()` antes de que el propio arranque de Testbench llegue a ejecutarse:

<!-- illustrative: the reader's own attribute-style slice test -->
```php
<?php

declare(strict_types=1);

use Firefly\Testing\Attributes\WebSlice;
use Firefly\Testing\Slice\WebSliceTestCase;

#[WebSlice(scan: ['App\\Http\\Slice\\' => __DIR__.'/Fixtures/Slice'])]
final class SliceControllerTest extends WebSliceTestCase
{
    public function test_web_slice_attribute_boots_the_sliced_route(): void
    {
        $response = $this->get('/slice/ping');

        $this->assertSame(200, $response->status());
    }
}
```

!!! warning "Atributos frente a llamadas de método — no mezcles los dos en una misma clase de prueba"
    `#[FireflyTest]`/`#[WebSlice]`/`#[DataSlice]` solo funcionan sobre una clase escrita a mano que extienda la base coincidente — no sobre un archivo de clausura de Pest. Una prueba de clausura de Pest llama a `$this->webSlice(...)`/`$this->dataSlice(...)` directamente en su lugar, porque el método internamente llama a `refreshApplication()` para re-arrancar con los valores de la rebanada — Testbench ya ha realizado un primer arranque vacío en `setUp()` antes de que el cuerpo de la clausura llegue a ejecutarse. Elige un solo estilo por clase de prueba.

---

## La capa de fixtures

Tres pequeños helpers rematan el kit, cada uno reemplazando un equivalente improvisado a mano que solía estar duplicado por paquete.

`FixtureRegistry` es un registro fino de fixtures con nombre sobre clausuras de fábrica sencillas — la comodidad de Firefly que se sienta junto a las factories de Eloquent, no un reemplazo de ellas:

<!-- source: packages/testing/src/Fixture/FixtureRegistry.php -->
```php
final class FixtureRegistry
{
    // …
    public function register(string $name, Closure $factory): self
    // …
    public function has(string $name): bool
    // …
    public function make(string $name, array $overrides = []): object
    // …
    public function load(string ...$names): array
```

<!-- illustrative: the fixtures a reader registers for their own aggregate -->
```php
<?php

use Firefly\Testing\Fixture\FixtureRegistry;

$registry = (new FixtureRegistry)->register('widget', function (array $overrides): Widget {
    return new Widget(
        $overrides['name'] ?? 'default',
        $overrides['qty'] ?? 1,
    );
});

$bolt = $registry->make('widget', ['name' => 'bolt', 'qty' => 5]);
$two = $registry->load('widget', 'widget'); // list<Widget>, 2 entries
```

`AggregateSeeder` reproduce los eventos de dominio de un agregado a través de un puerto `ApplicationEventPublisher` — útil para sembrar una proyección (el libro mayor del Capítulo 6, por ejemplo) sin volver a ejecutar todo el flujo de comandos que originalmente produjo esos eventos:

<!-- illustrative: the seeding a reader does from their own test -->
```php
<?php

use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use Firefly\Testing\Fixture\AggregateSeeder;

$publisher = new RecordingApplicationEventPublisher;
(new AggregateSeeder)->publishEvents($publisher, new WidgetCreated('a'), new WidgetCreated('b'));

expect($publisher->events)->toHaveCount(2);
```

`ListenerSpy` graba valores de cadena arbitrarios que un fixture de listener o handler vio, en orden de llamada — el único reemplazo compartido de lo que solía ser una clase `Spy` improvisada a mano en cada paquete que necesitaba una:

<!-- illustrative: the listener spy a reader drives from their own test -->
```php
<?php

use Firefly\Testing\Fixture\ListenerSpy;

$spy = new ListenerSpy;
$spy->record('order.placed');
$spy->record('order.shipped');

expect($spy->seen)->toBe(['order.placed', 'order.shipped']);
```

---

## Backends reales: `RequiresDocker` y `fireflyConfigFor()`

Algunas pruebas necesitan un backend genuino — un Postgres real, un broker Kafka real — en lugar de un doble en memoria. Estas se etiquetan con `@group integration` y se excluyen por completo de la puerta por defecto: `vendor/bin/pest` (la puerta de rama completa que ejecuta `composer test`) nunca toca uno a menos que lo pidas explícitamente con `vendor/bin/pest --group=integration`.

`RequiresDocker` es un trait (`@mixin PHPUnit\Framework\TestCase`) que convierte "Docker no está disponible" en un salto limpio en lugar de un fallo duro, de modo que una máquina sin Docker que *sí* ejecute el grupo de integración aún obtenga un resultado verde:

<!-- source: packages/testing/src/Integration/RequiresDocker.php -->
```php
trait RequiresDocker
{
    protected function skipUnlessDocker(): void
    {
        if (! is_docker_available()) {
            $this->markTestSkipped('Docker is not available; skipping @group integration test.');
        }
    }
}
```

`is_docker_available()` ejecuta `docker info` y devuelve `true` si y solo si sale con `0` — nunca lanza una excepción, de modo que llamarla sin Docker instalado en absoluto es siempre seguro. Una base de prueba de integración real respaldada por Kafka muestra el patrón de principio a fin:

<!-- illustrative: the reader's own container-backed integration test -->
```php
<?php

declare(strict_types=1);

use Firefly\Testing\Integration\RequiresDocker;
use PHPUnit\Framework\TestCase;

abstract class KafkaIntegrationTestCase extends TestCase
{
    use RequiresDocker;

    protected function setUp(): void
    {
        $this->skipUnlessDocker();
        parent::setUp();
    }
}
```

`fireflyConfigFor(object $container, string $prefix = 'database.connections.testing'): array` — el análogo de `@ServiceConnection` — mapea un testcontainer arrancado a un array de configuración plano, indexado por clave con puntos, con duck typing contra cualquiera de `getHost()`/`getMappedPort()`/`getUsername()`/`getPassword()`/`getDatabase()` que el objeto contenedor realmente exponga, de modo que funciona contra cualquier clase de contenedor de testcontainers-php sin ninguna dependencia dura de los tipos de la biblioteca:

<!-- source: packages/testing/src/functions.php -->
```php
/**
 * Map a started testcontainer into a flat Firefly/Laravel config array (the @ServiceConnection analog).
 *
 * @return array<string,mixed>
 */
function fireflyConfigFor(object $container, string $prefix = 'database.connections.testing'): array
```

Alimenta el resultado directamente al `configOverrides()` de una subclase de `FireflyTestCase`, o establece cada clave en el repositorio de configuración desde `defineFireflyEnvironment()`.

---

## Prueba desde Lumen: cómo la muestra hace dogfooding de todo

`samples/lumen` construye su propia base de prueba, `LumenTestCase`, encima de `FireflyDatabaseTestCase` — el patrón sobre el que cada capítulo de este libro ha estado descansando en última instancia:

<!-- source: samples/lumen/tests/LumenTestCase.php -->
```php
abstract class LumenTestCase extends FireflyDatabaseTestCase
{
    // …
    protected function fireflyProviders(): array
    {
        return [
            ValidationServiceProvider::class,
            WebServiceProvider::class,
            DataServiceProvider::class,
            CqrsServiceProvider::class,
            CqrsWiringProvider::class,
            EdaServiceProvider::class,
            EdaWiringProvider::class,
            SecurityServiceProvider::class,
            SecurityWiringProvider::class,
            ActuatorServiceProvider::class,
            ActuatorWiringProvider::class,
        ];
    }
    // …
    protected function configOverrides(): array
    {
        return [
            // …
            'firefly.scan.paths' => $this->scanPaths(),
            // …
            'firefly.security.enabled' => true,
            // …
            'firefly.management.enabled' => true,
            'firefly.management.endpoints.web.exposure.include' => 'health,info',
        // …
        ];
    }
// …
}
```

Cada una de las llamadas HTTP de los Capítulos 4 al 11 en este libro — cada `$this->postJson(...)`, cada aserción RFC-7807 — se ejecuta contra una subclase de `LumenTestCase`. Deliberadamente enlaza el `InMemoryEventBus` *real* como el `EventPublisher` (nunca un `RecordingEventPublisher`), porque las propias pruebas de Lumen necesitan que el puente de dominio-a-integración del Capítulo 8 dispare genuinamente, no que meramente se grabe como si lo hubiera hecho.

!!! laravel "Paridad con Laravel"
    El propio kit de pruebas de Laravel (`RefreshDatabase`, `Event::fake()`, `Bus::fake()`) es excelente para las propias primitivas de Laravel, pero no tiene visibilidad alguna sobre un puerto de Firefly — una llamada a `CommandBus::send()` o a `EventPublisher::publish()` es invisible para `Event::fake()`, porque nada de ella pasa por el propio despachador de eventos de Laravel. Los dobles de grabación y las expectativas de Pest de `firefly/testing` son la capa de paridad: el mismo idioma de "prepara un fake, afirma sobre lo que vio" que ya conoces de Laravel, apuntado a los puertos que los demás capítulos de este libro realmente presentaron.

---

## Un navegador de verdad sobre la aplicación de verdad

Todo lo visto hasta ahora en este capítulo afirma sobre objetos y sobre respuestas HTTP. Ninguna de las dos cosas puede decirte si la página de bienvenida se renderizó de verdad, si los gráficos del panel de administración se dibujaron, o si Swagger UI lanzó un error de JavaScript mientras construía su lista de operaciones. Para eso hace falta un navegador — y la razón por la que la mayoría de proyectos no tiene pruebas de navegador es que el montaje habitual es miserable: un segundo proceso sirviendo la aplicación, una segunda base de datos, un segundo contenedor, y una suite que se va separando de la que ejercitan las pruebas unitarias.

La suite de navegador de LaraFly no tiene nada de eso, por una propiedad que merece enunciarse sin rodeos: **el navegador conduce la misma aplicación arrancada que el resto de la suite.**

Pest 4 más `pestphp/pest-plugin-browser` sirve la aplicación arrancada con Testbench a Chromium **en proceso**. No hay `php -S`, ni raíz de documentos aparte, ni segunda conexión: el mismo contenedor, y la misma base de datos SQLite en memoria que el test pobló:

<!-- source: tests/Browser/Support/BrowserTestCase.php -->
```php
/**
 * The application every browser scenario drives: the shipped skeleton, compiled by the real firefly:cache
 * writer and booted under Testbench with the provider set a created app gets — served to Chromium by the
 * plugin's in-process server, so the test keeps the container and the SQLite connection.
 // …
 */
abstract class BrowserTestCase extends SkeletonExampleTestCase
{
    use SeedsOrders;

    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return DiscoveredProviders::forSkeleton();
    }
```

Y lo que se conduce es el **esqueleto**, compilado por el escritor real de `firefly:cache` — el mismo proyecto que produce `composer create-project`. Que una prueba de navegador falle es, por tanto, evidencia sobre lo que hace la aplicación de una persona recién llegada, no sobre una prueba montada para pasar.

Un escenario se lee como lo describirías en voz alta:

<!-- source: tests/Browser/WelcomeAndApiTest.php -->
```php
it('renders the welcome page with the live route table', function (): void {
    /** @var BrowserTestCase $this */
    visit('/')
        ->assertSee('Hello, LaraFly')
        ->assertSee('Your routes')
        ->assertSee('/orders/{id}')
        ->assertSee('OrderController')
        ->assertSee('GreetingController')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'welcome');
});
// …
it('serves the OpenAPI viewer, and Swagger UI draws the operations without a JavaScript error', function (): void {
    /** @var BrowserTestCase $this */
    visit('/openapi')
        ->assertPresent('#swagger-ui')
        ->wait(2)
        ->assertSee('/orders/{id}')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'openapi-viewer');
});
```

`assertNoJavaScriptErrors()` está en casi todos los escenarios, y es la afirmación que paga la suite. Una página que se renderiza y lanza calladamente en la consola es una página que se ve bien en una captura y está rota para quien la usa.

### Por qué no ralentiza tu gate

Las pruebas de navegador necesitan Node y una descarga de Chromium. El gate por defecto no debe:

<!-- source: phpunit.xml.dist -->
```xml
<testsuite name="unit">
    <directory>tests</directory>
    <directory>packages/*/tests</directory>
    <directory>samples/lumen/tests</directory>
    <exclude>tests/Browser</exclude>
</testsuite>
<testsuite name="browser">
    <directory>tests/Browser</directory>
</testsuite>
```

Dos suites, y `tests/Browser` queda excluida de la que se usa por defecto. Así que `composer check` — Pint, PHPStan, Pest y Deptrac — se mantiene enteramente libre de Node, y `composer test:browser` ejecuta la suite de navegador a solas. CI tiene un trabajo aparte, `Browser (Pest + Playwright)`, que la ejecuta y sube cada captura como artefacto, así que un fallo viene con una foto del aspecto que tenía la página.

Lo que hace el trabajo aquí es la **división en suites**, no la etiqueta de grupo:

<!-- source: tests/Pest.php -->
```php
// Browser tests: everything under tests/Browser drives a real Chromium through
// pestphp/pest-plugin-browser against the Testbench-booted app (the plugin serves
// it in-process). tests/Browser is its own `browser` testsuite in phpunit.xml.dist
// and is excluded from the default `unit` suite, so the default gate never needs
// Node; `composer test:browser` runs this suite alone. The `browser` group is a
// label only (for --group=browser filtering): the suite split in phpunit.xml.dist
// is what keeps these files out of the default run — Pest includes every file of
// the configured suite before it filters groups, and the plugin boots Playwright
// the moment a file under tests/Browser is included.
pest()->group('browser')->in('Browser');

pest()->browser()
    ->inChrome()
    ->inLightMode()
    ->timeout(10000);
```

Ese comentario merece leerse dos veces, porque la distinción que traza pilla a la gente: Pest **incluye** cada fichero de la suite configurada antes de filtrar por grupo, y el plugin arranca Playwright en cuanto se incluye un fichero bajo `tests/Browser`. Un `--exclude-group=browser` seguiría pagando, por tanto, el coste de Playwright. Solo la división en suites lo evita.

!!! warning "El plugin fuerza `app.debug=false`"
    Dos claves del framework toman por defecto `app.debug` — `firefly.web.error-page.trace` y `firefly.admin.enabled` — y el plugin fuerza `app.debug=false` mientras atiende cada petición de navegador. Una suite que dependiera de esos valores por defecto renderizaría la página de error de *producción* creyendo que prueba la de depuración. Así que `BrowserTestCase` enciende cada superficie que pretende mirar **por su propia clave**, y las subclases sobrescriben `trace()` y `securityOverrides()` — ambas leídas antes del arranque, que es por lo que son ganchos y no llamadas por test.

Y un navegador se autentica como lo hace una persona: `SecuredBrowserTestCase` pone el login por formulario propio del framework y dos usuarios en memoria delante de la aplicación, así que los escenarios de inicio de sesión conducen el mismo `FormLoginFilter` que describió el Capítulo 10 y no un atajo de pruebas.

## Autenticar un test

Lo que nos lleva a los ayudantes en los que se apoyan los capítulos de seguridad. Tres métodos, un cuarto atributo junto a los tres de `Firefly\Testing\Attributes\`, y un interruptor que apaga la pila entera. Los tres métodos se construyen uno sobre otro:

<!-- source: packages/testing/src/FireflyTestCase.php -->
```php
public function actingAsAuthentication(Authentication $authentication): static
{
    SecurityContextHolder::setContext(new SecurityContext($authentication));
    $this->app()->instance(ActingPrincipal::class, new ActingPrincipal($authentication));

    $kernel = $this->resolveHttpKernel();
    if ($kernel instanceof FoundationHttpKernel && ! $kernel->hasMiddleware(ActingPrincipalMiddleware::class)) {
        $kernel->prependMiddleware(ActingPrincipalMiddleware::class);
    }

    return $this;
}
```

`actingAsAuthentication()` es la costura: fija el portador de inmediato, enlaza el principal, y antepone un middleware **una sola vez**, así que una segunda llamada cambia quién es la petición siguiente en vez de apilar otra copia en el kernel. El middleware es el eslabón más externo a propósito — `SecurityContextPersistenceFilter` deja en paz a un portador ya autenticado, y los filtros del framework que limpian el portador en su propio `finally` corren dentro de él, así que el principal está de vuelta en el portador cuando la petición retorna.

`actingAsPrincipal('ada', ['ROLE_USER'])` es la forma de cada día, construida sobre él. Y para los capítulos de OAuth2 está el `oidcLogin()` de Spring Security, como método:

<!-- source: packages/testing/src/FireflyTestCase.php -->
```php
    /**
     * Run the rest of this test as a person who signed in through OpenID Connect (Spring Security's
     * `oidcLogin()` test support, as a method): the principal is a real DefaultOidcUser over an id token whose
     * claims are `{iss: https://idp.test, sub: user, aud: firefly-app, iat, exp}` overlaid by $claims — and
     * NO raw token value, exactly the shape the session hands a controller after a real login — so
     * `#[AuthenticationPrincipal] OidcUser $user`, `getClaim()`, `getEmail()` and the rest all answer. Its
     * authorities are OIDC_USER (an OidcUserAuthority carrying the claims) and SCOPE_x per $scopes; the
     * Authentication carries those plus $authorities (where a GrantedAuthoritiesMapper would have put ROLE_*),
     * and $registrationId on its attributes, which the authorized-client manager and RP-initiated logout read.
     * The principal is named by $nameAttributeKey read from the claims (`sub` by default), so a claim for it
     * must exist. Nothing here talks to a provider: no registration, no discovery, no token.
     // …
     */
    public function actingAsOidcUser(array $claims = [], array $authorities = [], string $registrationId = 'oidc', array $scopes = ['openid'], string $nameAttributeKey = 'sub'): static
```

`actingAsOidcUser()` construye un `DefaultOidcUser` **real**, con las autoridades que un inicio de sesión real habría concedido — `OIDC_USER` más un `SCOPE_x` por cada scope — y el identificador de registro en los atributos del token, que es lo que leen el gestor de clientes autorizados y el cierre de sesión iniciado por la parte confiante. Nada de esto habla con un proveedor: ni registro, ni descubrimiento, ni petición de token. Y deliberadamente no lleva **ningún valor de token en bruto**, porque esa es exactamente la forma que la sesión entrega a un controlador tras un inicio de sesión real; un test que recibiera un token en bruto estaría probando algo que la aplicación nunca ve.

`#[WithMockUser]` es el `@WithMockUser` de Spring, y es la forma declarativa de `actingAsPrincipal()` — el cuarto atributo de `Firefly\Testing\Attributes\`, junto a los tres atributos de rebanada de más arriba:

<!-- source: packages/testing/src/Attributes/WithMockUser.php -->
```php
/**
 * Spring's @WithMockUser: run the test (or every test of the class) as a signed-in principal. `roles` are
 * prefixed with `ROLE_` unless already so; `authorities` are taken verbatim. A method-level attribute wins
 * over a class-level one. Honoured by FireflyTestCase::setUp() through actingAsPrincipal(), so it covers
 * direct calls, HTTP requests through the filters, the dispatcher guard and proxied beans alike.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class WithMockUser
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $authorities
     */
    public function __construct(
        public string $name = 'user',
        public array $roles = ['USER'],
        public array $authorities = [],
    ) {}
```

A diferencia de los tres atributos de rebanada, este se lee en `setUp()` sobre *cualquier* `FireflyTestCase`, ganando el de nivel de método sobre el de nivel de clase, así que una clase de test escrita a mano puede dar acceso a toda su suite una vez y nombrar la excepción en el único método que necesita otro principal.

Y para la dirección contraria está `withoutSecurity()`:

<!-- source: packages/testing/src/FireflyTestCase.php -->
```php
    /**
     * Switch the security stack off for the rest of this test: the URL filter, the CSRF filter, every
     * authentication filter, the proxy's method-security link and the CQRS bus authorizers (through
     * MethodSecurityMessageEnforcer, which DefaultCommandBus/DefaultQueryBus hold by constructor) read their
     * flags live, and the dispatcher guard is rebound to the no-op default. Beans already built stay built;
     * only their gates change — so a controller test that dispatches a command whose handler carries a
     * #[PreAuthorize] gets the handler's answer, not a 401.
     */
    public function withoutSecurity(): static
```

Lee dos veces la última frase, porque es la razón por la que este método existe en absoluto en vez de una sobrescritura de configuración: los indicadores se leen **en vivo**, en cada petición, así que cambiarlos a mitad de un test cambia las puertas de beans que ya estaban construidos. Un test que solo quiere ejercitar lo que hay *detrás* de la puerta no tiene que volver a arrancar la aplicación para pasarla.

---

## Lo que aprendiste {.recap}

| Concepto | Qué hace |
|---|---|
| `FireflyTestCase` | Base de Testbench; tres hooks (`fireflyProviders`/`configOverrides`/`defineFireflyEnvironment`); config sembrada antes del arranque |
| `FireflyDatabaseTestCase` / `UsesSqliteMemory` | Añade una conexión sqlite `:memory:` compartida + `createSchema()` |
| `bootFireflyApp()` / `fireflyApplication()` | Arranques desnudos, sin Testbench; un menú `$needs` rellena los respaldos faltantes de cache/validation/http |
| 11 dobles de grabación | Implementaciones reales de puertos que graban lo que vieron — la capa de paridad que `Event::fake()` no puede alcanzar |
| 5 expectativas de Pest | `toHavePublished`, `toHaveHandledCommand`, `toBeUp`, `toHaveRecordedMetric`, `toBeProblemDetails` |
| `WebSliceTestCase` / `DataSliceTestCase` | Arranca solo una rebanada escaneada por PSR-4 + sobrescrituras explícitas; por clase de prueba, fallo rápido en datos |
| `#[FireflyTest]` / `#[WebSlice]` / `#[DataSlice]` | Análogo de atributo de clase de las mismas tres formas, para clases de prueba escritas a mano |
| `FixtureRegistry` / `AggregateSeeder` / `ListenerSpy` | Fixtures con nombre; reproducción de eventos de dominio; un spy compartido de orden de llamada |
| `RequiresDocker` / `fireflyConfigFor()` | Opt-in de `@group integration`; salta-no-falla sin Docker; mapeo de testcontainer → config |
| La suite `browser` | Pest 4 + `pest-plugin-browser` sirviendo el **esqueleto** arrancado con Testbench en proceso — mismo contenedor, mismo SQLite, sin un segundo servidor |
| Las dos suites de `phpunit.xml.dist` | `tests/Browser` excluida de `unit`, así que `composer check` se mantiene libre de Node; `composer test:browser` la ejecuta a solas |
| `assertNoJavaScriptErrors()` | La afirmación que paga la suite: una página que se renderiza y lanza en la consola se ve bien en una captura |
| `actingAsAuthentication()` | La costura bajo `actingAsPrincipal()` y `actingAsOidcUser()`; middleware antepuesto una sola vez, así que una segunda llamada cambia el principal |
| `actingAsOidcUser()` | El `oidcLogin()` de Spring como método: un `DefaultOidcUser` real, autoridades reales, **ningún token en bruto** — la forma que una sesión entrega de verdad a un controlador |
| `RecordingAuthenticationEvents` | El doble consciente de la seguridad: la familia de eventos de seguridad por nombre (`successes`/`interactive`/`failures`/`logouts`/`denials`); enlázalo antes del arranque, y pasa `$forwardTo` cuando los listeners reales deban seguir oyendo |
| `#[WithMockUser]` | El `@WithMockUser` de Spring: el `actingAsPrincipal()` declarativo, leído en `setUp()`, ganando el de nivel de método sobre el de clase |
| `withoutSecurity()` | Cada indicador de seguridad apagado durante el resto del test — leídos en vivo, así que los beans ya construidos cambian de puerta sin volver a arrancar |

---

## Ponlo en práctica {.exercises}

1. **Escribe una prueba de rebanada para uno de los propios controladores de Lumen.** Usando `WebSliceTestCase`, escanea solo `Lumen\Web\` y confirma que puedes servir un endpoint de wallet sin arrancar CQRS, EDA ni seguridad en absoluto — luego anota qué peticiones fallan, y por qué, una vez que entiendas exactamente qué beans dejó fuera la rebanada.
2. **Reproduce tú mismo la brecha de `toBeProblemDetails()`.** Llámala contra una respuesta `404` real de la propia API REST de Lumen y confirma que falla con el mensaje de clave-`type`-faltante que describió este capítulo — luego reescribe la misma aserción de la manera en que `WalletRestTest.php` realmente lo hace, y confirma que esa pasa.
3. **Ejecuta la suite de navegador, y luego rompe una página.** Ejecuta `composer test:browser` y mira `tests/Browser/Screenshots`. Después añade a la vista de bienvenida un `<script>` que lance, y vuelve a ejecutarla: confirma que la página sigue renderizándose, sigue pasando todos los `assertSee`, y que `assertNoJavaScriptErrors()` es la única afirmación que lo atrapa.
4. **Demuestra que la división en suites importa.** Cronometra `composer test` y confirma que no se descarga ningún Chromium. Después prueba `vendor/bin/pest --exclude-group=browser` y mira cómo Playwright arranca igualmente — la etiqueta de grupo filtra tests, la división en suites es lo que impide que los ficheros se incluyan siquiera.
5. **Añade una prueba de integración protegida por `RequiresDocker`.** Elige un repositorio respaldado por Eloquent, escribe una prueba `@group integration` contra un testcontainer Postgres real usando `fireflyConfigFor()`, y confirma que `vendor/bin/pest` (sin banderas) la salta por completo mientras que `vendor/bin/pest --group=integration` la ejecuta de verdad.
6. **Comprueba que `$forwardTo` importa.** Enlaza un `RecordingAuthenticationEvents` sin `$forwardTo` desde `defineFireflyEnvironment()`, registra un `#[AsEventListener]` sobre `InteractiveAuthenticationSuccessEvent` que escriba una fila, e inicia sesión: confirma que el espía graba el evento y que el listener nunca se ejecutó. Después vuelve a enlazarlo envolviendo el publicador propio del framework y confirma que ocurren las dos cosas. Por último mueve la llamada a `instance()` a *después* del arranque y confirma que el espía no graba nada en absoluto — el momento del enlace es todo el truco.
7. **Pasa una puerta de dos formas.** Toma una prueba de controlador que reciba un `401`, y haz que pase dos veces: una con `#[WithMockUser(roles: ['ADMIN'])]` sobre el método, otra con `withoutSecurity()`. Después pon `#[WithMockUser]` a nivel de clase y sobrescríbelo en un solo método, y confirma que gana el de nivel de método.
