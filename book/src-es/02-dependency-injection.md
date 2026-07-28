<span class="eyebrow">Parte I — Fundamentos · Capítulo 2</span>

# Inyección de Dependencias y Auto-Configuración {.chtitle}

Al terminar este capítulo conocerás los cuatro atributos de estereotipo, cómo la inyección por constructor resuelve las dependencias, cómo desambiguar entre varias implementaciones de una interfaz con `#[Primary]` y `#[Qualifier]`, cómo controlar el orden de las listas con `#[Order]`, cómo inyectar configuración y expresiones con `#[Value]`, qué significan los tres ámbitos de componente y — atando todo junto — qué ocurre exactamente entre `php artisan firefly:cache` y que tu aplicación sirva su primera petición.

!!! note "Término nuevo: contenedor"
    Un **contenedor de inyección de dependencias** es un objeto que sabe cómo construir otros objetos, y te entrega una instancia ya construida en lugar de que tú llames a `new` y conectes sus dependencias a mano. El contenedor de LaraFly es `Illuminate\Container\Container` — el mismo contenedor que ya usa una aplicación Laravel corriente — con una incorporación: los atributos de PHP 8 le dicen *qué* construir, así que rara vez llamas tú mismo a `$container->bind(...)`.

---

## Estereotipos: declarar un bean

Un **bean** es cualquier objeto que el contenedor gestiona en tu nombre — construido una vez (por defecto) y entregado cuando se solicita. Marcas una clase como bean con un atributo de **estereotipo**. LaraFly distribuye cuatro:

```php
use Firefly\Container\Attributes\{Component, Configuration, Repository, Service};
```

`#[Component]` es el atributo base; `#[Service]`, `#[Repository]` y `#[Configuration]` son todos especializaciones de él — literalmente, cada uno `extends Firefly\Container\Attributes\Component` en PHP. Un escaneo de componentes encuentra cada uno de ellos con una única llamada de reflexión:

```php
$reflection->getAttributes(Component::class, ReflectionAttribute::IS_INSTANCEOF);
```

`ReflectionAttribute::IS_INSTANCEOF` es lo que hace que esto funcione: coincide con `#[Component]` en sí mismo *y* con cualquier atributo que extienda de él, así que el escáner nunca tiene que enumerar los estereotipos por nombre. Esta es también la razón por la que `#[RestController]` de `firefly/web` — el atributo que conociste en `GreetingController` en el Inicio rápido — participa en ese mismo escaneo: él también `extends Component`.

Ya conociste el estereotipo más sencillo en el Inicio rápido:

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

`#[Service]` es el estereotipo para lógica de aplicación/negocio — el equivalente en LaraFly del `@Service` de Spring. No hay nada más que escribir: ningún `$this->app->bind(GreetingService::class, ...)` en ningún sitio, ningún cierre fábrica. El atributo es todo el registro.

### `#[Repository]` sobre un puerto hexagonal

`#[Repository]` marca un bean orientado a la persistencia, y es donde los estereotipos empiezan a mostrar su valor real: desambiguar entre una interfaz y la clase que la implementa. `samples/lumen` define el *puerto* — la interfaz de la que dependen las capas de dominio y de aplicación — sin ningún atributo del framework encima, porque una interfaz nunca es un bean en sí misma:

```php
<?php

declare(strict_types=1);

namespace Lumen\Infrastructure;

use Lumen\Domain\Wallet;

/**
 * The hexagonal PORT for wallet persistence: the domain/application layer depends on this interface only, never on
 * Eloquent or any storage detail. `EloquentWalletRepository` is the sole adapter, auto-bound by the framework's
 * nominal interface auto-binding (Firefly\Container's ComponentScanner/ContainerRegistrar::wireInterfaces()).
 */
interface WalletRepository
{
    public function save(Wallet $wallet): Wallet;

    public function findById(string $id): ?Wallet;

    /** @return list<Wallet> */
    public function findByOwnerId(string $ownerId): array;
}
```

El *adaptador* — la clase que realmente habla con Eloquent — es el que lleva `#[Repository]`:

