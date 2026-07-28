<span class="eyebrow">Parte IV — Observabilidad, Pruebas y Entrega · Capítulo 12</span>

# Probar Aplicaciones LaraFly {.chtitle}

Al terminar este capítulo conocerás las dos familias de arnés de arranque de `firefly/testing` — `FireflyTestCase`/`FireflyDatabaseTestCase` para pruebas de clase al estilo de Testbench, y `bootFireflyApp()`/`fireflyApplication()` para arranques desnudos sin ninguna cola de framework de pruebas — los diez dobles de grabación que permiten a una prueba afirmar sobre lo que un puerto de Firefly realmente vio (algo que los propios `Event::fake()`/`Bus::fake()` de Laravel no pueden hacer, porque nunca han oído hablar de los puertos de Firefly), las cinco expectativas de Pest con sabor a Firefly, el par `WebSliceTestCase`/`DataSliceTestCase` que arranca solo los beans que una prueba necesita, y — porque este libro solo enseña lo que realmente se entrega — una limitación real y honesta con la que se toparon las propias pruebas de `samples/lumen` y cómo la sortearon.

!!! note "Término nuevo: prueba de rebanada (slice test)"
    Una **prueba de rebanada** arranca solo la estrecha vertical del framework que una prueba realmente ejercita — el pipeline web sobre un controlador, o el pipeline de datos sobre un repositorio — en lugar de toda la aplicación. Es la idea de `@WebMvcTest`/`@DataJpaTest` de Spring Boot: arranques más rápidos, y una rebanada mal cableada falla de inmediato en lugar de funcionar silenciosamente por accidente porque algún bean no relacionado resultó estar presente también.

---

## El arnés de arranque: `FireflyTestCase`

Cada base de prueba de Firefly improvisada a mano por todo el monorepo solía volver a derivar el mismo puñado de reglas de orden de arranque a mano. `FireflyTestCase` las absorbe de una vez, como una subclase de `Testbench\TestCase` con exactamente tres hooks que sobreescribir:

```php
abstract class FireflyTestCase extends TestCase
{
    /**
     * The Firefly capability providers this test needs, in registration order.
     * FireflyAutoConfigureServiceProvider is ALWAYS prepended by the harness — do NOT list it here.
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function fireflyProviders(): array
    {
        return [];
    }

    /**
     * Eager, dot-keyed config seeded BEFORE boot so #[ConditionalOn*] passes (which scan at register
     * time) observe it. This is the seam for `firefly.scan.paths`, `firefly.<feature>.*` flags, etc.
     *
     * @return array<string, mixed>
     */
    protected function configOverrides(): array
    {
        return [];
    }

    /**
     * Lazily-resolved bindings / manifest instances, bound AFTER config. Override to
     * $app->instance(SomeManifest::class, ...) or bind a port fake for the whole test class.
     */
    protected function defineFireflyEnvironment(Application $app): void
    {
        // no-op by default
    }
}
```

La clase base se encarga de las partes que cada prueba solía equivocar sutilmente: `FireflyAutoConfigureServiceProvider` se registra *siempre* primero (el propio `register()` de cada proveedor de capacidad asume que el kernel ya existe), `configOverrides()` se aplica antes del arranque de modo que un `#[ConditionalOnProperty]` que escanea en tiempo de registro realmente lo observa, y el canal de log se cambia a `errorlog` de modo que un sandbox de solo lectura nunca tropiece con un logger respaldado por el sistema de archivos. Una subclase mínima no necesita más que el único hook que realmente usa:

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

```php
function fireflyApplication(array $config = [], array $providers = [], array $bindings = [], array $needs = []): Application { /* ... */ }
function bootFireflyApp(array $config = [], array $providers = [], array $bindings = [], array $needs = []): ApplicationContext { /* ... */ }
```

