<span class="eyebrow">Parte III — Coordinar y Asegurar la Aplicación · Capítulo 9</span>

# Transacciones y el Proxy `#[Transactional]` {.chtitle}

Al terminar este capítulo sabrás exactamente qué hace `#[Transactional]` — sus siete modos de propagación, sus ajustes de aislamiento/solo-lectura/rollback, y el proxy generado que le da dientes — cómo la auto-invocación esquiva ese proxy (y qué hacer en su lugar), cómo `firefly:cache` y el escaneo en proceso dan juntos a toda aplicación un proxy funcional sin cableado propio, y el hecho más consecuente de todo este libro hasta ahora: **un evento de dominio se publica únicamente porque `TransactionTemplate` — la maquinaria detrás de `#[Transactional]`— es el único que llama al despacho post-confirmación.** Sin `#[Transactional]`, no hay publicación, sin importar con cuánta corrección un agregado haya lanzado su evento.

!!! note "Término nuevo: demarcación declarativa de transacciones"
    En vez de escribir `DB::beginTransaction()` / `DB::commit()` / `DB::rollBack()` a mano dentro del cuerpo de un método, *declaras* la frontera con un atributo y dejas que un proxy generado la haga cumplir. Este es el modelo `@Transactional` de Spring, y es por lo que los Capítulos 6 y 7 ya pudieron mostrarte `#[Transactional]` sobre `OpenWalletHandler`, `DepositHandler`, `WithdrawHandler` y `TransferHandler` sin una sola llamada explícita a `DB::` dentro de ninguno de sus cuerpos `handle()`.

---

## El atributo `#[Transactional]`

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Transactional
{
    /**
     * @param  list<class-string<Throwable>>  $rollbackFor
     * @param  list<class-string<Throwable>>  $noRollbackFor
     */
    public function __construct(
        public Propagation $propagation = Propagation::REQUIRED,
        public Isolation $isolation = Isolation::DEFAULT,
        public bool $readOnly = false,
        public array $rollbackFor = [Throwable::class],
        public array $noRollbackFor = [],
        public ?string $connection = null,
        public ?int $timeout = null,
    ) {}
}
```

Sobre una **clase**, fija el valor por defecto para cada método público. Sobre un **método**, *reemplaza* — nunca se fusiona con — el atributo de nivel de clase para ese único método (la misma semántica de Spring). `packages/data/tests/Fixtures/Capstone/AccountService.php` es código de prueba real y distribuido que pone en juego ambas formas a la vez:

```php
<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Capstone;

use Firefly\Container\Attributes\Service;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Data\Transaction\Propagation;
use Firefly\Data\Transaction\TransactionalDescriptor;
use Firefly\Data\Transaction\TransactionTemplate;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Service]
#[Transactional]
class AccountService
{
    public function __construct(private readonly TransactionTemplate $template) {}

    public function transferAndCommit(): void
    {
        DB::table('accounts')->insert(['name' => 'a']);
        DB::table('accounts')->insert(['name' => 'b']);
    }

    public function transferAndFail(): void
    {
        DB::table('accounts')->insert(['name' => 'a']);
        DB::table('accounts')->insert(['name' => 'b']);

        throw new RuntimeException('boom');
    }

    #[Transactional(noRollbackFor: [IgnorableException::class])]
    public function logButKeep(): void
    {
        DB::table('accounts')->insert(['name' => 'kept']);

        throw new IgnorableException('ignored');
    }

    public function outerWithNested(): void
    {
        DB::table('accounts')->insert(['name' => 'outer']);

        try {
            $this->template->execute(function (): void {
                DB::table('accounts')->insert(['name' => 'inner']);

                throw new RuntimeException('inner fail');
            }, new TransactionalDescriptor(propagation: Propagation::NESTED));
        } catch (RuntimeException) {
        }
    }
}
```

Cada método público aquí hereda el valor por defecto de `#[Transactional]` a nivel de clase (`REQUIRED`, rollback ante cualquier `Throwable`) **excepto** `logButKeep()`, cuyo propio atributo de nivel de método *reemplaza por completo* ese valor por defecto para este único método — no apila `noRollbackFor` encima del valor por defecto de la clase, *es* la configuración efectiva. Por defecto `rollbackFor = [Throwable::class]`: PHP no tiene una separación entre excepciones comprobadas y no comprobadas, así que por defecto *cualquier* throwable revierte la transacción, a menos que también coincida con `noRollbackFor`, que siempre gana.

