<span class="eyebrow">Apéndice A</span>

# Hoja de Referencia Laravel → LaraFly {.chtitle}

LaraFly es **aditivo**: superpone un modelo de aplicación con forma de Spring Boot encima de Laravel 13 — nunca bifurca, envuelve ni reemplaza el framework de debajo. Cada capacidad de LaraFly es un paquete Composer normal que registra proveedores de servicio de Laravel normales; una app LaraFly sigue siendo, en todo aspecto que un desarrollador de Laravel reconocería, una app Laravel. Este apéndice es un mapa de referencia rápida desde el idioma de Laravel que ya conoces hasta el concepto de LaraFly junto al que este libro te lo presentó, con el capítulo que lo cubre en profundidad.

## De un vistazo

| Aspecto | Laravel puro | LaraFly | Capítulo |
|---|---|---|---|
| Punto de entrada | Proveedores de servicio registrados en `bootstrap/providers.php`, cableados a mano | Los mismos proveedores, más clases `AutoConfiguration` autodescubiertas ensambladas por un pipeline de `BootPass` decidido por el kernel | 2 |
| Inyección de dependencias | `app()->bind()`/`app()->singleton()` en el `register()` de un proveedor | Estereotipos `#[Component]`/`#[Service]`/`#[Repository]`/`#[Configuration]` sobre la propia clase; un escaneo de componentes compilado resuelve las dependencias del constructor | 2 |
| Configuración | `config('mail.host')` (acceso a array, sin tipo) | DTOs `#[ConfigProperties]` enlazados desde un subárbol de configuración — tipados, de fallo rápido ante una clave faltante/no coincidente | 3 |
| Enrutamiento HTTP | Archivos de ruta `routes/web.php`/`routes/api.php` | `#[RestController]` + atributos de verbo (`#[GetMapping]`, …), compilados a un `RouteManifest`, aún despachados a través de rutas nativas de Laravel | 4 |
| Validación | `FormRequest::rules()` (reglas en array) | Interceptación de parámetro `#[Valid]` sobre un modelo de restricciones al estilo de Bean Validation, aún respaldado por el validador de Laravel | 4 |
| Persistencia | Modelos Eloquent + query builder directamente en el código de aplicación | El mismo Eloquent por debajo de un puerto/adaptador `CrudRepository` — el código de aplicación depende de una interfaz, nunca de Eloquent directamente | 5 |
| Modelado de dominio | Los modelos Eloquent llevan tanto datos como comportamiento | `Firefly\Domain\Entity`/agregados sin dependencia de framework; Eloquent vive solo en el adaptador | 6 |
| Transacciones | `DB::transaction(fn () => …)` (con ámbito de clausura) | `#[Transactional]` sobre una clase/método — reglas declarativas de propagación/aislamiento/rollback, un proxy generado dirige `beginTransaction`/`commit`/`rollBack` | 9 |
| Eventos | `Event::listen()`/`#[AsEventListener]`, solo en-proceso | Dos superficies distintas: el bus en-proceso (`#[AsEventListener]`) **y** un bus de EDA respaldado por broker (`#[EventListener]`) | 8 |
| CQRS | No es un concepto de primera clase — un "command" de Laravel normalmente significa un comando de consola de Artisan | `CommandBus`/`QueryBus` con un pipeline acotado (validar → autorizar → invocar → métricas) y `#[CommandHandler]`/`#[QueryHandler]` | 7 |
| Seguridad | Guards de auth + middleware de ruta (`->middleware('auth')`, `Gate::allows()`) | `SecurityContext` con forma de Spring Security 6 + DSL de URL `HttpSecurity` de denegar-por-defecto + seguridad de método (`#[PreAuthorize]`/`#[Secured]`/`#[RolesAllowed]`) | 10 |
| Operaciones | Nada integrado — las comprobaciones de salud y las métricas suelen ser a medida o un paquete | `firefly/actuator` (health/info/env/beans/conditions/mappings/loggers/scheduledtasks) + `firefly/observability` (métricas Prometheus/Micrometer-JSON) | 11 |
| Pruebas | `RefreshDatabase`, `Event::fake()`, `Bus::fake()` — solo ven las propias primitivas de Laravel | `FireflyTestCase`/`FireflyDatabaseTestCase`, dobles de grabación para los propios puertos de Firefly, expectativas de Pest con sabor a Firefly | 12 |
| Andamiaje | `php artisan make:controller`/`make:model` | `php artisan make:firefly-controller`/`make:firefly-handler`/… — un generador por estereotipo | 13 |
| Rendimiento de arranque | La propia caché de config/rutas de Laravel (`config:cache`, `route:cache`) | `php artisan firefly:cache` — un comando compila cada manifiesto de Firefly **y** genera proxies `#[Transactional]` para un arranque sin reflexión | 13 |

---

## Punto de entrada: proveedores de servicio frente a auto-configuración

Laravel arranca registrando los proveedores de servicio que `bootstrap/providers.php` lista, en el orden en que aparecen (o, para los proveedores descubiertos por paquete, aproximadamente en orden alfabético). El `firefly/context` de LaraFly se sienta encima de eso: el proveedor de cada paquete de capacidad todavía existe y todavía lo descubre Laravel exactamente de la misma manera — pero su método `register()` no hace nada excepto *acumular* sus contribuciones de `BootPass` en un colector `PendingBootPasses`. El `FireflyKernel` compartido drena ese buffer una vez que se resuelve realmente y ejecuta cada fase (escaneo, evaluación de condiciones, registro de beans, pasadas de cableado) en un orden decidido por el kernel — de modo que qué proveedor instanció Laravel primero es irrelevante.