```php
<?php

declare(strict_types=1);

namespace Lumen\Infrastructure;

use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\EloquentRepository;
use InvalidArgumentException;
use Lumen\Domain\Wallet;

/**
 * The Eloquent ADAPTER for the `WalletRepository` port, bound to the `Wallet` aggregate model.
 *
 * (The real file's docblock explains, in full, a PHP parameter-variance constraint this class must respect
 * because it both `extends EloquentRepository` and `implements WalletRepository` — see the shipped source
 * under `samples/lumen/src/Infrastructure/EloquentWalletRepository.php` for the complete rationale.)
 *
 * @extends EloquentRepository<Wallet>
 */
#[Repository]
final class EloquentWalletRepository extends EloquentRepository implements WalletRepository
{
    protected string $model = Wallet::class;

    public function save(object $entity): Wallet
    {
        if (! $entity instanceof Wallet) {
            throw new InvalidArgumentException(sprintf('%s::save() only accepts a %s.', self::class, Wallet::class));
        }

        return parent::save($entity);
    }

    public function findById(mixed $id): ?Wallet
    {
        $found = parent::findById($id);

        return $found instanceof Wallet ? $found : null;
    }

    /** @return list<Wallet> */
    public function findByOwnerId(string $ownerId): array
    {
        /** @var list<Wallet> $result */
        $result = $this->dispatchQuery('findByOwnerId', [$ownerId]);

        return $result;
    }
}
```

Nada en `samples/lumen` vincula nunca `WalletRepository` a `EloquentWalletRepository` a mano. Cuando el constructor de un bean pide `WalletRepository`, el contenedor necesita saber a qué clase *concreta* entregarle — y esto es la **auto-conexión de interfaces**: `ContainerRegistrar::wireInterfaces()` observa las interfaces implementadas por cada componente escaneado y, para cada interfaz con exactamente un componente que la implemente (o exactamente uno marcado `#[Primary]`, que veremos más adelante en este capítulo), la vincula automáticamente.

!!! laravel "Paridad con Laravel"
    En una aplicación Laravel corriente escribirías tú mismo `$this->app->bind(WalletRepository::class, EloquentWalletRepository::class);`, normalmente en el método `register()` de un proveedor de servicios. `#[Repository]` más la auto-conexión de interfaces es exactamente esa vinculación, generada para ti a partir del hecho de que solo una clase escaneada implementa la interfaz.

### Métodos fábrica `#[Configuration]` y `#[Bean]`

No todo lo que necesitas inyectar es una clase de tu propiedad. `#[Configuration]` marca una clase como una **fuente de métodos fábrica `#[Bean]`** — el tipo de retorno de cada método se convierte en el tipo registrado del bean, y sus parámetros se resuelven e inyectan exactamente igual que los de un constructor. `#[Configuration]` es en sí mismo un `#[Component]`, así que la clase de configuración también es un bean gestionado.

El proyecto de andamiaje que generaste en el Inicio rápido ya distribuye uno, real y ya empaquetado:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Data\Transaction\TransactionalManifest;

/**
 * Category C: the app-side #[Configuration] that firefly:cache generates/ships. It LOADS the app's compiled
 * TransactionalManifest as a bean — the only override seam for it, because binding it directly on the Laravel
 * container is invisible to DataAutoConfiguration's #[ConditionalOnMissingBean] (which consults the
 * BeanDefinitionRegistry). Its default #[Order] 0 sorts strictly before DataAutoConfiguration's #[Order(1000)],
 * so the empty default steps aside. On a cached boot this #[Configuration] is discovered from the compiled
 * component/context manifests (never scanned); until firefly:cache has run, it returns an empty manifest.
 */
#[Configuration]
final class CachedTransactionalConfiguration
{
    #[Bean]
    public function transactionalManifest(): TransactionalManifest
    {
        $file = base_path('bootstrap/cache/firefly/transactional.php');

        return is_file($file) ? TransactionalManifest::load($file) : new TransactionalManifest([], []);
    }
}
```

`transactionalManifest()` devuelve `TransactionalManifest` — así que ese es el tipo bajo el que se registra el bean, y cualquier constructor que pida un `TransactionalManifest` recibe lo que este método devuelva. No necesitarás `#[Transactional]` en sí hasta un capítulo posterior, pero la *forma* — clase `#[Configuration]`, método `#[Bean]`, tipo de retorno como clave de registro — es una que volverás a ver cada vez que este libro presente un paquete nuevo que necesite entregarte un objeto ya construido en lugar de una clase que construyes directamente.

!!! laravel "Paridad con Laravel"
    `#[Configuration]` + `#[Bean]` es el equivalente directo de que el método `register()` de un proveedor de servicios Laravel llame a `$this->app->singleton(SomeType::class, fn () => ...)` — salvo que el *tipo* que devuelve el cierre se lee de la propia declaración de tipo de retorno del método, así que no hay nada que mantener sincronizado a mano.

---

## Inyección por constructor

!!! note "Término nuevo: inyección"
    **Inyección de dependencias** significa que un bean recibe sus colaboradores a través de su constructor en lugar de construirlos él mismo o obtenerlos de un global. El contenedor inspecciona el constructor de un bean, resuelve el tipo de cada parámetro y pasa la instancia construida (o ya construida).