---

## Los siete modos de propagación

`Propagation` es un enum sin respaldo con todos los modos de Spring, incluyendo `NESTED` — posible sobre una conexión relacional corriente gracias a los propios savepoints automáticos de Laravel:

```php
enum Propagation
{
    case REQUIRED;
    case REQUIRES_NEW;
    case NESTED;
    case SUPPORTS;
    case NOT_SUPPORTED;
    case MANDATORY;
    case NEVER;
}
```

`TransactionTemplate::execute()` es la única fuente de verdad por la que pasan tanto el proxy generado como cualquier llamador programático directo — no hay un segundo camino de código que mantener sincronizado:

```php
final class TransactionTemplate
{
    public function execute(Closure $work, ?TransactionalDescriptor $descriptor = null): mixed
    {
        $d = $descriptor ?? new TransactionalDescriptor;
        $connection = DB::connection($d->connection);
        $active = $connection->transactionLevel() > 0;

        return match ($d->propagation) {
            Propagation::MANDATORY => $active ? $work() : throw new TransactionRequiredException,
            Propagation::NEVER => $active ? throw new TransactionNotAllowedException : $work(),
            Propagation::SUPPORTS, Propagation::NOT_SUPPORTED => $work(),
            Propagation::REQUIRED => $active ? $work() : $this->runInTransaction($connection, $work, $d, true),
            Propagation::REQUIRES_NEW, Propagation::NESTED => $this->runInTransaction($connection, $work, $d, ! $active),
        };
    }
}
```

| Modo | Comportamiento |
|---|---|
| `REQUIRED` *(por defecto)* | Se une a la transacción del llamador si hay una activa; si no, inicia una nueva transacción más externa. |
| `REQUIRES_NEW` | Siempre corre dentro de una transacción. Sin ninguna activa se convierte en la nueva transacción más externa; con una **ya activa en la misma conexión**, Laravel no tiene primitiva de suspensión, así que degrada a un `beginTransaction()` anidado — un savepoint, no una transacción verdaderamente independiente. |
| `NESTED` | La misma mecánica que `REQUIRES_NEW` en esta implementación: una transacción más externa nueva si ninguna está activa, o si no, un savepoint — así que un fallo en `NESTED` se deshace solo hasta su propio savepoint, nunca toda la unidad de trabajo. |
| `SUPPORTS` | Corre dentro de la transacción del llamador si hay una activa; si no, sin transacción alguna. Nunca inicia una. |
| `NOT_SUPPORTED` | Siempre corre sin transacción — pero en la *misma* conexión no hay primitiva de suspensión, así que una transacción ya activa simplemente no se pausa; el trabajo igual corre dentro de ella. |
| `MANDATORY` | Requiere una transacción activa; corre dentro de ella, o lanza `TransactionRequiredException`. |
| `NEVER` | Prohíbe una transacción activa; lanza `TransactionNotAllowedException` si hay una activa, o si no, corre sin ninguna. |

`outerWithNested()` de arriba es `NESTED` en acción: el método externo inserta `'outer'` bajo el valor por defecto `REQUIRED` de nivel de clase, después llama a `$this->template->execute(...)` directamente con `propagation: Propagation::NESTED`, inserta `'inner'`, y lanza. Una prueba capstone real y distribuida demuestra exactamente qué se deshace y qué sobrevive:

```php
it('unwinds a NESTED inner rollback to a savepoint, leaving the outer row intact', function () {
    accountService($this->app())->outerWithNested();

    expect(DB::table('accounts')->pluck('name')->all())->toBe(['outer']);
});
```

La fila `'inner'` desaparece con el savepoint; `'outer'` — insertada antes siquiera de que empezara la unidad de trabajo anidada — sobrevive y luego confirma normalmente cuando el método externo retorna.

---

## Aislamiento, solo-lectura, y la decisión de rollback

`Isolation` es un enum respaldado por cadenas cuyo valor **es** la cláusula SQL:

```php
enum Isolation: string
{
    case DEFAULT = 'DEFAULT';
    case READ_UNCOMMITTED = 'READ UNCOMMITTED';
    case READ_COMMITTED = 'READ COMMITTED';
    case REPEATABLE_READ = 'REPEATABLE READ';
    case SERIALIZABLE = 'SERIALIZABLE';
}
```

