<span class="eyebrow">Inicio rápido</span>

# Construye Lumen paso a paso {.chtitle}

Bienvenido. Antes de la inmersión profunda, este capítulo te lleva desde una *terminal vacía* hasta una aplicación LaraFly *en ejecución y consultable con curl* — instalada, compilada y servida — en bastante menos de diez minutos. Cada comando y cada listado de este capítulo son reales: es exactamente lo que genera `composer create-project firefly/skeleton`, sin modificar.

Esto es un *recorrido*, no la inmersión profunda. El Capítulo 1 argumenta el enfoque completo; el Capítulo 2 abre la sala de máquinas y explica, en profundidad, todo lo que aquí solo mencionas de pasada — el contenedor, los estereotipos y el manifiesto de arranque compilado. El objetivo de este capítulo es el impulso inicial: al terminarlo tendrás un servicio real en ejecución, y una primera sensación informal de lo que significa "convención sobre configuración" en LaraFly.

!!! note "Nota"
    Todos los listados de este capítulo se copian literalmente de `skeleton/`, el andamiaje de proyecto que instala `composer create-project firefly/skeleton`, y de `samples/lumen`, la aplicación más completa de monedero digital y libro mayor que este libro construye a lo largo de los capítulos siguientes.

---

## Paso 1 — Requisitos previos

LaraFly es un framework PHP construido sobre Laravel 13. Necesitas:

* **PHP 8.3 o más reciente**, con las extensiones que el propio Laravel requiere (`mbstring`, `openssl`, `pdo`, `tokenizer`, `xml`, `ctype`, `json`).
* **Composer 2**, el gestor de dependencias de PHP.

Confirma que ambos están disponibles:

```bash
php --version
composer --version
```

---

## Paso 2 — Instalación

La forma más rápida de iniciar una nueva aplicación LaraFly es `composer create-project`, apuntando a la plantilla `firefly/skeleton`:

```bash
composer create-project firefly/skeleton my-app
cd my-app
```

`composer create-project` hace más que copiar archivos: ejecuta un script `post-create-project-cmd` que termina de preparar el proyecto por ti —

```json
"post-create-project-cmd": [
    "@php -r \"file_exists('.env') || copy('.env.example', '.env');\"",
    "@php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\"",
    "@php artisan key:generate --ansi",
    "@php artisan firefly:cache"
]
```

Para cuando ese comando termina, `.env` ya existe, se ha creado el archivo de base de datos SQLite, `APP_KEY` está configurada y — el paso que más importa para este libro — **`firefly:cache` ya ha compilado los manifiestos de tu aplicación**. Todavía no has escrito una sola línea de PHP, y la ruta de arranque sin reflexión sobre la que se construye todo este framework ya está en su sitio.

!!! tip "Un instalador global, si lo prefieres"
    `composer global require firefly/installer` te da un comando `firefly` en tu `PATH`. `firefly new my-app` envuelve la misma llamada a `composer create-project firefly/skeleton`, y luego ejecuta `git init` y un commit inicial por ti — el equivalente en LaraFly de `laravel new`.

### Qué acabas de instalar

El `composer.json` del andamiaje requiere solo dos paquetes de Firefly directamente:

```json
"require": {
    "php": "^8.3",
    "firefly/cli": "*@dev",
    "firefly/firefly": "*@dev",
    "laravel/framework": "^13.0"
}
```

`firefly/cli` te da los comandos `artisan firefly:*` que usarás a lo largo de este libro. `firefly/firefly` es el **metapaquete de tiempo de ejecución** — el análogo en Composer de una lista de materiales (BOM) de Maven — que arrastra toda la familia Firefly (contenedor, contexto, configuración, web, datos, cqrs, eda, seguridad, validación, resiliencia, programación, observabilidad, actuator y más) en una sola línea `require`, de modo que tu propio `composer.json` nunca tiene que enumerarlos uno a uno.

!!! laravel "Paridad con Laravel"
    `composer create-project firefly/skeleton` es el equivalente en LaraFly de `laravel new` — y `firefly/firefly` es el equivalente de instalar el propio `laravel/framework`: una línea de dependencia que trae una pila completa y coherente en lugar de una colección de piezas versionadas de forma independiente.

---

## Paso 3 — Un vistazo por dentro

Abre el proyecto generado. Hay dos cosas que merece la pena notar de inmediato, porque son la idea completa de este framework en miniatura.

Primero, `bootstrap/providers.php` está vacío:

```php
<?php

return [];
```

No hay ningún proveedor de servicios que registrar a mano. Los propios proveedores de `firefly/cli` y `firefly/firefly` se descubren automáticamente mediante el descubrimiento de paquetes de Composer/Laravel — nunca añades una línea aquí por un paquete de Firefly.

Segundo, `routes/web.php` también está casi vacío:

```php
<?php

// Intentionally minimal: Firefly's WebServiceProvider registers the app's #[RestController] routes from
// the compiled RouteManifest (see skeleton/app/Http/GreetingController.php). This file exists because
// bootstrap/app.php's withRouting(web: ...) requires the path.
```

Aquí no se declara ninguna ruta. Como dice el comentario, vienen de un **`RouteManifest` compilado** — construido a partir de atributos en tus controladores, no de un archivo de rutas que mantienes a mano. Enseguida conocerás la clase a la que apunta ese comentario.

El único archivo que sí importa ahora mismo es `config/firefly.php`:

```php
<?php

declare(strict_types=1);

return [
    'scan' => [
        'paths' => [
            'App\\' => app_path(),
        ],
    ],
    'cache' => [
        'path' => base_path('bootstrap/cache/firefly'),
        'component_manifest' => base_path('bootstrap/cache/firefly/component.php'),
        'context_manifest' => base_path('bootstrap/cache/firefly/context.php'),
    ],
];
```

`scan.paths` le dice al escáner de componentes de LaraFly qué raíces PSR-4 mirar — aquí, solo tu propio namespace `App\` bajo `app/`. `cache.path` es el lugar donde `firefly:cache` (que ya ejecutaste, en el Paso 2) escribió los manifiestos compilados que encontró allí.

---

## Paso 4 — Conoce el primer bean

`app/GreetingProperties.php` es un pequeño DTO de configuración tipada — la respuesta de LaraFly a `@ConfigurationProperties` de Spring:

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

`app/GreetingService.php` es una clase PHP corriente que lleva un único atributo, `#[Service]`:

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

Nada aquí registra `GreetingService` en un contenedor a mano, y nada conecta `GreetingProperties` en su constructor tampoco. Basta con `#[Service]`: el escaneo de componentes encuentra la clase, el contenedor la construye, y como el constructor pide un `GreetingProperties`, el contenedor resuelve e inyecta uno automáticamente. Esto es **inyección de dependencias por constructor**, y es el tema de todo el Capítulo 2.

---

## Paso 5 — Exponlo por HTTP

`app/Http/GreetingController.php` le pone un borde web al servicio:

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
 */
#[RestController]
final class GreetingController
{
    public function __construct(private readonly GreetingService $greetings) {}

    /** @return array<string, string> */
    #[GetMapping('/')]
    public function index(): array
    {
        return ['message' => $this->greetings->greet('World')];
    }