## Inyección de dependencias: `app()->bind()` frente a estereotipos

El contenedor de Laravel es potente pero explícito — un enlace vive en un proveedor, separado de la clase que enlaza:

```php
final class GreetingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GreetingService::class, fn () => new GreetingService('Hello'));
    }
}
```

El `firefly/container` de LaraFly superpone atributos de PHP 8 sobre `Illuminate\Container` en su lugar — marca la propia clase con `#[Component]` (o `#[Service]`/`#[Repository]`/`#[Configuration]`, todas especializaciones de él) y un escaneo de componentes compilado la registra, resolviendo las dependencias del constructor por tipo:

```php
#[Service]
final readonly class GreetingService
{
    public function __construct(private string $greeting = 'Hello') {}
}
```

Nada te impide también usar `app()->bind()` directamente — los dos coexisten, y el Capítulo 2 te mostró exactamente cuándo cada uno es la mejor opción.

## Configuración: `config()` frente a `#[ConfigProperties]`

`config('mail.port')` es acceso a array sin tipo — una errata o una clave faltante devuelve `null` silenciosamente. `firefly/config` añade DTOs `#[ConfigProperties('mail')]` que enlazan todo un subárbol de configuración sobre una clase readonly sencilla de una vez, tipados y de fallo rápido ante una clave requerida faltante. Los archivos `config/*.php` de Laravel siguen siendo la única fuente de verdad; LaraFly los lee, no los reemplaza.

## Transacciones: `DB::transaction()` frente a `#[Transactional]`

`DB::transaction(fn () => …)` limita una transacción al ámbito de una clausura — las reglas de propagación y rollback son las que escribas en línea. `#[Transactional]` declara propagación (`REQUIRED`, `REQUIRES_NEW`, `NESTED`, …), aislamiento, solo-lectura, y listas de excepciones de rollback/no-rollback sobre la propia clase o método; un proxy generado en tiempo de escaneo (Capítulo 9) dirige la frontera a través de `beginTransaction()`/`commit()`/`rollBack()` manuales, de modo que una excepción capturada aún puede commitearse cuando coincide con `noRollbackFor`.

## Seguridad: middleware frente a `HttpSecurity` de denegar-por-defecto

La postura por defecto de Laravel es permisiva: una ruta es pública a menos que le adjuntes `->middleware('auth')` o una comprobación de habilidad. `firefly/security` invierte eso — el DSL de URL `HttpSecurity` construye una lista ordenada de reglas y deniega cualquier cosa sin coincidencia (401 anónimo, 403 autenticado), el mismo modelo de denegar-por-defecto que Spring Security. La seguridad de método (`#[PreAuthorize]`, `#[Secured]`, `#[RolesAllowed]`) se hace cumplir en el bus de CQRS y en el despachador de controladores mediante un evaluador de expresiones de lista blanca cerrada — sin ningún `eval()` en toda la cadena (Capítulo 10). Los propios guards de auth y middleware de Laravel siguen funcionando por debajo; `firefly/security` es una capa más estricta encima de ellos, no una bifurcación.

## Operaciones: actuator y observabilidad

Laravel no trae ningún endpoint de comprobación de salud ni de métricas de fábrica — la mayoría de los equipos o bien improvisan uno o recurren a un paquete. `firefly/actuator` (health/info/env/beans/conditions/mappings/loggers/scheduledtasks bajo `/actuator`, asegurado enteramente por configuración `HttpSecurity` ordinaria) y `firefly/observability` (una exposición `/metrics` de primera parte Prometheus-0.0.4 + Micrometer-JSON) son los análogos de Spring Boot Actuator y Micrometer, respectivamente — ambos paquetes Composer opcionales, ambos seguros por defecto (Capítulo 11).

## Andamiaje y rendimiento de arranque: `make:*`/`config:cache` frente a `make:firefly-*`/`firefly:cache`

Los propios generadores `make:controller`/`make:model` de Laravel y sus comandos de rendimiento de arranque `config:cache`/`route:cache` tienen análogos directos en LaraFly, extendidos para cubrir cada estereotipo de framework en lugar de solo los modelos Eloquent y las rutas: `make:firefly-*` (ocho generadores, uno por estereotipo) y `firefly:cache` (que compila *cada* manifiesto de Firefly — componentes, rutas, handlers de CQRS, listeners de eventos, tareas programadas, reglas de seguridad, metadatos transaccionales — más genera las clases proxy `#[Transactional]`, en un solo comando) — véase el Capítulo 13.

!!! laravel "Paridad con Laravel"
    Cada fila de esta página está pensada para leerse en una sola dirección: LaraFly nunca te pide renunciar a un idioma de Laravel, te pide recurrir además a uno más estructurado allí donde el código base se beneficia de ello. Un proyecto LaraFly puede mezclar libremente `app()->bind()` y `#[Service]`, una ruta `routes/api.php` sencilla y un `#[RestController]`, en el mismo código base — nada en las muestras de este libro requiere una migración de todo-o-nada.