En la transacción más externa de una unidad de trabajo, un aislamiento distinto de `DEFAULT` emite `SET TRANSACTION ISOLATION LEVEL {value}`, y `readOnly: true` emite `SET TRANSACTION READ ONLY` — ambos **de mejor esfuerzo**: si cualquiera de las dos sentencias falla (un driver que la ignora, como SQLite) se captura y se ignora en silencio en vez de hacer fallar toda la unidad de trabajo.

```php
final class TransactionTemplate
{
    private function shouldRollBack(Throwable $e, TransactionalDescriptor $d): bool
    {
        foreach ($d->noRollbackFor as $type) {
            if ($e instanceof $type) {
                return false; // noRollbackFor wins: commit-and-rethrow
            }
        }

        foreach ($d->rollbackFor as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }

        return false; // not listed in rollbackFor: commit-and-rethrow
    }
}
```

Léelo en orden: una excepción que coincide con `noRollbackFor` siempre **confirma** (se comprueba primero, así que gana incluso sobre una coincidencia en `rollbackFor`); si no, una coincidencia en `rollbackFor` (por defecto, todo) **revierte**; si no — solo alcanzable con un `rollbackFor` deliberadamente reducido — **confirma**. `AccountService::logButKeep()` ejercita exactamente la primera rama, y una prueba capstone real demuestra que la fila sobrevive a la excepción con la que se lanza:

```php
it('commits despite a method-level noRollbackFor exception (override beats class-level)', function () {
    try {
        accountService($this->app())->logButKeep();
    } catch (IgnorableException) {
    }

    // Class-level default would roll back any Throwable; the method-level override keeps the row.
    expect(DB::table('accounts')->where('name', 'kept')->count())->toBe(1);
});
```

Nota que `TransactionTemplate` usa `beginTransaction()`/`commit()`/`rollBack()` **manuales** en todo momento, nunca el propio `DB::transaction($closure)` de Laravel — solo el control manual permite que una excepción capturada sea *confirmada*-y-relanzada cuando `noRollbackFor` lo indica, en lugar del propio helper de Laravel, que siempre revierte ante cualquier excepción sin ningún punto de anulación de ese tipo.

---

## El modelo de proxy

Un bean `#[Transactional]` nunca se llama directamente. `TransactionalBeanPostProcessor` — un `BeanPostProcessor` descubierto exactamente como cualquier otro bean, instalado en la fase 700 — lo intercambia, en su segunda pasada (después de que `#[PostConstruct]` ya haya corrido sobre el bean real), por una instancia de una `final class {Target}__FireflyTransactionalProxy extends {Target}` generada. `ProxyClassGenerator` emite esa clase, sobrescribiendo cada método transaccional con:

```php
final class TransferService__FireflyTransactionalProxy extends TransferService
{
    private \Firefly\Data\Proxy\MethodInterceptor $__fireflyTxInterceptor;

    public function transfer(int $amount): int
    {
        return (new \Firefly\Data\Proxy\MethodInvocation(
            $this,
            TransferService::class,
            'transfer',
            [$amount],
            [$this->__fireflyTxInterceptor],
            [\Firefly\Data\Transaction\TransactionalDescriptor::class => self::__fireflyTxDescriptor('transfer')],
            fn (array $__fireflyArgs) => parent::transfer(...$__fireflyArgs),
        ))->proceed();
    }
}
```

— entregando la llamada a `MethodInvocation::proceed()`, que recorre los interceptores que el plan compilado nombró para ese método y termina en la closure terminal que llama a `parent::`. Para un bean cuyo único advice es `#[Transactional]` esa lista tiene un solo eslabón: `TransactionInterceptor::invoke()` lee el `TransactionalDescriptor` horneado desde la invocación y le pasa `fn () => $invocation->proceed()` a su inalterado `run()`, que delega directamente en `TransactionTemplate::execute()`. Una prueba capstone real confirma que el intercambio realmente ocurrió — la clase del bean resuelto **no** es en absoluto la clase de servicio plana:

```php
it('proxies the #[Service] and rolls back BOTH inserts when the method throws', function () {
    $service = accountService($this->app());

    expect($service::class)->not->toBe(AccountService::class); // it is the generated proxy subclass
    expect($service)->toBeInstanceOf(AccountService::class);

    try {
        $service->transferAndFail();
    } catch (RuntimeException) {
    }

    expect(DB::table('accounts')->count())->toBe(0);
});
```