    /** @return array<string, string> */
    #[GetMapping('/greetings/{name}', name: 'greetings.show')]
    public function show(#[PathVariable] string $name): array
    {
        return ['message' => $this->greetings->greet($name)];
    }
}
```

`#[RestController]` es en sí mismo un estereotipo — está construido sobre la misma base `#[Component]` que `#[Service]` — así que `GreetingController` se autoconecta exactamente igual que `GreetingService`: el contenedor lo construye, ve que el constructor quiere un `GreetingService`, y le proporciona uno. `#[GetMapping]` y `#[PathVariable]` son lo que el *escaneo de rutas* lee, por separado, para construir el `RouteManifest` al que se refería el comentario de `routes/web.php`.

---

## Paso 6 — Ejecútalo

Arranca el servidor de desarrollo:

```bash
php artisan firefly:serve
```

`firefly:serve` es un envoltorio delgado: llama a `artisan serve` (o a `octane:start`, si `laravel/octane` está instalado) — no reimplementa nada por su cuenta. En otra terminal, prueba las dos rutas que acabas de leer:

```bash
curl -s localhost:8000/
```

```json
{"message":"Hello, World!"}
```

```bash
curl -s localhost:8000/greetings/Ada
```

```json
{"message":"Hello, Ada!"}
```

`"Hello"` es el valor por defecto de `$salutation` en `GreetingProperties` — nada en `config/greeting.php` lo sobrescribe todavía, así que lo que ves es el valor por defecto del constructor. Cambia ese valor por defecto, o vincula `greeting.salutation` en tu propia configuración, y cada respuesta lo reflejará, sin ningún cambio de código ni en el servicio ni en el controlador.

::: figure art/figures/request-lifecycle.svg | Figura 0.1 — Una petición atraviesa la cadena de filtros web y el despachador del controlador antes de que tu método manejador se ejecute.

!!! note "Dos comandos, un solo trabajo"
    Ya ejecutaste `firefly:cache` una vez, de forma indirecta, durante `composer create-project`. Si añades o cambias un atributo — un nuevo `#[Service]`, una nueva ruta — vuelve a ejecutar `php artisan firefly:cache` para recompilar los manifiestos, o `php artisan firefly:clear` para eliminarlos y volver a la ruta de escaneo de desarrollo (más lenta, basada en reflexión). El Capítulo 2 explica exactamente qué produce cada comando.

---

## Paso 7 — Un vistazo a hacia dónde va esto

`GreetingService` es deliberadamente la porción más pequeña posible: un bean, una dependencia, una ruta. La aplicación que este libro realmente construye, capítulo a capítulo, es más grande — **Lumen**, un servicio de monedero digital y libro mayor que ya existe, completamente construido y probado, en el propio paquete `samples/lumen` del framework. Expone:

| Método | Ruta | Propósito |
|---|---|---|
| POST | `/api/v1/wallets` | Abrir un monedero |
| POST | `/api/v1/wallets/{id}/deposit` | Depositar fondos |
| POST | `/api/v1/wallets/{id}/withdraw` | Retirar fondos — protegido |
| POST | `/api/v1/wallets/transfers` | Transferir fondos entre dos monederos |
| GET | `/api/v1/wallets/{id}/balance` | Consultar solo el saldo |
| GET | `/api/v1/wallets/{id}/ledger` | Consultar el libro mayor proyectado del monedero |

Cada uno de esos endpoints está construido a partir del puñado de ideas que acabas de conocer en miniatura — una clase con estereotipo, inyección por constructor, un atributo que el framework lee en el momento del escaneo — más muchas cosas más: un puerto de repositorio hexagonal y su adaptador Eloquent, manejadores de comando y consulta de CQRS, eventos de dominio proyectados en un libro mayor, y seguridad a nivel de método en el endpoint de retirada. Puedes ejecutar toda su batería de pruebas ahora mismo, antes de leer una página más, si quieres verla funcionar:

```bash
composer test -- samples/lumen/tests
```

El resto de este libro construye exactamente esa aplicación contigo, una idea cada vez, empezando por la idea que ya has usado dos veces sin una explicación completa: el contenedor.

---

## Lo que construiste {.recap}

Pasaste de una terminal vacía a una aplicación LaraFly compilada, en ejecución y consultable con curl, y conociste — sin necesitar todavía la explicación completa — un `#[Service]`, un DTO `#[ConfigProperties]`, un `#[RestController]`, la inyección de dependencias por constructor y el paso de compilación `firefly:cache` que convierte todo eso en un manifiesto de arranque sin reflexión.

| En este Inicio rápido... | Se profundiza en |
|---|---|
| Instalaste con `composer create-project firefly/skeleton` | **Capítulo 1** — ¿Por qué LaraFly? |
| Viste cómo `#[Service]`, `#[ConfigProperties]` y `#[RestController]` registran beans sin conexión manual | **Capítulo 2** — Inyección de Dependencias y Auto-Configuración |
| Ejecutaste `firefly:cache` y viste rutas servidas desde un manifiesto compilado, no desde `routes/web.php` | **Capítulo 2** — Inyección de Dependencias y Auto-Configuración |

Cuando estés listo para el *porqué* de todo esto, pasa la página al Capítulo 1.

---

## Ponlo en práctica {.exercises}

1. **Cambia el saludo por defecto.** Edita el valor por defecto del constructor de `GreetingProperties` de `'Hello'` a otra cosa, vuelve a ejecutar `php artisan firefly:cache`, reinicia `firefly:serve` y confirma que la respuesta cambia sin tocar ningún otro código.
2. **Añade una tercera ruta.** Dale a `GreetingController` un método `farewell(string $name)` mapeado a `#[GetMapping('/farewells/{name}')]` que devuelva `['message' => "Goodbye, {$name}!"]` directamente — todavía no necesitas ningún servicio nuevo.
3. **Lee el `README.md` del ejemplo Lumen** (`samples/lumen/README.md`) antes del Capítulo 1 — presenta, en prosa, todo lo que el resto de este libro construye en código.