`$config` es el array de configuración completo; `$providers` se registran **después** de `FireflyAutoConfigureServiceProvider`, que se registra siempre primero pase lo que pase; `$bindings` son sobrescrituras `$app->instance()` enlazadas *antes* del registro de proveedores — la costura para entregar un doble de puerto; y `$needs` es un pequeño "menú de enlaces faltantes" (`'cache'`, `'validation'`, `'http'`) que enlaza un respaldo fresco y aislado para una capacidad de la que un arranque desnudo carecería de otro modo por completo:

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

`RecordingEventPublisher`, al completo, muestra la forma que sigue cada doble de la tabla — una implementación real del puerto, más un array sencillo que solo recuerda lo que ocurrió:

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

    public function publishedOf(string $eventType): array
    {
        return array_values(array_filter($this->published, static fn (array $e): bool => $e['eventType'] === $eventType));
    }
}
```

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

```php
// tests/Pest.php
use Firefly\Testing\Pest\FireflyExpectations;

FireflyExpectations::register();
```

Hay exactamente **cinco**:

- **`toHavePublished(string $eventType, array $payloadContains = [])`** — sobre un `RecordingEventPublisher`; al menos un evento publicado del tipo dado cuyo payload contiene el subconjunto clave/valor dado.
- **`toHaveHandledCommand(string $class)`** — sobre un `RecordingCommandBus`; al menos un comando enviado es una instancia de `$class`.
- **`toBeUp()`** — sobre un `Health` o un `HealthIndicator` (reducido vía `->health()` automáticamente); afirma `Status::Up`.
- **`toHaveRecordedMetric(string $name, array $tags = [])`** — sobre cualquier cosa que exponga `meters(): list<Meter>` (p. ej. un `MeterRegistry`); al menos un medidor grabado con el nombre y subconjunto de tags dados.
- **`toBeProblemDetails(int $status)`** — sobre un `TestResponse` (comprueba el content type `application/problem+json` y decodifica el cuerpo) o un array sencillo; afirma que la forma RFC-7807 tiene las claves `type`/`title`/`status` y que `status` coincide.

```php
<?php

use Firefly\Testing\Double\RecordingCommandBus;

// @phpstan-ignore method.notFound
expect($commandBus)->toHaveHandledCommand(PlaceOrder::class);
```

!!! note "Los comentarios `@phpstan-ignore method.notFound`, explicados"
    Pest instala estas vía `expect()->extend(...)` **en tiempo de ejecución** — la reflexión estática de PHPStan sobre la clase `Pest\Expectation` de vendor no tiene forma de ver un método añadido de esta manera, de modo que cada llamada se marca como `method.notFound` aunque genuinamente exista una vez que `tests/Pest.php` se ha ejecutado. Mantén cada expectativa personalizada en su propia sentencia `expect()` en lugar de encadenar con `->and(...)`: encadenar degrada el `method.notFound` (precisamente ignorable) hasta el `method.nonObject` (no ignorable).

Para una aserción más llana, con sabor a PHPUnit, en lugar de las expectativas fluidas, dos helpers procedurales viven junto a `bootFireflyApp()` en el mismo `functions.php` autocargado:

```php
function assertEventPublished(object $publisher, string $eventType, array $payloadContains = []): void { /* ... */ }
function assertNoEventsPublished(object $publisher): void { /* ... */ }
```

!!! warning "Una limitación real y honesta: `toBeProblemDetails()` y el campo `type`"
    El propio `Web\WalletRestTest.php` de `samples/lumen` documenta, en un comentario de código justo al lado de las pruebas que afecta, una brecha genuina: `toBeProblemDetails()` afirma que el cuerpo de la respuesta tiene una clave `type`, pero el renderizador RFC-7807 real del framework (`Firefly\Kernel\Error\ErrorResponse::fromException()`, renderizado por `Firefly\Web\Exception\ProblemDetailsRenderer`) nunca rellena `type` — es un campo opcional que solo se emite cuando se pasa explícitamente, cosa que el camino de excepción-a-respuesta nunca hace. Llamar a `toBeProblemDetails()` contra un `404`/`422`/`403`/`409` genuino que este framework realmente produce falla, siempre, por exactamente esa razón.

    Por eso las propias pruebas de Lumen — como la propia suite de pruebas de remate de `firefly/web` — afirman los campos RFC-7807 directamente en su lugar:

    ```php
    it('returns RFC-7807 problem+json for an unknown wallet', function () {
        $this->getJson('/api/v1/wallets/nope/balance')
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 404)
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND')
            ->assertJsonPath('title', 'Not Found');
    });
    ```

    Un libro que solo enseñe lo que genuinamente funciona te haría un flaco favor ocultando esto: `toBeProblemDetails()` es real, se entrega, y la propia suite del paquete de pruebas lo ejercita contra un cuerpo que *sí* lleva una clave `type` — simplemente no es la aserción a la que Lumen recurre contra las respuestas de error reales del framework, y ahora sabes por qué, en lugar de toparte con el mismo fallo en frío.

---

## Pruebas de rebanada: `WebSliceTestCase` y `DataSliceTestCase`

Una prueba de rebanada arranca solo los beans que un escaneo PSR-4 descubre para una estrecha vertical, más cualesquiera sobrescrituras explícitas de puertos que suministres — la idea de `@WebMvcTest`/`@DataJpaTest` de Spring, portada. Ambas bases exponen un método, invocable directamente desde una clausura de prueba de Pest:

```php
abstract class WebSliceTestCase extends FireflyTestCase
{
    public function webSlice(array $scan = [], array $overrides = []): ApplicationContext { /* ... */ }
}