`$service::class` es el `AccountService__FireflyTransactionalProxy` generado, y sin embargo `$service instanceof AccountService` sigue siendo verdadero — el proxy *es-un* `{Target}`, así que cada llamada del contenedor y `#[PreDestroy]` se resuelven contra él exactamente como lo harían contra el bean original. `ProxyFactory` lo instancia **preservando el estado**: `newInstanceWithoutConstructor()` (así `#[PostConstruct]` no se vuelve a ejecutar), y luego el estado ya inicializado del bean real se copia sobre el proxy ranura a ranura, cada ranura escrita por una closure vinculada a la clase que la *declara* — nunca `ReflectionProperty::setValue()`. Escribir desde la clase declarante no es un detalle: una closure vinculada solo a la clase declarada únicamente ve los privados de esa clase, así que escribir cada ranura desde donde fue declarada es lo que permite que la copia alcance un `private` de un padre (el traductor de `EloquentRepository`, bajo cada `#[Repository]`) e inicialice un `protected readonly` de un padre (el manifiesto y el tracker de `EloquentRepository`) en PHP 8.3 — el mínimo que el framework soporta — donde una propiedad `readonly` solo es inicializable desde el ámbito de la clase que la declara. El cast `(array)` es lo que hace exactas las ranuras: mangla un privado como `"\0Owner\0name"`, así que un privado que un padre y un hijo declaran bajo un mismo nombre sigue siendo dos ranuras, y una propiedad tipada que el bean nunca inicializó sencillamente no está y se queda sin inicializar en el proxy.

!!! warning "La auto-invocación esquiva el proxy"
    Un método que llama a `$this->otroMetodo()` desde *dentro* de la clase proxificada llama directamente a través de `parent::`, saltándose `__fireflyTxInterceptor` por completo — la misma limitación bien conocida de Spring. Esto es exactamente por qué `AccountService::outerWithNested()` de arriba no simplemente llama a algún hipotético método `$this->innerNested()` — en su lugar pasa por el **`TransactionTemplate` inyectado**, que es la vía de escape correcta para obtener semántica transaccional en una unidad de trabajo interna desde dentro de otro método de la misma instancia.

---

## Un proxy, muchos advices

Ese proxy ya no va solo de transacciones. Cualquier paquete puede aportar un tipo de advice publicando un `#[Component]` que implemente `AdviceSource` — `advice()` nombra su bean interceptor, su clase descriptora y un **orden**; `scan()` devuelve las filas que reclama; `render()` convierte una fila de vuelta en el literal PHP que la clase generada hornea dentro. `ProxyPlanner` fusiona las filas de cada fuente en un único `ProxyPlan`, aplicando las fuentes en `Advice::order`, así que la lista de advices de cada método queda de fuera hacia dentro por construcción. La clase generada conserva el nombre que siempre ha tenido, `{Target}__FireflyTransactionalProxy`, pero ahora declara una propiedad interceptora privada y una fábrica estática de descriptores horneada **por cada tipo de advice** que la clase usa, y `MethodInvocation::proceed()` recorre esa lista antes de llegar a la closure terminal `fn (array $__fireflyArgs) => parent::m(...$__fireflyArgs)`. Un `Advice` de orden menor se ejecuta *más afuera*: el advice de `MethodSecurityAdviceSource` es `100` y el transaccional es `1000`, así que un rechazo se lanza antes de que llegue a abrirse una transacción. Un advice cuyo bean interceptor no está presente hace fallar el arranque con una `ConfigurationException` salvo que haya declarado `inertWhenUnbound` — el de seguridad lo hace, porque «las anotaciones son inertes hasta que `firefly.security.enabled` esté activo» es su estado documentado — y entonces `InterceptorRegistry` le entrega al proxy un `PassThroughInterceptor` en su lugar.

::: figure art/figures/method-interceptor-chain.svg | Figura 9.1 — Cada AdviceSource aporta filas a un único ProxyPlan compilado; una llamada ejecuta entonces la seguridad de método en el orden de advice 100 antes que la transacción en 1000.

---

## Cómo una aplicación real consigue de verdad un proxy funcional