Ya has visto esto dos veces sin una explicación completa. `OpenWalletHandler`, el manejador de comando que abre un nuevo monedero en `samples/lumen`, es el ejemplo más claro — todo su trabajo es recibir el *puerto*, no el adaptador:

```php
<?php

declare(strict_types=1);

namespace Lumen\Application\Command;

use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Data\Transaction\Attributes\Transactional;
use Lumen\Domain\Wallet;
use Lumen\Infrastructure\WalletRepository;

/**
 * Handles OpenWallet: mints a new wallet id, opens the aggregate, and persists it. The handled command type is
 * inferred from the sole handle() parameter (HandlerScanner param inference) — no explicit #[CommandHandler(...)].
 */
#[CommandHandler]
class OpenWalletHandler
{
    public function __construct(private readonly WalletRepository $wallets) {}

    #[Transactional]
    public function handle(OpenWallet $command): string
    {
        $id = 'wlt-'.bin2hex(random_bytes(8));
        $wallet = Wallet::open($id, $command->ownerId, $command->currency);
        $this->wallets->save($wallet);

        return $id;
    }
}
```

El constructor de `OpenWalletHandler` pide `WalletRepository` — la *interfaz*, no `EloquentWalletRepository`. El contenedor resuelve esa petición en tres pasos: busca `WalletRepository` en el manifiesto compilado, encuentra la interfaz auto-conectada a `EloquentWalletRepository` (la única implementación escaneada), y construye — o reutiliza — esa instancia. `OpenWalletHandler` nunca importa `EloquentWalletRepository`, nunca menciona Eloquent, y seguiría compilando sin cambios si `samples/lumen` sustituyera mañana el adaptador de almacenamiento por otro distinto. Ese es todo el sentido de programar contra un puerto: el propio código fuente del manejador es la prueba de que no sabe, y no necesita saber, qué hay al otro lado de `WalletRepository`.

!!! tip "Consejo: la inyección por constructor es la única que hay"
    LaraFly no admite inyección por propiedad ni por setter — toda dependencia que necesite un bean debe declararse como un parámetro de constructor tipado. Esto es deliberado: la firma del constructor de una clase es una lista completa y honesta de todo aquello de lo que depende, legible sin abrir el cuerpo de la clase.

::: figure art/figures/di-autoconfig.svg | Figura 2.1 — Del escaneo de componentes al manifiesto compilado, al paso de condiciones, al registro de beans, a los singletons eager.

---

## Ámbitos

Todo componente es un **singleton** por defecto: el contenedor lo construye una vez, y cada resolución posterior devuelve la misma instancia. Elige un ciclo de vida distinto con el argumento `scope` que acepta cualquier atributo de estereotipo:

```php
use Firefly\Container\Attributes\Service;
use Firefly\Container\Scope;

#[Service(scope: Scope::Transient)]  // a new instance every time it is resolved
final class RequestId {}
```

`Scope` es un enum de PHP corriente con tres casos:

```php
enum Scope
{
    case Singleton;  // one instance for the life of the application (the default)
    case Transient;  // a new instance every resolution
    case Scoped;     // one instance per Laravel request/scope
}
```

Usa `Scope::Transient` para cualquier cosa que nunca deba compartirse — un id de correlación por operación, un constructor mutable. Usa el `Scope::Singleton` por defecto para todo lo demás, que es casi todo: los servicios, los repositorios y los DTO de configuración son todos naturalmente compartibles, y un singleton es más barato de resolver.

!!! warning "Advertencia"
    `Scope::Scoped` vincula una instancia por petición de Laravel bajo el clásico PHP-FPM. Si despliegas bajo Octane, la vida de un bean con ámbito es de una *petición*, no de un *worker* — no lo uses como sustituto de `Scope::Singleton` solo porque suene más seguro; los dos tienen ciclos de vida genuinamente distintos.

---

## `#[Primary]` y `#[Qualifier]`: desambiguar implementaciones

La auto-conexión de interfaces, descrita antes en este capítulo, tiene una regla sencilla para el caso común: exactamente una implementación escaneada de una interfaz se vincula a ella automáticamente. Pero las aplicaciones reales a menudo tienen *más* de una implementación de la misma interfaz — un adaptador de producción y uno de prueba, o dos estrategias genuinamente distintas. `#[Primary]` y `#[Qualifier]` son la manera de decirle al contenedor cuál quieres:

```php
use Firefly\Container\Attributes\{Primary, Qualifier, Service};

interface Greeter
{
    public function greet(): string;
}

#[Service]
#[Primary]
final class EnglishGreeter implements Greeter
{
    public function greet(): string
    {
        return 'Hello';
    }
}

#[Service('spanish')]
#[Qualifier('spanish')]
final class SpanishGreeter implements Greeter
{
    public function greet(): string
    {
        return 'Hola';
    }
}
```

Con ambos beans registrados, el contenedor resuelve la interfaz de tres maneras distintas según lo que le pidas:

```php
$container->get(Greeter::class);      // EnglishGreeter — the #[Primary] one
$container->getByName('spanish');     // SpanishGreeter — resolved by its bean name
$container->getAll(Greeter::class);   // [EnglishGreeter, SpanishGreeter] — every implementation
```

`#[Primary]` desempata cuando un constructor pide la interfaz a secas: el contenedor vincula `Greeter::class` a la implementación que lleve `#[Primary]`, exactamente igual que `wireInterfaces()` vinculó antes `WalletRepository` a `EloquentWalletRepository` — salvo que ahora la elección es explícita en lugar de ser la única opción disponible. `#[Qualifier('spanish')]` le da a `SpanishGreeter` un *nombre* que puedes pedir directamente, independiente de la resolución por tipo, cuando específicamente necesitas la que no es la principal.

!!! warning "Advertencia"
    Con cero, o con más de una implementación `#[Primary]` de la misma interfaz, el contenedor no tiene un valor por defecto bien definido — resolver la interfaz a secas se vuelve ambigua. Dale a toda interfaz con varias implementaciones exactamente un `#[Primary]`, o resuelve cada implementación por su nombre `#[Qualifier]`.

### `#[Order]`

`getAll(Greeter::class)`, arriba, devuelve todas las implementaciones — y las devuelve **ordenadas por `#[Order]`**, la más baja primero, exactamente como la convención `@Order` de Spring:

```php
use Firefly\Container\Attributes\Order;

#[Service]
#[Order(10)]
final class EnglishGreeter implements Greeter { /* ... */ }

#[Service]
#[Order(20)]
final class SpanishGreeter implements Greeter { /* ... */ }
```

El orden por defecto es `0` cuando se omite el atributo, así que cualquier cosa que ordenes explícitamente con un número positivo se ordena después de cualquier bean sin ordenar, y cualquier cosa que ordenes con un número negativo se ordena antes que ellos. `#[Order]` también se aplica a métodos fábrica `#[Bean]`, no solo a clases — el atributo funciona de forma idéntica allí donde una lista de beans necesite una secuencia determinista.

---

## Inyección con `#[Value]`

A veces lo que necesitas inyectar no es un bean en absoluto, sino un único escalar — un valor de configuración o una pequeña expresión calculada. `#[Value]` apunta directamente a un parámetro de constructor:

```php
use Firefly\Container\Attributes\Value;

final class MailerConfig
{
    public function __construct(
        #[Value('${MAIL_HOST:localhost}')] public readonly string $host,
        #[Value('#{25 * 2}')] public readonly int $port,
    ) {}
}
```

Se admiten dos formas de expresión. `${NAME:default}` lee la variable de entorno `NAME`, recurriendo a `default` cuando no está definida; `#{expr}` evalúa un pequeño lenguaje de expresiones en un entorno aislado (sandbox) — `#{25 * 2}` se resuelve como el entero `50`. (`firefly/config`, que se cubre en un capítulo posterior, extiende esa misma resolución para leer configuración de la aplicación, no solo el entorno.)

!!! note "Nota"
    `#[Value]` apunta exactamente a un parámetro de constructor — no es un estereotipo de clase, y una clase que solo tenga parámetros anotados con `#[Value]` sigue necesitando un atributo de estereotipo como `#[Service]` si quieres que el contenedor la gestione como un bean por derecho propio.

---

## Del escaneo de componentes al arranque sin reflexión

Cada estereotipo, cada `#[Bean]`, cada `#[Primary]`/`#[Qualifier]`/`#[Order]` que acabas de leer es *metadato de reflexión* — inerte hasta que algo lo lee. Ese algo es `ComponentScanner`, y se ejecuta **exactamente una vez**, cuando invocas `firefly:cache`, nunca más en ninguna petición que sirva la aplicación en ejecución.

`ComponentScanner::scan()` recorre cada raíz PSR-4 que tu `config/firefly.php` nombra bajo `scan.paths`, refleja cada clase que encuentra y — para cada clase que lleve un atributo instancia-de `#[Component]` — registra su estereotipo, su ámbito, si es `#[Primary]`, su `#[Order]`, su nombre `#[Qualifier]`, las interfaces que implementa y (para una clase `#[Configuration]`) la lista de sus métodos `#[Bean]`. El resultado es un array plano de `ComponentDescriptor` — sin objetos, sin cierres, nada que no se pueda serializar.