abstract class DataSliceTestCase extends FireflyDatabaseTestCase
{
    public function dataSlice(array $scan = [], array $overrides = []): ApplicationContext { /* ... */ }
}
```

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

```php
final class FixtureRegistry
{
    public function register(string $name, Closure $factory): self { /* ... */ }
    public function has(string $name): bool { /* ... */ }
    public function make(string $name, array $overrides = []): object { /* ... */ }
    public function load(string ...$names): array { /* ... */ }
}
```

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

```php
<?php

use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use Firefly\Testing\Fixture\AggregateSeeder;

$publisher = new RecordingApplicationEventPublisher;
(new AggregateSeeder)->publishEvents($publisher, new WidgetCreated('a'), new WidgetCreated('b'));

expect($publisher->events)->toHaveCount(2);
```

`ListenerSpy` graba valores de cadena arbitrarios que un fixture de listener o handler vio, en orden de llamada — el único reemplazo compartido de lo que solía ser una clase `Spy` improvisada a mano en cada paquete que necesitaba una:

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

```php
function fireflyConfigFor(object $container, string $prefix = 'database.connections.testing'): array { /* ... */ }
```

Alimenta el resultado directamente al `configOverrides()` de una subclase de `FireflyTestCase`, o establece cada clave en el repositorio de configuración desde `defineFireflyEnvironment()`.

---

## Prueba desde Lumen: cómo la muestra hace dogfooding de todo

`samples/lumen` construye su propia base de prueba, `LumenTestCase`, encima de `FireflyDatabaseTestCase` — el patrón sobre el que cada capítulo de este libro ha estado descansando en última instancia:

```php
abstract class LumenTestCase extends FireflyDatabaseTestCase
{
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