El Capítulo 2 te enseñó la forma de una clase `#[Configuration]` y sus métodos fábrica `#[Bean]`. Durante una versión de este framework, toda aplicación LaraFly tuvo que escribir una de ellas a mano — un `#[Configuration]` cuyo único `#[Bean]` cargaba el `TransactionalManifest` cacheado — o `#[Transactional]` no hacía absolutamente nada. **Ya no necesitas esa clase**, y merece una página: el fallo que aquella clase sorteaba fue de los más afilados que ha tenido este framework, y su arreglo es una pequeña lección sobre cómo se resuelve cada artefacto compilado.

`php artisan firefly:cache` emite **tres** artefactos separados que lee la maquinaria de proxies. Ejecuta `TransactionalScanner`/`TransactionalManifestCompiler` — uno de los doce pares scanner/compiler — para escribir los datos del manifiesto en `bootstrap/cache/firefly/transactional.php`. Ejecuta `ProxyPlanCompiler` sobre el `ProxyPlan` fusionado para escribir `bootstrap/cache/firefly/proxy-plan.php` — el artefacto que decide qué beans reciben siquiera un proxy y qué advice ejecuta cada uno de sus métodos. Y ejecuta `ProxyClassGenerator` para emitir un archivo fuente `{Target}__FireflyTransactionalProxy` **por cada clase que el plan nombra** — cada clase que reclama algún `AdviceSource`, así que un bean que solo lleva reglas de seguridad de método también recibe uno — en un directorio `proxies/`, más un classmap `proxies.php`.

Durante mucho tiempo, nada cargaba el primero de esos tres. `DataAutoConfiguration` vinculaba un `TransactionalManifest` *vacío* incondicionalmente, el `transactional.php` compilado solo lo referenciaba su propia declaración, y por eso `hasProxyFor()` era siempre falso y `TransactionalBeanPostProcessor` devolvía cada bean sin envolver. `#[Transactional]` era un **no-op silencioso** en cualquier aplicación que no escribiera a mano su propia configuración de manifiesto — que es exactamente lo que aquel `#[Configuration]` manuscrito existía para hacer, y exactamente el modo de fallo del que avisaba la sección anterior, llegando desde el propio lado del framework.

Está arreglado en el origen. Los dos beans resuelven ahora su artefacto como se resuelve en este framework todo artefacto de Categoría B — **archivo compilado primero, escaneo en proceso después, vacío al final**:

```php
final class DataAutoConfiguration
{
    #[Bean]
    #[ConditionalOnMissingBean(TransactionalManifest::class)]
    public function transactionalManifest(Container $container): TransactionalManifest
    {
        if (($file = AppScan::cachedFile($container, AppScan::TRANSACTIONAL)) !== null) {
            ProxyMaterializer::classmap($container);

            return TransactionalManifest::load($file);
        }

        $paths = AppScan::paths($container);
        if ($paths === []) {
            return new TransactionalManifest([], []);
        }

        return (new TransactionalScanner)->scan($paths);
    }
    // …
}
```

Tres cosas de ese método merecen leerse despacio. El archivo compilado gana cuando existe, que es la ruta de producción cacheada. Cuando no existe, el escáner corre en proceso sobre `firefly.scan.paths`, que es la ruta de desarrollo — no hace falta `firefly:cache` para tener un proxy funcional mientras escribes código. Y `ProxyMaterializer::classmap()` se llama **antes** de devolver el manifiesto, así que las clases proxy generadas son cargables antes de que nada pueda pedir una; `TransactionalBeanPostProcessor` lanza una `ConfigurationException` ante un manifiesto que promete una clase proxy que no encuentra, de modo que una caché a medio escribir falla ruidosamente al arrancar en lugar de correr sin proxy en silencio.

`proxyPlan()` se sienta a su lado con la misma forma y un peldaño extra: `proxy-plan.php` primero, luego — para una caché escrita por un `firefly:cache` anterior a la existencia de los planes de proxy — un plan solo-transaccional puenteado desde el manifiesto, luego el escaneo en proceso a través de cada bean `AdviceSource`, y por último, sin ninguna ruta de escaneo, un plan solo-transaccional a partir del `TransactionalManifest` que esté vinculado. Ese último peldaño es lo que permite que los propios fixtures capstone del framework compilen un manifiesto a mano y aun así envuelvan sus beans `#[Transactional]`.

