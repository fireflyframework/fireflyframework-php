# Tutorial: construye tu primera funcionalidad LaraFly

Este es un recorrido paso a paso, construido a mano, de cómo levantar una funcionalidad LaraFly real desde
una aplicación creada con `firefly new` hasta una porción completa gobernada por atributos — un controlador REST,
un servicio, configuración tipada, un modelo de dominio respaldado por un repositorio, validación de
peticiones, manejo de errores RFC-7807, un par de comando/consulta CQRS, un listener de evento de dominio,
un outbox agnóstico de la base de datos y una saga que se compensa. Cada bloque de código de abajo es fiel
al framework tal como se distribuye en este repositorio — los atributos, nombres de clase y firmas de
método se toman literalmente de las fuentes de los paquetes `firefly/*` y del sample de wallet ejecutable
`samples/lumen`; no se inventan para el tutorial.

Al terminar habrás hecho crecer la porción de ejemplo `Greeting` del starter `firefly/skeleton` hasta
convertirla en una pequeña funcionalidad de "greetings" con una tabla `greetings` persistida, un
`#[EventListener]` que reacciona a un evento de dominio entregado a través de un outbox de base de datos, una
saga de tarjetas de felicitación que se deshace sola y un arranque cacheado sin reflexión — la misma
arquitectura que usa el [sample de Lumen](#paso-14-y-ahora-que) a mayor escala.

## Tabla de contenidos

1. [Paso 1: crea la aplicación](#paso-1-crea-la-aplicacion)
2. [Paso 2: tu primer endpoint — `#[RestController]`](#paso-2-tu-primer-endpoint-restcontroller)
3. [Paso 3: autoinyección de un servicio — `#[Service]`](#paso-3-autoinyeccion-de-un-servicio-service)
4. [Paso 4: configuración tipada — `#[ConfigProperties]`](#paso-4-configuracion-tipada-configproperties)
5. [Paso 5: ejecútala y llama a la API](#paso-5-ejecutala-y-llama-a-la-api)
6. [Paso 6: persistiendo greetings — `#[Repository]` y consultas derivadas](#paso-6-persistiendo-greetings-repository-y-consultas-derivadas)
7. [Paso 7: validando el cuerpo de la petición — `#[Valid]`](#paso-7-validando-el-cuerpo-de-la-peticion-valid)
8. [Paso 8: manejo de errores RFC-7807](#paso-8-manejo-de-errores-rfc-7807)
9. [Paso 9: comandos y consultas — CQRS](#paso-9-comandos-y-consultas-cqrs)
10. [Paso 10: reaccionando a un evento de dominio — `#[EventListener]`](#paso-10-reaccionando-a-un-evento-de-dominio-eventlistener)
11. [Paso 11: la caché sin reflexión y la introspección de salud](#paso-11-la-cache-sin-reflexion-y-la-introspeccion-de-salud)
12. [Paso 12: un outbox agnóstico de la base de datos y un consumidor](#paso-12-un-outbox-agnostico-de-la-base-de-datos-y-un-consumidor)
13. [Paso 13: una saga con compensación — `#[Saga]`](#paso-13-una-saga-con-compensacion-saga)
14. [Paso 14: y ahora qué](#paso-14-y-ahora-que)

## Requisitos previos

- **PHP 8.3+** (se recomienda 8.4) y **Composer 2**.
- Un **terminal** y un editor de código.
- `curl` (o cualquier cliente HTTP) para probar los endpoints a medida que los construyes.

Consulta [Instalación](installation.md) para la lista completa de requisitos.

---

## Paso 1: crea la aplicación

Instala LaraFly y genera una nueva aplicación con la plantilla incluida:

```bash
composer global require fireflyframework/larafly
firefly new my-app
cd my-app
```

El `post-create-project-cmd` de `firefly/skeleton` se ejecuta automáticamente y te deja con una app que ya
arranca y que ya está cacheada: copia `.env.example` a `.env`, crea `database/database.sqlite`, ejecuta
`php artisan key:generate`, ejecuta `php artisan migrate` (el recurso de ejemplo persiste en una tabla
`orders`, así que `POST /orders` funciona en la primera petición y no tras un paso que alguien tenga que
contarte) y ejecuta `php artisan firefly:cache` — el paso de compilación sin reflexión que retomarás en el
[Paso 11](#paso-11-la-cache-sin-reflexion-y-la-introspeccion-de-salud). Consulta
[Instalación](installation.md) para usar la plantilla local directamente desde un checkout del framework.

El proyecto generado tiene este aspecto:

```
my-app/
├── app/
│   ├── GreetingProperties.php      # DTO #[ConfigProperties('greeting')]
│   ├── GreetingService.php         # bean #[Service]
│   ├── Http/
│   │   ├── AddressPayload.php      # un payload #[Valid] anidado
│   │   ├── GreetingController.php  # #[RestController] — JSON
│   │   ├── OrderController.php     # #[RestController] — el ejemplo CRUD completo
│   │   ├── OrderLinePayload.php
│   │   ├── OrderRequest.php        # el DTO #[RequestBody], con restricciones
│   │   └── WelcomeController.php   # #[Controller] — la página HTML de bienvenida en /
│   └── Orders/                     # el dominio y los repositorios del ejemplo
├── bootstrap/
│   ├── app.php
│   ├── cache/firefly/              # manifiestos compilados — ya poblados
│   └── providers.php
├── config/
│   ├── app.php, cache.php, database.php, logging.php, queue.php, session.php
│   └── firefly.php                 # cada clave firefly.*, con su valor por defecto y su porqué
├── database/
│   ├── database.sqlite
│   └── migrations/                 # las tablas orders del ejemplo
├── resources/views/welcome.blade.php
├── routes/
│   ├── console.php
│   └── web.php
├── tests/
├── artisan
└── composer.json
```

| Fichero/Directorio | Propósito |
|---|---|
| `app/` | El código de tu aplicación — controladores, servicios, clases de dominio, todo lo que LaraFly escanea. |
| `app/Orders/` + `app/Http/Order*.php` | La porción vertical de ejemplo: un `#[RestController]` sobre beans `#[Repository]`, con un `#[RequestBody]` validado. Bórrala cuando ya no la necesites. |
| `config/firefly.php` | La referencia completa y comentada de cada clave `firefly.*` que lee el framework — empezando por dónde viven tus clases. |
| `bootstrap/cache/firefly/` | Los manifiestos compilados que escribe `firefly:cache` — la ruta de arranque sin reflexión. |
| `database/database.sqlite` | La base de datos por defecto — no se necesita ningún servicio externo para arrancar. |

Abre `config/firefly.php`. Es largo — documenta cada clave `firefly.*` que lee el framework, con su valor
por defecto y la razón de ese valor —, pero antes de escribir una sola línea de código solo importan dos
bloques, y son los dos primeros del fichero:

<!-- source: skeleton/config/firefly.php -->

```php
'scan' => [
    'paths' => [
        'App\\' => app_path(),
    ],
],
// …
'cache' => [
    'path' => base_path('bootstrap/cache/firefly'),
    'component_manifest' => base_path('bootstrap/cache/firefly/component.php'),
    'context_manifest' => base_path('bootstrap/cache/firefly/context.php'),
],
```

`scan.paths` es un mapa PSR-4 (prefijo → directorio) — todo atributo de la familia `#[Component]`
(`#[Service]`, `#[Repository]`, `#[RestController]`, `#[CommandHandler]`, …) bajo `app_path()` se descubre
desde aquí, ya sea mediante un escaneo en proceso durante el desarrollo o, una vez compilado, desde los
manifiestos de `cache`. Es la única clave que una aplicación tiene que acertar: déjala vacía y la app
arranca sin rutas, sin handlers, sin listeners, sin tareas programadas, sin restricciones y sin reglas de
seguridad de método.

El skeleton trae ya dos porciones cableadas de extremo a extremo. `app/Http/GreetingController.php`,
respaldado por `app/GreetingService.php` y `app/GreetingProperties.php`, es la pequeña, y es la que hace
crecer este tutorial. `app/Http/OrderController.php` sobre `app/Orders/` es la grande — un recurso CRUD
completo con un cuerpo de petición validado — y puedes leerla cuando quieras ver adónde llega este
tutorial.

---

## Paso 2: tu primer endpoint — `#[RestController]`

Abre `app/Http/GreetingController.php` — esto es lo que la plantilla ya generó por ti, íntegro:

<!-- source: skeleton/app/Http/GreetingController.php -->

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\GreetingService;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\RestController;

/**
 * The sample Firefly slice: a #[RestController] whose routes are discovered by the RouteScanner and served
 * from the compiled RouteManifest. GreetingService is autowired via constructor DI.
 *
 * `/` belongs to App\Http\WelcomeController, a #[Controller] that renders HTML — this one returns a value
 * the ResponseFactory negotiates into JSON, which is the difference between the two stereotypes.
 */
#[RestController]
final class GreetingController
{
    public function __construct(private readonly GreetingService $greetings) {}

    /** @return array<string, string> */
    #[GetMapping('/greetings/{name}', name: 'greetings.show')]
    public function show(#[PathVariable] string $name): array
    {
        return ['message' => $this->greetings->greet($name)];
    }
}
```

Elemento por elemento:

- **`#[RestController]`** — un estereotipo que extiende `Firefly\Container\Attributes\Component`. Como el
  component scan descubre estereotipos con `ReflectionAttribute::IS_INSTANCEOF`, una clase `#[RestController]`
  queda *a la vez* auto-registrada como bean singleton de DI *y* enrutada — sin registro de rutas por
  separado.
- **`#[GetMapping('/greetings/{name}', name: 'greetings.show')]`** junto con **`#[PathVariable]`** — mapea
  `GET /greetings/{name}` a `show()` y vincula automáticamente el segmento de ruta `{name}` al parámetro
  `$name`. `#[GetMapping]`/`#[PostMapping]`/`#[PutMapping]`/`#[PatchMapping]`/`#[DeleteMapping]` aceptan
  cada uno `path`, `status` (el status de respuesta por defecto) y un `name` opcional de ruta Laravel.
- Un **retorno de array plano** (`array<string, string>`) se codifica a JSON por la negociación de
  contenido del framework — no hay que construir un objeto `Response` a mano.

El docblock nombra lo único que conviene saber antes de ponerse a buscar `GET /`: aquí no está. De `/` se
encarga `app/Http/WelcomeController.php`, que lleva **`#[Controller]`** — el estereotipo HTML, el
`@Controller` de Spring frente al `@RestController` de este. El mismo escaneo de rutas encuentra los dos
porque `#[Controller]` extiende `#[RestController]`, así que el filtro `IS_INSTANCEOF` de `RouteScanner` lo
captura sin cambiar el escáner; la diferencia es que una acción `#[Controller]` devuelve una vista y esta
devuelve un valor que negociar.

Un `RouteScanner` aparte lee los metadatos de enrutamiento de la misma clase que ya encontró el component
scan — un `#[RestController]` nunca registra sus propias rutas.

---

## Paso 3: autoinyección de un servicio — `#[Service]`

Abre `app/GreetingService.php` — también generado ya:

<!-- source: skeleton/app/GreetingService.php -->

```php
<?php

declare(strict_types=1);

namespace App;

use Firefly\Container\Attributes\Service;

/**
 * A #[Service] stereotype: auto-registered as a singleton bean and resolved through the container, so its
 * GreetingProperties dependency is autowired.
 */
#[Service]
final class GreetingService
{
    public function __construct(private readonly GreetingProperties $properties) {}

    public function greet(string $name): string
    {
        return sprintf('%s, %s!', $this->properties->salutation, $name);
    }
}
```

**`#[Service]`** es un estereotipo `#[Component]` — semánticamente "lógica de negocio", registrado como
bean singleton de DI exactamente igual que `#[RestController]`. El constructor pide una instancia de
`GreetingProperties`; el contenedor inspecciona los type hints de `__construct()` y la resuelve
automáticamente — sin factory, sin XML, sin llamada manual a `bind()`. El propio constructor de
`GreetingController` funciona igual: pide `GreetingService`, y el contenedor lo cablea.

---

## Paso 4: configuración tipada — `#[ConfigProperties]`

Abre `app/GreetingProperties.php`:

<!-- source: skeleton/app/GreetingProperties.php -->

```php
<?php

declare(strict_types=1);

namespace App;

use Firefly\Config\Attributes\ConfigProperties;

/**
 * Binds the `greeting.*` configuration subtree onto this readonly DTO and registers it as a container
 * singleton, so it can be constructor-injected wherever GreetingProperties is requested.
 */
#[ConfigProperties('greeting')]
final readonly class GreetingProperties
{
    public function __construct(public string $salutation = 'Hello') {}
}
```

**`#[ConfigProperties('greeting')]`** enlaza el subárbol de configuración `greeting` (es decir,
`config('greeting')`) sobre este DTO de solo lectura y registra la *instancia ya enlazada* como singleton
del contenedor, de modo que puede inyectarse allí donde se solicite `GreetingProperties` — exactamente lo
que hace `GreetingService` arriba. No existe ningún fichero `config/greeting.php` en el skeleton, así que
`config('greeting')` resuelve a un subárbol vacío; el binder recurre entonces al valor por defecto propio
de cada parámetro del constructor (`'Hello'`) en lugar de fallar. Por eso el endpoint ya saluda con "Hello"
tal cual, sin configuración adicional.

Para sobrescribirlo, añade un fichero de configuración Laravel real — `config/greeting.php`:

<!-- illustrative: un fichero de configuración de Laravel que el lector añade en su propia aplicación; el skeleton se entrega a propósito sin él, que es lo que hace visible el valor por defecto del constructor -->

```php
<?php

declare(strict_types=1);

return [
    'salutation' => env('GREETING_SALUTATION', 'Hello'),
];
```

Y en `.env`:

```
GREETING_SALUTATION="Howdy"
```

Aquí no hay nada específico del framework — `#[ConfigProperties]` enlaza a partir de lo que resuelva
`config('greeting')`, y la propia convención de Laravel de `config/*.php` + `env()` es la que usas para
poblarlo. No hace falta volver a ejecutar `firefly:cache` por un simple cambio de valor de configuración
(solo al añadir o modificar clases anotadas — ver el [Paso 11](#paso-11-la-cache-sin-reflexion-y-la-introspeccion-de-salud)).

---

## Paso 5: ejecútala y llama a la API

Arranca el servidor de desarrollo:

```bash
php artisan firefly:serve
```

`firefly:serve` es un passthrough delgado a `artisan serve` (o a `octane:start` si `laravel/octane` está
instalado), escuchando por defecto en `127.0.0.1:8000`. En otro terminal:

```bash
curl http://127.0.0.1:8000/greetings/Ada
# {"message":"Hello, Ada!"}

curl -s http://127.0.0.1:8000/ | head -n 1
# <!DOCTYPE html>   <- la página de bienvenida, renderizada por el estereotipo #[Controller]
```

Abre también `http://127.0.0.1:8000/` en un navegador: la página de bienvenida no es un marcador de
posición estático. Lee el enum `BootPhase` real, el mismo `BeansCatalog` y el mismo
`ConditionEvaluationReport` que sirven `/actuator/beans` y `/actuator/conditions`, y el mismo
`RouteManifest` que lee el dispatcher — así que muestra las rutas reales de tu aplicación, su número de
beans y si este arranque fue compilado o escaneado.

Si configuraste `GREETING_SALUTATION="Howdy"` en el [Paso 4](#paso-4-configuracion-tipada-configproperties),
la petición de saludo ahora devuelve `"Howdy, Ada!"` — sin necesidad de reiniciar, ya que
`config('greeting')` se relee del entorno en cada petición.

---

## Paso 6: persistiendo greetings — `#[Repository]` y consultas derivadas

Hasta ahora `greet()` no tiene estado. Vamos a persistir greetings con nombre en la base de datos.

Primero, genera una migración (un comando de Laravel corriente — nada específico de Firefly aquí):

```bash
php artisan make:migration create_greetings_table
```

Edita el `database/migrations/..._create_greetings_table.php` generado:

<!-- illustrative: una migración que el lector genera y edita en su propia aplicación; las migraciones del skeleton crean las tablas orders del ejemplo, no esta -->

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('greetings', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('message');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('greetings');
    }
};
```

Ejecútala — `firefly:db` es un passthrough delgado a los propios comandos de base de datos de Laravel
(acción por defecto `migrate`):

```bash
php artisan firefly:db
```
```
INFO  Running migrations.

  2026_07_28_120500_create_greetings_table ... DONE
```

Ahora añade el modelo Eloquent, `app/Domain/Greeting.php`:

<!-- illustrative: un modelo Eloquent que el lector escribe en su propia aplicación -->

```php
<?php

declare(strict_types=1);

namespace App\Domain;

use Illuminate\Database\Eloquent\Model;

final class Greeting extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'message'];
}
```

Y un repositorio, `app/Infrastructure/GreetingRepository.php`:

<!-- illustrative: un bean #[Repository] que el lector escribe en su propia aplicación -->

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\Greeting;
use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\EloquentRepository;

/**
 * @extends EloquentRepository<Greeting>
 * @method Greeting|null findFirstByName(string $name)
 */
#[Repository]
final class GreetingRepository extends EloquentRepository
{
    protected string $model = Greeting::class;
}
```

Elemento por elemento:

- **`#[Repository]`** — otro estereotipo `#[Component]` (de la misma familia que `#[Service]`): un bean de
  contenedor, descubierto de la misma manera.
- **`EloquentRepository`** es la base respaldada por Eloquent de `firefly/data`, que implementa los
  puertos de CRUD/paginación (`save()`, `findById()`, `findAll()`, `count()`, …). Declarar
  `protected string $model` es lo único que necesita un repositorio concreto — el resto viene del padre.
- **`findFirstByName(string $name)`** nunca se implementa — no hace falta. Es una **consulta derivada**:
  `EloquentRepository::__call()` analiza el nombre del método (`DerivedQueryParser`, análisis puro de
  cadenas, sin reflexión) en `find` (prefijo) + `First` (límite 1, devuelve un único resultado anulable en
  vez de una lista) + `Name` (mapeado a la columna `name`, operador `Equals` implícito), y ejecuta
  `Greeting::query()->where('name', '=', $name)->first()`. El docblock `@method` solo está ahí para que
  PHPStan vea un retorno tipado para lo que, en tiempo de ejecución, es despacho dinámico.

Cablea el repositorio en el servicio, actualizando `app/GreetingService.php`:

<!-- illustrative: el App\GreetingService del propio lector después de que este paso lo edite; el skeleton entrega la versión del Paso 3 -->

```php
<?php

declare(strict_types=1);

namespace App;

use App\Domain\Greeting;
use App\Infrastructure\GreetingRepository;
use Firefly\Container\Attributes\Service;

#[Service]
final class GreetingService
{
    public function __construct(
        private readonly GreetingProperties $properties,
        private readonly GreetingRepository $repository,
    ) {}

    public function greet(string $name): string
    {
        return sprintf('%s, %s!', $this->properties->salutation, $name);
    }

    public function record(string $name, string $message): Greeting
    {
        return $this->repository->save(new Greeting(['name' => $name, 'message' => $message]));
    }

    public function findRecord(string $name): ?Greeting
    {
        return $this->repository->findFirstByName($name);
    }
}
```

Y expónlo, añadiendo un método `store()` a `app/Http/GreetingController.php`:

<!-- illustrative: el App\Http\GreetingController del propio lector después de que este paso le añada un método; el skeleton entrega la versión del Paso 2 -->

```php
use App\Web\Dto\CreateGreetingRequest;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestBody;

#[RestController]
final class GreetingController
{
    // … el constructor y show() del Paso 2 …

    /** @return array<string, string> */
    #[PostMapping('/greetings', status: 201)]
    public function store(#[RequestBody] CreateGreetingRequest $body): array
    {
        $greeting = $this->greetings->record($body->name, $body->message);

        return ['name' => $greeting->name, 'message' => $greeting->message];
    }
}
```

con un DTO de petición sencillo, `app/Web/Dto/CreateGreetingRequest.php`:

<!-- illustrative: un DTO de petición que el lector escribe en su propia aplicación -->

```php
<?php

declare(strict_types=1);

namespace App\Web\Dto;

final class CreateGreetingRequest
{
    public function __construct(
        public readonly string $name,
        public readonly string $message,
    ) {}
}
```

`#[RequestBody]` decodifica el cuerpo JSON e hidrata `CreateGreetingRequest` a partir de él. Pruébalo:

```bash
curl -X POST http://127.0.0.1:8000/greetings \
  -H "Content-Type: application/json" \
  -d '{"name": "Ada", "message": "Hello from the database!"}'
# {"name":"Ada","message":"Hello from the database!"}
```

Sin embargo, nada impide que se guarde un cuerpo vacío:

```bash
curl -X POST http://127.0.0.1:8000/greetings \
  -H "Content-Type: application/json" \
  -d '{"name": "", "message": ""}'
# {"name":"","message":""}   <- ¡se guarda! todavía nada lo valida.
```

Vamos a arreglar eso a continuación.

---

## Paso 7: validando el cuerpo de la petición — `#[Valid]`

Añade restricciones a `app/Web/Dto/CreateGreetingRequest.php`:

<!-- illustrative: el DTO de petición del propio lector después de que este paso le añada restricciones -->

```php
<?php

declare(strict_types=1);

namespace App\Web\Dto;

use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\Size;

final class CreateGreetingRequest
{
    public function __construct(
        #[NotBlank]
        #[Size(min: 2, max: 40)]
        public readonly string $name,
        #[NotBlank]
        public readonly string $message,
    ) {}
}
```

Y protege el parámetro del controlador con `#[Valid]`, en `app/Http/GreetingController.php`:

<!-- illustrative: el App\Http\GreetingController del propio lector después de que este paso añada #[Valid] al parámetro que añadió el Paso 6 -->

```php
use Firefly\Validation\Valid;

#[RestController]
final class GreetingController
{
    // …

    #[PostMapping('/greetings', status: 201)]
    public function store(#[Valid] #[RequestBody] CreateGreetingRequest $body): array
    {
        $greeting = $this->greetings->record($body->name, $body->message);

        return ['name' => $greeting->name, 'message' => $greeting->message];
    }
}
```

Elemento por elemento:

- **`#[NotBlank]`** (de `Firefly\Validation\Constraint`) rechaza `null`, `''` y una cadena compuesta solo
  de espacios en blanco.
- **`#[Size(min: 2, max: 40)]`** acota la longitud de la cadena.
- **`#[Valid]`** sobre el parámetro `#[RequestBody]` pasa el cuerpo decodificado en bruto por
  `Firefly\Validation\Constraint\BeanValidator` contra las reglas de restricción compiladas del DTO
  **antes** de que se construya el DTO. Solo si tiene éxito se hidrata `CreateGreetingRequest` — un fallo
  lanza la `ValidationException` del kernel y el cuerpo del método del controlador nunca llega a
  ejecutarse.

Repite la petición inválida:

```bash
curl -X POST http://127.0.0.1:8000/greetings \
  -H "Content-Type: application/json" \
  -d '{"name": "", "message": ""}'
```

```json
{
    "status": 422,
    "title": "Unprocessable Entity",
    "code": "VALIDATION_ERROR",
    "category": "validation",
    "severity": "warning",
    "detail": "Validation failed",
    "type": "about:blank",
    "errors": [
        { "field": "name", "message": "must not be blank", "constraint": "NotBlank", "rejectedValue": "" },
        { "field": "message", "message": "must not be blank", "constraint": "NotBlank", "rejectedValue": "" }
    ]
}
```

servida como `application/problem+json`, con un status `422` — la misma forma que cubre en detalle el
[Paso 8](#paso-8-manejo-de-errores-rfc-7807). La petición válida del Paso 6 sigue funcionando exactamente
igual que antes.

---

## Paso 8: manejo de errores RFC-7807

Añade un endpoint de búsqueda para un greeting guardado, en `app/Http/GreetingController.php`:

<!-- illustrative: el App\Http\GreetingController del propio lector después de que este paso le añada una acción de búsqueda -->

```php
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;

#[RestController]
final class GreetingController
{
    // …

    /** @return array<string, string> */
    #[GetMapping('/greetings/{name}/record')]
    public function showRecord(#[PathVariable] string $name): array
    {
        $greeting = $this->greetings->findRecord($name);
        if ($greeting === null) {
            throw new ResourceNotFoundException("No saved greeting for '{$name}'");
        }

        return ['name' => $greeting->name, 'message' => $greeting->message];
    }
}
```

Toda excepción del framework extiende `Firefly\Kernel\Exception\FireflyException`, que fija un status HTTP,
un `errorCode()` estable y legible por máquinas, una `ErrorCategory` y una `ErrorSeverity`.
`Firefly\Kernel\Exception\Business\ResourceNotFoundException` es una subclase tipada `404`/
`RESOURCE_NOT_FOUND` — nunca escribes un try/catch en el controlador. El
`Firefly\Web\Exception\ProblemDetailsRenderer` de `firefly/web` convierte cualquier `FireflyException`
lanzada en una respuesta `application/problem+json`, globalmente, en el `httpStatus()` propio de la
excepción.

Pruébalo:

```bash
curl http://127.0.0.1:8000/greetings/Ada/record
# {"name":"Ada","message":"Hello from the database!"}

curl -i http://127.0.0.1:8000/greetings/Nowhere/record
```

```
HTTP/1.1 404 Not Found
Content-Type: application/problem+json
```

```json
{
    "status": 404,
    "title": "Not Found",
    "code": "RESOURCE_NOT_FOUND",
    "category": "business",
    "severity": "warning",
    "detail": "No saved greeting for 'Nowhere'",
    "type": "about:blank",
    "instance": "/greetings/Nowhere/record"
}
```

No escribiste este renderer, ni una tabla de códigos de estado, ni una clase de respuesta de error — es un
único mecanismo consistente en todo el framework. Consulta [Manejo de errores](modules/error-handling.md)
para la taxonomía completa de excepciones (`ConflictException`, `AuthenticationException`,
`AuthorizationException`, y más).

---

## Paso 9: comandos y consultas — CQRS

Para una lectura, `findRecord()` del Paso 6 es suficiente. Pero una *mutación* — renombrar un greeting
guardado — es una buena candidata para `firefly/cqrs`: obtiene semántica transaccional y publicación de
eventos de dominio gratis.

Primero, enseña al modelo `Greeting` a levantar un evento de dominio al renombrarse. Actualiza
`app/Domain/Greeting.php`:

<!-- illustrative: el modelo Eloquent del propio lector después de que este paso le enseñe a registrar un evento de dominio -->

```php
<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Event\GreetingRenamed;
use Firefly\Domain\HasDomainEvents;
use Firefly\Domain\RecordsDomainEvents;
use Illuminate\Database\Eloquent\Model;

final class Greeting extends Model implements RecordsDomainEvents
{
    use HasDomainEvents;

    /** @var list<string> */
    protected $fillable = ['name', 'message'];

    public function rename(string $newMessage): void
    {
        $old = $this->message;
        $this->message = $newMessage;
        $this->raiseEvent(new GreetingRenamed($this->name, $old, $newMessage));
    }
}
```

`Firefly\Domain\HasDomainEvents` es la forma en trait del comportamiento de acumulación de eventos de
`Firefly\Domain\AggregateRoot` (`raiseEvent()`/`pendingEvents()`/`pullEvents()`/`clearEvents()`) para una
clase cuyo único slot de herencia ya está gastado en el propio `Model` de Eloquent — implementar
`RecordsDomainEvents` junto a él te da un único objeto que es a la vez una fila persistida *y* un agregado
que levanta eventos. (Un objeto de dominio puro, sin Eloquent, en cambio haría directamente
`extend AggregateRoot` — ver [Dominio (DDD)](modules/domain.md).)

Añade el evento, `app/Domain/Event/GreetingRenamed.php`:

<!-- illustrative: un DomainEvent que el lector escribe en su propia aplicación -->

```php
<?php

declare(strict_types=1);

namespace App\Domain\Event;

use Firefly\Cqrs\Attributes\PublishDomainEvent;
use Firefly\Domain\DomainEvent;

#[PublishDomainEvent('greeting.events')]
final readonly class GreetingRenamed extends DomainEvent
{
    public function __construct(
        public string $name,
        public string $oldMessage,
        public string $newMessage,
    ) {
        parent::__construct();
    }
}
```

`#[PublishDomainEvent('greeting.events')]` enruta este evento hacia el destino de evento de integración
`greeting.events` en cuanto se puentea hacia el bus de eda tras el commit (ver el
[Paso 10](#paso-10-reaccionando-a-un-evento-de-dominio-eventlistener)).

Ahora el lado de escritura — un comando y su handler. `app/Application/Command/RenameGreeting.php`:

<!-- illustrative: un mensaje de comando que el lector escribe en su propia aplicación -->

```php
<?php

declare(strict_types=1);

namespace App\Application\Command;

final readonly class RenameGreeting
{
    public function __construct(public string $name, public string $newMessage) {}
}
```

`app/Application/Command/RenameGreetingHandler.php`:

<!-- illustrative: un #[CommandHandler] que el lector escribe en su propia aplicación -->

```php
<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Infrastructure\GreetingRepository;
use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;

/**
 * Intentionally NOT final: the generated #[Transactional] proxy subclasses this handler.
 */
#[CommandHandler]
class RenameGreetingHandler
{
    public function __construct(private readonly GreetingRepository $greetings) {}

    #[Transactional]
    public function handle(RenameGreeting $command): void
    {
        $greeting = $this->greetings->findFirstByName($command->name);
        if ($greeting === null) {
            throw new ResourceNotFoundException("No saved greeting for '{$command->name}'");
        }

        $greeting->rename($command->newMessage);
        $this->greetings->save($greeting);
    }
}
```

Y el lado de lectura — una consulta y su handler. `app/Application/Query/GetGreeting.php`:

<!-- illustrative: un mensaje de consulta que el lector escribe en su propia aplicación -->

```php
<?php

declare(strict_types=1);

namespace App\Application\Query;

final readonly class GetGreeting
{
    public function __construct(public string $name) {}
}
```

`app/Application/Query/GetGreetingHandler.php`:

<!-- illustrative: un #[QueryHandler] que el lector escribe en su propia aplicación -->

```php
<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Greeting;
use App\Infrastructure\GreetingRepository;
use Firefly\Cqrs\Attributes\QueryHandler;

#[QueryHandler]
final class GetGreetingHandler
{
    public function __construct(private readonly GreetingRepository $greetings) {}

    public function handle(GetGreeting $query): ?Greeting
    {
        return $this->greetings->findFirstByName($query->name);
    }
}
```

Elemento por elemento:

- **`#[CommandHandler]`** / **`#[QueryHandler]`** especializan ambos a `#[Component]` — un único atributo a
  la vez registra el handler como bean de DI inyectado por constructor *y* le dice a `HandlerScanner` cómo
  construir el manifiesto comando/consulta → handler. Cada handler expone exactamente un método público
  `handle()`; el tipo de mensaje manejado se infiere del único parámetro de ese método (pasa un
  class-string explícito, `#[CommandHandler(RenameGreeting::class)]`, solo si necesitas desambiguar).
- **`#[Transactional]`** sobre `handle()` es estructural, no decorativo: un proxy generado envuelve la
  llamada real en una transacción, y tras un commit exitoso vacía los eventos de dominio acumulados de
  `Greeting` y los publica — sin él, `GreetingRenamed` nunca se levantaría hacia el bus de eda. Ver
  [Transacciones](modules/transactional.md).
- `RenameGreetingHandler` **no** es `final` — el framework genera
  `RenameGreetingHandler__FireflyTransactionalProxy extends RenameGreetingHandler`, lo cual fallaría de
  forma fatal al cargar la clase si el padre fuese `final`.

Cablea ambos buses en el controlador y cambia `showRecord()` para que use `QueryBus::ask()`, actualizando
`app/Http/GreetingController.php`:

<!-- illustrative: el App\Http\GreetingController del propio lector una vez aplicados todos los pasos de este tutorial -->

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Command\RenameGreeting;
use App\Application\Query\GetGreeting;
use App\GreetingService;
use App\Web\Dto\CreateGreetingRequest;
use App\Web\Dto\RenameGreetingRequest;
use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Query\QueryBus;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Validation\Valid;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PatchMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RestController;

#[RestController]
final class GreetingController
{
    public function __construct(
        private readonly GreetingService $greetings,
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

    /** @return array<string, string> */
    #[GetMapping('/greetings/{name}', name: 'greetings.show')]
    public function show(#[PathVariable] string $name): array
    {
        return ['message' => $this->greetings->greet($name)];
    }

    /** @return array<string, string> */
    #[PostMapping('/greetings', status: 201)]
    public function store(#[Valid] #[RequestBody] CreateGreetingRequest $body): array
    {
        $greeting = $this->greetings->record($body->name, $body->message);

        return ['name' => $greeting->name, 'message' => $greeting->message];
    }

    /** @return array<string, string> */
    #[GetMapping('/greetings/{name}/record')]
    public function showRecord(#[PathVariable] string $name): array
    {
        /** @var \App\Domain\Greeting|null $greeting */
        $greeting = $this->queries->ask(new GetGreeting($name));
        if ($greeting === null) {
            throw new ResourceNotFoundException("No saved greeting for '{$name}'");
        }

        return ['name' => $greeting->name, 'message' => $greeting->message];
    }

    /** @return array<string, string> */
    #[PatchMapping('/greetings/{name}/record')]
    public function rename(#[PathVariable] string $name, #[Valid] #[RequestBody] RenameGreetingRequest $body): array
    {
        $this->commands->send(new RenameGreeting($name, $body->message));

        /** @var \App\Domain\Greeting $greeting */
        $greeting = $this->queries->ask(new GetGreeting($name));

        return ['name' => $greeting->name, 'message' => $greeting->message];
    }
}
```

con un pequeño DTO adicional, `app/Web/Dto/RenameGreetingRequest.php`:

<!-- illustrative: un DTO de petición que el lector escribe en su propia aplicación -->

```php
<?php

declare(strict_types=1);

namespace App\Web\Dto;

use Firefly\Validation\Constraint\NotBlank;

final class RenameGreetingRequest
{
    public function __construct(
        #[NotBlank]
        public readonly string $message,
    ) {}
}
```

**`CommandBus::send(object $command): mixed`** es el lado de escritura; **`QueryBus::ask(object $query): mixed`**
es el lado de lectura (`ask`, y no `query`, para no colisionar con el propio `query()` de Eloquent). Ambos
ejecutan un pipeline acotado (correlacionar → validar → autorizar → resolver e invocar el handler →
métricas) antes de llegar a tu handler. Pruébalo:

```bash
curl -X PATCH http://127.0.0.1:8000/greetings/Ada/record \
  -H "Content-Type: application/json" \
  -d '{"message": "Hello again from the database!"}'
# {"name":"Ada","message":"Hello again from the database!"}
```

---

## Paso 10: reaccionando a un evento de dominio — `#[EventListener]`

`GreetingRenamed` es un evento de dominio, levantado en proceso por `Greeting::rename()`. En cuanto se
confirma la unidad de trabajo `#[Transactional]` de `RenameGreetingHandler`, el puente
dominio → evento-de-integración de `firefly/cqrs` lo vuelve a publicar en el bus de broker de `firefly/eda`
como un evento de integración — `eventType` pasa a ser el nombre corto de la clase del evento
(`"GreetingRenamed"`), y `destination` viene de `#[PublishDomainEvent]` (`"greeting.events"`).

Añade un listener, `app/Listener/GreetingRenamedListener.php`:

<!-- illustrative: un bean #[EventListener] que el lector escribe en su propia aplicación -->

```php
<?php

declare(strict_types=1);

namespace App\Listener;

use Firefly\Container\Attributes\Component;
use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\EventEnvelope;
use Illuminate\Support\Facades\Log;

#[Component]
final class GreetingRenamedListener
{
    #[EventListener('GreetingRenamed')]
    public function onGreetingRenamed(EventEnvelope $envelope): void
    {
        Log::info('greeting.renamed', $envelope->payload);
    }
}
```

Elemento por elemento:

- **`#[Component]`** — un bean sencillo; el listener no necesita ningún estereotipo HTTP ni de CQRS, solo
  registro en el DI.
- **`#[EventListener('GreetingRenamed')]`** se suscribe al bus de broker de eda, **no** al bus de eventos de
  aplicación en proceso (ese es el `#[AsEventListener]` de `firefly/context`, algo no relacionado). El
  patrón se compara contra el `eventType` del envelope con `fnmatch()` — una cadena plana como
  `'GreetingRenamed'` se comporta como una coincidencia exacta; también podrías pasar un glob
  (`'Greeting*'`) o un array de varios nombres exactos. El patrón debe nombrar el **tipo** del evento,
  nunca la cadena de destino de `#[PublishDomainEvent]`.
- **`EventEnvelope`** transporta `eventType`, `destination`, `payload` (los campos públicos del evento —
  `name`, `oldMessage`, `newMessage`, más el propio `eventId`/`occurredAt` de `DomainEvent`) y `headers`.

Con el proveedor de eda en memoria por defecto del skeleton, la entrega es síncrona — el listener se
ejecuta antes de que la petición `PATCH` del Paso 9 siquiera devuelva su respuesta. Vuelve a lanzar el
`PATCH` del Paso 9 y comprueba el log:

```bash
tail -n 1 storage/logs/laravel.log
```

```
[2026-07-28 12:10:03] local.INFO: greeting.renamed {"name":"Ada","oldMessage":"Hello from the database!","newMessage":"Hello again from the database!", ...}
```

Consulta [Arquitectura orientada a eventos](modules/eda.md) para el modelo completo de broker/adaptador y
[CQRS](modules/cqrs.md) para ver exactamente cómo se dispara el puente dominio → evento-de-integración.

---

## Paso 11: la caché sin reflexión y la introspección de salud

Cada clase que has añadido desde el Paso 1 — el repositorio, los handlers de comando/consulta, el listener
de evento — necesita compilarse en los manifiestos de la app antes de que se recoja en un arranque
cacheado. Vuelve a ejecutar:

```bash
php artisan firefly:cache
```

```
firefly:cache — wrote 15 manifest(s) + 1 proxy(ies) to /path/to/my-app/bootstrap/cache/firefly
```

El recuento de manifiestos es fijo, quince, porque `ManifestCacheWriter` escribe cada artefacto sin
condiciones — una aplicación sin ningún método `#[Scheduled]` obtiene igualmente un `scheduled.php` vacío,
que es lo que permite a la ruta de arranque leer "el fichero no está" como "nadie ha compilado todavía" y no
como "no hay nada que cargar". Este único comando ejecuta *cada* par escáner → compilador ya asentado de
cada paquete sobre `config('firefly.scan.paths')` y escribe, en `bootstrap/cache/firefly/`: los manifiestos
de DI/context/config-properties, la tabla de rutas compilada, el manifiesto de handlers
`#[ControllerAdvice]`, el manifiesto de restricciones de validación, el manifiesto de handlers de CQRS, los
manifiestos de listeners de evento/mensaje, el manifiesto de tareas programadas, el manifiesto de orquestación
(`#[Saga]`/`#[Tcc]`/`#[Workflow]`), el manifiesto de métodos de
seguridad, el manifiesto de `#[Transactional]`, el **plan de proxies** (`proxy-plan.php` — qué beans reciben
un proxy y qué advice ejecuta cada uno de sus métodos) y el classmap de proxies, **y** un fichero de clase
`RenameGreetingHandler__FireflyTransactionalProxy` generado (el "1 proxy" de arriba). Se emite un proxy por
cada clase que reclama el *plan*, que es toda clase nombrada por cualquier advice source — así que un
`#[Service]` que solo lleve `#[PreAuthorize]` recibe un proxy igual que uno `#[Transactional]`; en esta
aplicación `RenameGreetingHandler` resulta ser el único. Un `FireflyCacheServiceProvider` enlaza estos
manifiestos compilados antes de cualquier resolución de beans, de modo que toda petición posterior corre
contra simples arrays de PHP — sin reflexión en tiempo de ejecución en la ruta caliente. Vuelve a ejecutarlo
cada vez que añadas o cambies una clase anotada; `php artisan firefly:clear` borra la caché y vuelve al
escáner en proceso.

Ahora introspecciona la app en ejecución desde el terminal, en proceso — sin ida y vuelta HTTP:

```bash
php artisan firefly:health
```

```json
{
    "status": "UP"
}
```

`firefly:health` resuelve el mismo `ActuatorRegistry` que resolvería una petición HTTP
`GET /actuator/health` e imprime su respuesta — la CLI no reimplementa ninguna lógica propia de actuator.
Prueba también la ruta HTTP:

```bash
curl http://127.0.0.1:8000/actuator/health
# {"status":"UP"}

curl -i http://127.0.0.1:8000/actuator/env
# HTTP/1.1 404 Not Found   <- los endpoints sensibles no se exponen hasta que lo activas
```

Consulta la [Referencia de la CLI](cli.md) para cada comando `firefly:*` y generador `make:firefly-*`
(incluyendo `make:firefly-handler`, `make:firefly-listener` y `make:firefly-repository`, que generan
exactamente los estereotipos que acabas de escribir a mano), y [Actuator](modules/actuator.md) para la
lista completa de endpoints y el modelo de exposición/seguridad.

---

## Paso 12: un outbox agnóstico de la base de datos y un consumidor

En el Paso 10 el listener se ejecutó dentro de la petición `PATCH`, porque el bus de eventos del skeleton vive en
memoria: rápido, y perdido si el proceso muere entre el commit y el listener. El outbox de base de datos cierra ese
hueco sin que cambie una sola línea de tu código. El evento se escribe en una tabla **en la misma transacción** que
el greeting renombrado, y un worker lo entrega después — igual en SQLite, MySQL, MariaDB, PostgreSQL o SQL Server.

El proveedor es la primera clave del bloque `eda` de `config/firefly.php`:

<!-- source: skeleton/config/firefly.php -->

```php
'eda' => [

    'provider' => env('FIREFLY_EDA_PROVIDER', 'memory'),
    // …
],
```

Cámbialo con la variable de entorno y crea la tabla del outbox — la migración se registra en cuanto el proveedor
es `database`:

```bash
echo 'FIREFLY_EDA_PROVIDER=database' >> .env
php artisan migrate        # crea firefly_eda_outbox
```

Reinicia `php artisan firefly:serve`, vuelve a renombrar el greeting y mira el log:

```bash
curl -X PATCH http://127.0.0.1:8000/greetings/Ada/record \
  -H "Content-Type: application/json" \
  -d '{"message": "Hello from the outbox!"}'
# {"name":"Ada","message":"Hello from the outbox!"}

tail -n 1 storage/logs/laravel.log   # todavía la línea del Paso 10: nada ha entregado aún el evento nuevo
```

El evento `GreetingRenamed` es una fila `PENDING` en `firefly_eda_outbox`, confirmada junto con el mensaje nuevo.
Entrégalo con el consumidor — un worker de larga duración en producción, un solo mensaje aquí:

```bash
php artisan firefly:eda:consume --max-messages=1
tail -n 1 storage/logs/laravel.log
```

```
[2026-07-28 12:20:41] local.INFO: greeting.renamed {"name":"Ada","oldMessage":"Hello again from the database!","newMessage":"Hello from the outbox!", ...}
```

Qué cambió y qué no:

- **Tu código no cambió.** `GreetingRenamedListener`, `RenameGreetingHandler` y `#[PublishDomainEvent]` están
  exactamente como los dejaron los Pasos 9 y 10. El outbox se engancha *antes* del commit, a través de la misma
  frontera `#[Transactional]` que publicaba el evento después de él.
- **La atomicidad sí.** Si el handler lanza una excepción después de `rename()`, la fila se revierte con el
  greeting: ningún evento para una escritura que nunca ocurrió, y — como la fila se confirma con el greeting —
  ningún evento perdido para una que sí ocurrió.
- **La entrega es un worker.** La fila se *arrienda* a un consumidor antes de que se ejecute el listener, así que
  puedes lanzar tantos workers `php artisan firefly:eda:consume` como quieras. En SQLite la estrategia de
  reclamación `auto` es un arrendamiento optimista; en PostgreSQL, MySQL 8.0.1+ y MariaDB 10.6+ es
  `FOR UPDATE SKIP LOCKED`; en SQL Server, `READPAST`. Un worker que cae a mitad de una entrega deja su fila para
  que se entregue de nuevo cuando termine el arrendamiento (`firefly.eda.database.lease-seconds`, 300 por
  defecto) — así que mantén tus listeners idempotentes.
- **PostgreSQL puede hacerlo mejor.** `FIREFLY_EDA_PROVIDER=postgres` es el mismo outbox más un `pg_notify` al
  escribir y un despertar por `LISTEN` en el consumidor, de modo que la entrega es inmediata en lugar de por sondeo.

Consulta [Outbox de base de datos de EDA](modules/eda-database.md) para las estrategias de reclamación, el relay
opcional a RabbitMQ o Kafka (`php artisan firefly:outbox:relay`) y el componente de salud `outbox`, y la
[Matriz de soporte de bases de datos](database-support.md) para lo que hace cada servidor.

---

## Paso 13: una saga con compensación — `#[Saga]`

Hasta aquí todo ha cabido en una transacción. Vender una tarjeta de felicitación no cabe: reserva una tarjeta
impresa del stock, cobra al cliente a través de un proveedor de pagos y entrega la tarjeta a un servicio de envío —
tres sistemas, tres commits y ninguna transacción que los abarque. Si falla el envío, el cobro ya se ha hecho. La
respuesta es una **saga**: cada paso confirma por su cuenta, cada paso que cambió algo nombra una *compensación*
que lo deshace, y un fallo ejecuta las compensaciones de los pasos completados, del más reciente al más antiguo.

Los tres sistemas tienen un único sustituto, `app/Cards/CardDesk.php` — en una aplicación real cada uno sería un
adaptador detrás de su propio puerto; aquí una sola clase en memoria mantiene el paso centrado en la saga, y su
`$journal` registra cada llamada:

<!-- source: tests/Fixtures/Tutorial/app/Cards/CardDesk.php -->

```php
<?php

declare(strict_types=1);

namespace App\Cards;

use Firefly\Container\Attributes\Component;
use RuntimeException;

/**
 * A stand-in for the three systems a greeting-card order touches — the card stock, the payment provider and the
 * mailer. A real application has one adapter per system; one in-memory class keeps this step about the saga.
 * $journal records every call, in order.
 */
#[Component]
final class CardDesk
{
    /** @var list<string> */
    public array $journal = [];

    private int $cards = 0;

    private int $payments = 0;

    public function reserve(): string
    {
        $card = 'card-'.++$this->cards;
        $this->journal[] = "reserve {$card}";

        return $card;
    }

    public function release(string $card): void
    {
        $this->journal[] = "release {$card}";
    }

    public function charge(int $amountMinor): string
    {
        $payment = 'payment-'.++$this->payments;
        $this->journal[] = "charge {$payment} {$amountMinor}";

        return $payment;
    }

    public function refund(string $payment): void
    {
        $this->journal[] = "refund {$payment}";
    }

    public function send(string $card, string $to): string
    {
        if ($to === 'Nobody') {
            throw new RuntimeException("The mailer has no address for {$to}.");
        }

        $this->journal[] = "send {$card} to {$to}";

        return "sent {$card} to {$to}";
    }
}
```

Ahora la saga, `app/Cards/GreetingCardSaga.php`:

<!-- source: tests/Fixtures/Tutorial/app/Cards/GreetingCardSaga.php -->

```php
<?php

declare(strict_types=1);

namespace App\Cards;

use Firefly\Container\Attributes\Service;
use Firefly\Orchestration\Core\Argument\FromStep;
use Firefly\Orchestration\Core\Argument\Input;
use Firefly\Orchestration\Saga\Attributes\Saga;
use Firefly\Orchestration\Saga\Attributes\SagaStep;

#[Service]
#[Saga(name: 'greeting-card')]
final class GreetingCardSaga
{
    public const int PRICE_MINOR = 300;

    public function __construct(private readonly CardDesk $desk) {}

    #[SagaStep(id: 'reserve', compensate: 'release')]
    public function reserve(): string
    {
        return $this->desk->reserve();
    }

    public function release(string $card): void
    {
        $this->desk->release($card);
    }

    #[SagaStep(id: 'charge', dependsOn: ['reserve'], compensate: 'refund')]
    public function charge(): string
    {
        return $this->desk->charge(self::PRICE_MINOR);
    }

    public function refund(string $payment): void
    {
        $this->desk->refund($payment);
    }

    #[SagaStep(id: 'send', dependsOn: ['charge'])]
    public function send(#[FromStep('reserve')] string $card, #[Input('to')] string $to): string
    {
        return $this->desk->send($card, $to);
    }
}
```

Elemento a elemento:

- **`#[Saga(name: 'greeting-card')]`** sobre un bean `#[Service]` declara la saga; `firefly:cache` la compila en
  el manifiesto de orquestación, y la topología se valida en el arranque.
- **`#[SagaStep(id: 'reserve', compensate: 'release')]`** — un paso hacia delante y el método que lo deshace. La
  compensación recibe el resultado del paso: `release()` recibe el id de tarjeta que devolvió `reserve()`.
- **`dependsOn: ['reserve']`** ordena los pasos en capas: `charge` solo se ejecuta después de que `reserve` haya
  tenido éxito, y `send` solo después de `charge`.
- **`#[FromStep('reserve')]`** le pasa a `send()` la tarjeta que reservó el primer paso; **`#[Input('to')]`** lee
  una clave de la entrada de la saga.
- `send` no tiene compensación: es el último paso, así que nada posterior puede fallar y dejarlo colgado.

Exponla, `app/Http/GreetingCardController.php`:

<!-- source: tests/Fixtures/Tutorial/app/Http/GreetingCardController.php -->

```php
<?php

declare(strict_types=1);

namespace App\Http;

use Firefly\Kernel\Exception\Business\ConflictException;
use Firefly\Orchestration\Saga\Engine\SagaEngine;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RestController;

#[RestController]
final class GreetingCardController
{
    public function __construct(private readonly SagaEngine $sagas) {}

    /** @return array{order: string, status: string} */
    #[PostMapping('/cards/{to}', status: 201)]
    public function send(#[PathVariable] string $to): array
    {
        $result = $this->sagas->execute('greeting-card', ['to' => $to]);

        if (! $result->success) {
            throw new ConflictException("The card for {$to} was not sent; every completed step was undone.", 'CARD_NOT_SENT', $result->error);
        }

        return ['order' => $result->correlationId, 'status' => $result->status->value];
    }
}
```

`SagaEngine::execute()` ejecuta la saga dentro de la petición y devuelve un `SagaResult`; una saga fallida se
convierte en una `ConflictException`, que la capa web renderiza como problem+json RFC-7807 exactamente igual que
los errores del Paso 8. Vuelve a compilar y prueba los dos casos:

```bash
php artisan firefly:cache

curl -X POST http://127.0.0.1:8000/cards/Ada
# {"order":"…","status":"COMPLETED"}

curl -i -X POST http://127.0.0.1:8000/cards/Nobody
# HTTP/1.1 409 Conflict   <- problem+json con el código CARD_NOT_SENT
```

El segundo pedido reservó una tarjeta y cobró al cliente antes de que el envío fallara. El journal muestra lo que
pasó después, y el propio test del repositorio (`tests/TutorialGreetingCardSagaTest.php`) comprueba exactamente
este orden:

```
reserve card-1
charge payment-1 300
refund payment-1
release card-1
```

El cobro se reembolsó **antes** de liberar la tarjeta — del más reciente al más antiguo, la política
`strict-sequential` que `firefly.orchestration.saga.compensation-policy` tiene por defecto. Por defecto los puntos
de control de la saga y sus cartas muertas viven en memoria, lo adecuado para una saga que termina dentro de una
petición. Define `FIREFLY_ORCHESTRATION_PERSISTENCE=database` y `FIREFLY_ORCHESTRATION_DLQ_STORE=database`
(después de `php artisan vendor:publish --tag=firefly-orchestration-migrations` y `php artisan migrate`) y cada
frontera de paso se escribe en `firefly_orchestration_executions`, un pedido fallido acaba en
`firefly_orchestration_dead_letters`, y `php artisan firefly:orchestration:list --name=greeting-card` y
`php artisan firefly:orchestration:dlq --name=greeting-card` los muestran. Consulta
[Orquestación](modules/orchestration.md) para las cinco políticas de compensación, TCC, los workflows duraderos
que esperan señales y temporizadores, la recuperación y los comandos de operación.

---

## Paso 14: y ahora qué

Has construido una pequeña porción de funcionalidad — `#[RestController]` → `#[Service]` →
`#[ConfigProperties]` → `#[Repository]` → `#[Valid]` → errores RFC-7807 →
`#[CommandHandler]`/`#[QueryHandler]` → `#[EventListener]` → un outbox de base de datos → una `#[Saga]` →
un arranque cacheado, sin reflexión — usando
únicamente los atributos reales y ya distribuidos del propio framework. Desde aquí:

### Fundamentos

- [Inyección de dependencias](modules/dependency-injection.md) — toda la familia `#[Component]`, los
  scopes, los beans condicionales y cómo funciona el manifiesto compilado.
- [Configuración](modules/configuration.md) — perfiles, `#[Value]` y DTOs `#[ConfigProperties]` anidados.
- [Validación](modules/validation.md) — el catálogo completo de restricciones (incluidas las reglas del
  dominio financiero).

### Web, datos y dominio

- [Capa web](modules/web.md) — el binding de parámetros (`#[QueryParam]`, `#[RequestHeader]`,
  `#[UploadedFile]`), la negociación de contenido y el modelo completo de cascada de `#[Valid]`.
- [Datos y repositorios](modules/data.md) — la gramática completa de consultas derivadas, `#[Query]`, las
  `Specification` y la paginación.
- [Datos relacionales](modules/data-relational.md) y [Transacciones](modules/transactional.md) — todo lo
  que hacen `EloquentRepository` y `#[Transactional]` más allá de este tutorial (modos de propagación,
  aislamiento, reglas de rollback).
- [Dominio (DDD)](modules/domain.md) — `Entity`, `ValueObject`, `AggregateRoot`, y el modelo completo de
  eventos tras el commit.
- [Manejo de errores](modules/error-handling.md) — la taxonomía completa de excepciones y
  `#[ExceptionHandler]`/`#[ControllerAdvice]`.

### CQRS, eventos y mensajería

- [CQRS (comando/consulta)](modules/cqrs.md) — el pipeline acotado, el puente dominio →
  evento-de-integración en detalle, y los mecanismos de autorización/caché.
- [Arquitectura orientada a eventos](modules/eda.md) — la política de reintento/DLQ, el adaptador de cola,
  y la distinción entre las dos superficies de evento (`#[AsEventListener]` frente a `#[EventListener]`).
- [Brokers de EDA](modules/eda-brokers.md) y [Mensajería](modules/messaging.md) — adaptadores de broker
  reales y el `MessageBrokerPort` de bajo nivel, de bytes en bruto.
- [Outbox de base de datos de EDA](modules/eda-database.md) — el outbox portable del Paso 12: las estrategias
  de reclamación, el relay y la matriz de soporte de bases de datos.
- [Orquestación](modules/orchestration.md) — sagas, TCC y workflows duraderos: todo lo que el Paso 13 dejó
  fuera.
- [Webhooks](modules/webhooks.md) y [Callbacks](modules/callbacks.md) — webhooks firmados de entrada,
  callbacks firmados de salida, ambos sobre el bus de eventos.

### Producción

- [Seguridad](modules/security.md) — autenticación persistida en sesión, login por formulario, HTTP Basic,
  JWT, reglas de URL `HttpSecurity` con denegación por defecto y `#[PreAuthorize]` sobre cualquier bean
  estereotipado.
- [Cliente OAuth2](modules/security-oauth2-client.md) y
  [Servidor de autorización OAuth2](modules/security-oauth2-server.md) — iniciar sesión con Google, GitHub,
  Okta, Keycloak o Entra, y emitir tus propios tokens.
- [Actuator](modules/actuator.md) y [Observabilidad](modules/observability.md) — el conjunto completo de
  endpoints y el núcleo de métricas al estilo Prometheus/Micrometer.
- [Trazas](modules/tracing.md) y [Logging](modules/logging.md) — un `traceparent` W3C propagado por HTTP,
  por los buses de CQRS y por el bus de eventos, y los mismos identificadores en cada registro de log
  estructurado.
- [Resiliencia](modules/resilience.md), [Planificación](modules/scheduling.md) y
  [Bloqueos de planificación en base de datos](modules/scheduling-database.md) — `Retry`/
  `CircuitBreaker`/`Bulkhead`, `#[Scheduled]` y el bloqueo que mantiene una tarea en un solo nodo.
- [Testing](modules/testing.md) — el arnés de arranque de `firefly/testing` y los dobles de grabación para
  cada puerto usado en este tutorial, y [Pruebas de integración](modules/integration-testing.md) para los
  mismos tests sobre servidores reales.

### Verlo a escala

El [sample de Lumen](https://github.com/fireflyframework/fireflyframework-php/tree/main/samples/lumen) es
una porción vertical ejecutable de wallet digital y ledger que ejercita exactamente los mismos primitivos
que acabas de usar — `#[Transactional]`, CQRS, eventos de dominio sobre EDA, seguridad a nivel de método y
renderizado de errores RFC-7807 — a la escala de un agregado completo con múltiples comandos, consultas y
un proyector de modelo de lectura.

---

Apache-2.0 © Firefly Software Solutions Inc.