`ManifestCompiler` toma ese array y lo escribe a disco sin nada más exótico que el propio `var_export()` de PHP:

```php
"<?php\n\ndeclare(strict_types=1);\n\n// Generated by firefly/container. Do not edit.\n\nreturn "
    .var_export($rows, true)
    .";\n";
```

El resultado — `bootstrap/cache/firefly/component.php` en tu proyecto — es un archivo PHP corriente, `require`-able: un array literal, sin reflexión, sin análisis de atributos y sin necesidad de recorrer el sistema de archivos para volver a leerlo. `ContainerRegistrar::register()` carga ese archivo y hace la conexión real del contenedor que describe este capítulo — vinculando cada componente bajo su propia clase, conectando interfaces (incluyendo la regla `#[Primary]`/única-implementación) y registrando la salida de cada fábrica `#[Bean]` bajo su tipo de retorno.

`php artisan firefly:cache` es lo que impulsa toda esta tubería para tu aplicación:

```bash
php artisan firefly:cache
```

```
firefly:cache — wrote 8 manifest(s) + 0 proxy(ies) to /path/to/my-app/bootstrap/cache/firefly
```

Ya ejecutaste esto una vez, de forma indirecta — `composer create-project firefly/skeleton` lo llama por ti en su script `post-create-project-cmd`, que es la razón por la que la aplicación del Inicio rápido arrancó correctamente sin que ejecutaras el comando a mano ni una sola vez. De aquí en adelante, cada vez que añadas o cambies un `#[Service]`, `#[Repository]`, `#[Configuration]` o cualquier otro atributo de Firefly, vuelve a ejecutar `firefly:cache` para recompilar el manifiesto; `php artisan firefly:clear` elimina la caché compilada y vuelve a la ruta de escaneo de desarrollo (más lenta, basada en reflexión).

!!! tip "Consejo: `firefly:cache` no es solo para contenedores"
    El mismo comando también compila los manifiestos de todos los demás pilares que presentó el Capítulo 1 — rutas, proxies transaccionales, manejadores de CQRS, escuchadores de eventos, reglas de seguridad y más. La inyección de dependencias es simplemente el primero y más fundamental de ellos; la misma idea de "reflexionar una vez, congelar en un manifiesto, arrancar desde la copia congelada" se repite para cada uno.

---

## Lo que aprendiste {.recap}

| Concepto | Qué hace |
|---|---|
| `#[Component]` / `#[Service]` / `#[Repository]` / `#[Configuration]` | Marcan una clase como bean gestionado; los tres últimos especializan el atributo base |
| Inyección por constructor | El contenedor resuelve y pasa automáticamente cada parámetro de constructor tipado |
| `Scope::Singleton` / `Transient` / `Scoped` | Una instancia compartida (por defecto), una instancia nueva por resolución, o una por petición |
| `#[Primary]` | Desempata cuando varios beans implementan la misma interfaz |
| `#[Qualifier]` | Nombra un bean concreto para su búsqueda, independiente de la resolución por tipo |
| `#[Order]` | Ordena una lista de `getAll()` de implementaciones, la más baja primero |
| `#[Bean]` en un método `#[Configuration]` | Registra el valor de retorno del método bajo su tipo de retorno |
| `#[Value]` | Inyecta un valor de entorno resuelto o una expresión en un parámetro de constructor |
| `firefly:cache` | Ejecuta el escaneo de componentes una vez y lo compila en un manifiesto sin reflexión |

---

## Ponlo en práctica {.exercises}

1. **Añade una segunda implementación.** Dale a `Lumen\Infrastructure\WalletRepository` una segunda implementación en memoria propia (en un proyecto de prueba — no modifiques el paquete `samples/lumen` distribuido) y márcala `#[Repository]` sin `#[Primary]`. Ejecuta `firefly:cache` y observa qué le ocurre a la auto-conexión de interfaces ahora que existen dos implementaciones.
2. **Traza un constructor.** Abre de nuevo `OpenWalletHandler` y, sin consultar nada, anota cada bean que el contenedor debe resolver — directa o transitivamente — para construir una instancia de él.
3. **Lee el manifiesto compilado.** Después de ejecutar `php artisan firefly:cache` en una aplicación de andamiaje, abre `bootstrap/cache/firefly/component.php` y busca la entrada de `App\GreetingService`. Relaciona cada clave del array con el campo de `ComponentDescriptor` que describe este capítulo.