Así que el skeleton **no distribuye** ningún `CachedTransactionalConfiguration`, y tu aplicación tampoco debería. Una aplicación LaraFly obtiene un proxy `#[Transactional]` funcional en desarrollo desde el escaneo y en producción desde la caché, sin cableado propio. Las suites de pruebas del framework sí suministran un `#[Configuration]` equivalente en línea — `packages/data/tests/Fixtures/Capstone/CapstoneTransactionalConfiguration` para el fixture `AccountService` de arriba, y `samples/lumen/tests/Support/LumenTransactionalConfiguration` para los manejadores que este libro toma de `samples/lumen` — porque esas suites no arrancan a través de una aplicación en absoluto y no tienen ningún `firefly.scan.paths` que escanear.

!!! tip "Si escribiste una de estas clases, bórrala"
    Un `TransactionalManifest` vinculado a mano sigue funcionando — `#[ConditionalOnMissingBean]` hace que el framework se aparte ante cualquier definición de bean competidora — pero ahora ata tu aplicación a lo que haga esa clase, y nunca fue más que un apaño.

---

## La lección clave: los eventos de dominio se publican solo a través de una frontera `#[Transactional]`

Todo en esta sección ha estado construyendo hacia un hecho, y es lo más importante que enseña este capítulo. Mira de nuevo dónde se llama en realidad a `DomainEventDispatcher::dispatchAfterCommit()` desde dentro de `TransactionTemplate`:

```php
final class TransactionTemplate
{
    private function runInTransaction(Connection $connection, Closure $work, TransactionalDescriptor $d, bool $outermost): mixed
    {
        if ($outermost) {
            $this->applySessionSettings($connection, $d);
        }

        $connection->beginTransaction();

        try {
            $result = $work();
        } catch (Throwable $e) {
            if ($outermost) {
                $this->dispatcher?->dispatchAfterCommit($d->connection);
            }

            if ($this->shouldRollBack($e, $d)) {
                $connection->rollBack();
            } else {
                $connection->commit();
            }

            throw $e;
        }

        if ($outermost) {
            $this->dispatcher?->dispatchAfterCommit($d->connection);
        }

        $connection->commit();

        return $result;
    }
}
```

`dispatchAfterCommit()` se llama desde exactamente **dos** lugares, y ambos están dentro de `TransactionTemplate` — no hay un tercer punto de llamada en ningún lugar del framework. Y `dispatchAfterCommit()` en sí solo tiene algo que despachar por un segundo hecho, igual de estructural: `EloquentRepository::save()` (Capítulo 5) solo registra una entidad en `AggregateTracker` cuando `$connection->transactionLevel() > 0` — y el *único* camino de código que alguna vez hace eso cierto es la propia llamada a `beginTransaction()` de `TransactionTemplate::runInTransaction()`, unas líneas más arriba.

Encadena esos dos hechos y la conclusión es ineludible: **un método sin `#[Transactional]` nunca eleva su nivel de transacción, así que `save()` nunca rastrea el agregado, así que no hay nada que `dispatchAfterCommit()` pueda drenar aunque de alguna forma se le llamara.** El propio docblock de `OpenWalletHandler` declara esto como la razón por la que el atributo está ahí, y no decoración:

```php
/**
 * #[Transactional] is LOAD-BEARING, not cosmetic: DefaultCommandBus opens no transaction of its own, and
 * EloquentRepository::save() only tracks the aggregate when transactionLevel() > 0. The generated transactional
 * proxy installs the TransactionTemplate that is the sole caller of DomainEventDispatcher::dispatchAfterCommit(),
 * so without this attribute the WalletOpened domain event would never publish and S5's ledger projector would
 * never fire.
 */
```

Recuerda del Capítulo 7 que `DefaultCommandBus::send()` no abre **ninguna** transacción propia — correlaciona, valida, autoriza e invoca al manejador, y punto. Cada pizca de comportamiento transaccional que has visto en `OpenWalletHandler`, `DepositHandler`, `WithdrawHandler` y `TransferHandler` proviene *enteramente* del atributo `#[Transactional]` sobre sus métodos `handle()`, a través del mecanismo de proxy exacto que este capítulo acaba de recorrer. Quítale el atributo a cualquiera de ellos y el comando igual "tiene éxito" — la fila igual se escribe mediante una llamada a `save()` plana, sin proxificar — pero `WalletOpened`/`FundsDeposited`/`FundsWithdrawn` se lanzan al búfer privado de eventos del agregado y luego se **descartan en silencio**, porque nada jamás drena ese búfer. `LedgerProjector` (Capítulo 6) simplemente nunca se dispararía, sin error, sin advertencia, y con una respuesta HTTP de apariencia perfectamente exitosa.