    protected function configOverrides(): array
    {
        return [
            'firefly.scan.paths' => $this->scanPaths(),
            'firefly.security.enabled' => true,
            'firefly.management.enabled' => true,
            'firefly.management.endpoints.web.exposure.include' => 'health,info',
        ];
    }
}
```

Cada una de las llamadas HTTP de los Capítulos 4 al 11 en este libro — cada `$this->postJson(...)`, cada aserción RFC-7807 — se ejecuta contra una subclase de `LumenTestCase`. Deliberadamente enlaza el `InMemoryEventBus` *real* como el `EventPublisher` (nunca un `RecordingEventPublisher`), porque las propias pruebas de Lumen necesitan que el puente de dominio-a-integración del Capítulo 8 dispare genuinamente, no que meramente se grabe como si lo hubiera hecho.

!!! laravel "Paridad con Laravel"
    El propio kit de pruebas de Laravel (`RefreshDatabase`, `Event::fake()`, `Bus::fake()`) es excelente para las propias primitivas de Laravel, pero no tiene visibilidad alguna sobre un puerto de Firefly — una llamada a `CommandBus::send()` o a `EventPublisher::publish()` es invisible para `Event::fake()`, porque nada de ella pasa por el propio despachador de eventos de Laravel. Los dobles de grabación y las expectativas de Pest de `firefly/testing` son la capa de paridad: el mismo idioma de "prepara un fake, afirma sobre lo que vio" que ya conoces de Laravel, apuntado a los puertos que los demás capítulos de este libro realmente presentaron.

---

## Lo que aprendiste {.recap}

| Concepto | Qué hace |
|---|---|
| `FireflyTestCase` | Base de Testbench; tres hooks (`fireflyProviders`/`configOverrides`/`defineFireflyEnvironment`); config sembrada antes del arranque |
| `FireflyDatabaseTestCase` / `UsesSqliteMemory` | Añade una conexión sqlite `:memory:` compartida + `createSchema()` |
| `bootFireflyApp()` / `fireflyApplication()` | Arranques desnudos, sin Testbench; un menú `$needs` rellena los respaldos faltantes de cache/validation/http |
| 10 dobles de grabación | Implementaciones reales de puertos que graban lo que vieron — la capa de paridad que `Event::fake()` no puede alcanzar |
| 5 expectativas de Pest | `toHavePublished`, `toHaveHandledCommand`, `toBeUp`, `toHaveRecordedMetric`, `toBeProblemDetails` |
| `WebSliceTestCase` / `DataSliceTestCase` | Arranca solo una rebanada escaneada por PSR-4 + sobrescrituras explícitas; por clase de prueba, fallo rápido en datos |
| `#[FireflyTest]` / `#[WebSlice]` / `#[DataSlice]` | Análogo de atributo de clase de las mismas tres formas, para clases de prueba escritas a mano |
| `FixtureRegistry` / `AggregateSeeder` / `ListenerSpy` | Fixtures con nombre; reproducción de eventos de dominio; un spy compartido de orden de llamada |
| `RequiresDocker` / `fireflyConfigFor()` | Opt-in de `@group integration`; salta-no-falla sin Docker; mapeo de testcontainer → config |

---

## Ponlo en práctica {.exercises}

1. **Escribe una prueba de rebanada para uno de los propios controladores de Lumen.** Usando `WebSliceTestCase`, escanea solo `Lumen\Web\` y confirma que puedes servir un endpoint de wallet sin arrancar CQRS, EDA ni seguridad en absoluto — luego anota qué peticiones fallan, y por qué, una vez que entiendas exactamente qué beans dejó fuera la rebanada.
2. **Reproduce tú mismo la brecha de `toBeProblemDetails()`.** Llámala contra una respuesta `404` real de la propia API REST de Lumen y confirma que falla con el mensaje de clave-`type`-faltante que describió este capítulo — luego reescribe la misma aserción de la manera en que `WalletRestTest.php` realmente lo hace, y confirma que esa pasa.
3. **Añade una prueba de integración protegida por `RequiresDocker`.** Elige un repositorio respaldado por Eloquent, escribe una prueba `@group integration` contra un testcontainer Postgres real usando `fireflyConfigFor()`, y confirma que `vendor/bin/pest` (sin banderas) la salta por completo mientras que `vendor/bin/pest --group=integration` la ejecuta de verdad.