!!! warning "Sin `#[Transactional]`, no hay publicación de eventos de dominio — en silencio"
    Esto no es un fallo lento ni un mensaje de error engañoso; es un **no-op completo y silencioso** para cada evento de dominio que el agregado del manejador haya lanzado. La escritura se confirma. El evento se desvanece. Nada en la respuesta, en los logs, ni en el esquema de la base de datos te dice que ocurrió — la única forma de notarlo es que un proyector o listener aguas abajo que esperabas que se disparara, nunca lo hace. Trata `#[Transactional]` sobre un manejador de comando que toca un agregado como estructuralmente necesario, no como un estilo.

---

## El `Transfer` atómico: el dinero no puede desvanecerse

El Capítulo 6 ya te mostró el código completo de `TransferHandler` — una única frontera `#[Transactional(propagation: Propagation::REQUIRED)]` envolviendo un débito, un guardado, un crédito, y un segundo guardado. Lo que el Capítulo 6 no te mostró es la prueba rigurosa de que la afirmación de atomicidad realmente se sostiene ante un fallo real. `samples/lumen/tests/Application/TransferSecurityTest.php` despacha a través del `CommandBus`/`QueryBus` reales — los mismos puertos que introdujo el Capítulo 7 — y demuestra ambas direcciones:

```php
it('transfers atomically: money is conserved across debit + credit', function () {
    $commands = $this->fireflyContext()->get(CommandBus::class);
    $queries = $this->fireflyContext()->get(QueryBus::class);

    $src = $commands->send(new OpenWallet('owner-A', Currency::EUR));
    $dst = $commands->send(new OpenWallet('owner-B', Currency::EUR));
    $commands->send(new Deposit($src, 10000));

    $commands->send(new Transfer($src, $dst, 4000));

    // Debit + credit committed as one unit of work: the 10000 that left nowhere reappears split 6000/4000.
    expect($queries->ask(new GetBalance($src)))->toBe(6000);
    expect($queries->ask(new GetBalance($dst)))->toBe(4000);
});

it('rolls the whole transfer back when the credit leg fails (money cannot vanish)', function () {
    $commands = $this->fireflyContext()->get(CommandBus::class);
    $queries = $this->fireflyContext()->get(QueryBus::class);

    // Destination in a DIFFERENT currency: the debited EUR amount cannot be credited into a USD wallet, so the
    // credit leg throws currency-mismatch AFTER the debit already ran -> the whole #[Transactional] tx rolls back.
    $src = $commands->send(new OpenWallet('owner-A', Currency::EUR));
    $dst = $commands->send(new OpenWallet('owner-B', Currency::USD));
    $commands->send(new Deposit($src, 10000));

    expect(fn () => $commands->send(new Transfer($src, $dst, 4000)))
        ->toThrow(CommandProcessingException::class);

    // Load-bearing, non-tautological proof that money cannot vanish: the source debit was ROLLED BACK (still
    // 10000, not 6000) and the destination never received anything (still 0). No value was created or destroyed.
    expect($queries->ask(new GetBalance($src)))->toBe(10000);
    expect($queries->ask(new GetBalance($dst)))->toBe(0);
});
```

La segunda prueba es la que importa. `Wallet::withdraw()` sobre el origen corrió con éxito y lanzó `FundsWithdrawn` a su propio búfer; luego `Wallet::deposit()` sobre el destino lanzó un `ConflictException` de moneda no coincidente — *después* de que la llamada `save()` del débito ya se hubiera ejecutado dentro de la misma transacción, todavía abierta. Porque el `rollbackFor` por defecto de `#[Transactional]` captura cualquier `Throwable`, el método entero revierte: el débito del origen se deshace a nivel de base de datos, y — porque el rollback ocurre *antes* de que `dispatchAfterCommit()` fuera a alcanzarse en el camino de éxito, y `TransactionTemplate` también lo llama en la rama `catch` precisamente para que un drenado revertido igual vacíe el rastreador sin llegar jamás a programar una publicación — ni `FundsWithdrawn` ni un `FundsDeposited` que ni siquiera llegó a lanzarse alcanza a ningún listener. El `CommandProcessingException` del Capítulo 7 envuelve el `ConflictException` subyacente, y las dos aserciones de saldo son todo el punto: no "la transferencia falló" en abstracto, sino que el propio `10000` del origen volvió **exactamente**, y el `0` del destino nunca se movió. No se creó valor; no se destruyó ninguno.

---

## Lo que aprendiste {.recap}

| Concepto | Qué hace |
|---|---|
| `#[Transactional]` | Valor por defecto a nivel de clase, *reemplazo* a nivel de método (no fusión); `rollbackFor = [Throwable::class]` por defecto |
| `Propagation` (7 modos) | `TransactionTemplate::execute()` es la única fuente de verdad para los siete, tanto para el proxy como para el llamador programático |
| `Isolation` / `readOnly` | Sentencias `SET TRANSACTION …` de mejor esfuerzo, solo en la transacción más externa |
| `shouldRollBack()` | `noRollbackFor` gana sobre `rollbackFor`; no coincidir con ninguna de las dos listas también confirma |
| El proxy generado | `{Target}__FireflyTransactionalProxy extends {Target}`; enruta cada llamada a través de `TransactionInterceptor::run()` y luego `parent::` |
| Esquive por auto-invocación | `$this->otro()` dentro de la clase proxificada se salta el interceptor por completo — usa el `TransactionTemplate` inyectado en su lugar |
| `DataAutoConfiguration::transactionalManifest()`/`proxyPlan()` | Artefacto compilado primero, escaneo en proceso después, vacío al final — la razón por la que ninguna aplicación necesita vincular un manifiesto a mano |
| La lección clave | `TransactionTemplate` es el **único** que llama a `dispatchAfterCommit()` — sin `#[Transactional]`, no hay publicación de eventos de dominio, en silencio |
| `Transfer` | Débito + crédito + ambos `save()` en una sola frontera — una rama de crédito fallida revierte también el débito ya ejecutado |

---

## Ponlo en práctica {.exercises}

1. **Reproduce la pérdida silenciosa de eventos.** En una copia de prueba del proyecto (no el paquete `samples/lumen` distribuido), quita `#[Transactional]` de una copia de `DepositHandler::handle()`, deposita en un monedero a través de la API HTTP, y confirma que el saldo *sí* se actualiza (la fila igual se escribe) mientras que el libro mayor (la tabla `ledger_entries` de `LedgerProjector`) **no recibe ninguna fila nueva en absoluto** — sin error en ningún lado.
2. **Demuestra que `NOT_SUPPORTED` no puede suspender.** Dale a un método `#[Transactional(propagation: Propagation::NOT_SUPPORTED)]`, llámalo desde dentro de otro método `#[Transactional(propagation: Propagation::REQUIRED)]` en la *misma* conexión (a través de `TransactionTemplate`, no por auto-invocación), y confirma — según la descripción "Known-latent" de este capítulo — que el trabajo interno igual corre dentro de la transacción externa en lugar de estar realmente fuera de una.
3. **Lee el código fuente del proxy generado.** Después de ejecutar `php artisan firefly:cache` en un proyecto con una clase `#[Transactional]`, abre el archivo emitido bajo `bootstrap/cache/firefly/proxies/` y busca los cuatro tipos de miembro que renderiza `ProxyClassGenerator`: la sobrescritura `(new \Firefly\Data\Proxy\MethodInvocation(...))->proceed()` que escribe para cada método aconsejado, la propiedad privada `$__fireflyTxInterceptor`, la fábrica privada estática `__fireflyTxDescriptor('m')` dentro de la cual se hornea el literal del descriptor, y la tabla pública estática `__fireflyAdvice()` que `ProxyFactory` lee para saber qué bean interceptor va en cada propiedad. Después dale a un *segundo* bean una regla `#[PreAuthorize]` y **ningún** `#[Transactional]`, vuelve a ejecutar `firefly:cache`, y confirma que el plan también lo nombró: recibe un proxy propio, con `$__fireflySecurityInterceptor` y `__fireflySecurityDescriptor('m')` en su lugar. Por último pon ambos advices sobre un mismo método y lee el orden de la cadena directamente del fuente generado — el array de interceptores de la sobrescritura es `[$this->__fireflySecurityInterceptor, $this->__fireflyTxInterceptor]`, de fuera hacia dentro, exactamente el orden que dibuja la Figura 9.1.
