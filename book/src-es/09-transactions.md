<span class="eyebrow">Parte III — Coordinar y Asegurar la Aplicación · Capítulo 9</span>

# Transacciones y el Proxy `#[Transactional]` {.chtitle}

Al terminar este capítulo sabrás exactamente qué hace `#[Transactional]` — sus siete modos de propagación, sus ajustes de aislamiento/solo-lectura/rollback, y el proxy generado que le da dientes — cómo la auto-invocación esquiva ese proxy (y qué hacer en su lugar), cómo `firefly:cache` cierra el círculo que el Capítulo 2 abrió con `CachedTransactionalConfiguration`, y el hecho más consecuente de todo este libro hasta ahora: **un evento de dominio se publica únicamente porque `TransactionTemplate` — la maquinaria detrás de `#[Transactional]`— es el único que llama al despacho post-confirmación.** Sin `#[Transactional]`, no hay publicación, sin importar con cuánta corrección un agregado haya lanzado su evento.

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
    public function transfer(int $amount): int
    {
        return $this->__fireflyTxInterceptor->run(
            fn () => parent::transfer($amount),
            self::__fireflyTxDescriptor('transfer'),
        );
    }
}
```

— enrutando la llamada real a través de `TransactionInterceptor::run()` (que delega directamente en `TransactionTemplate::execute()`) antes de caer hacia `parent::`. Una prueba capstone real confirma que el intercambio realmente ocurrió — la clase del bean resuelto **no** es en absoluto la clase de servicio plana:

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

`$service::class` es el `AccountService__FireflyTransactionalProxy` generado, y sin embargo `$service instanceof AccountService` sigue siendo verdadero — el proxy *es-un* `{Target}`, así que cada llamada del contenedor y `#[PreDestroy]` se resuelven contra él exactamente como lo harían contra el bean original. `ProxyFactory` lo instancia **preservando el estado**: `newInstanceWithoutConstructor()` (así `#[PostConstruct]` no se vuelve a ejecutar), y luego una closure vinculada copia el estado visible en el ámbito del bean real vía `get_object_vars()` — nunca `ReflectionProperty` — sobre el proxy.

!!! warning "La auto-invocación esquiva el proxy"
    Un método que llama a `$this->otroMetodo()` desde *dentro* de la clase proxificada llama directamente a través de `parent::`, saltándose `__fireflyTxInterceptor` por completo — la misma limitación bien conocida de Spring. Esto es exactamente por qué `AccountService::outerWithNested()` de arriba no simplemente llama a algún hipotético método `$this->innerNested()` — en su lugar pasa por el **`TransactionTemplate` inyectado**, que es la vía de escape correcta para obtener semántica transaccional en una unidad de trabajo interna desde dentro de otro método de la misma instancia.

---

## Cerrando el círculo desde el Capítulo 2: cómo una aplicación real consigue de verdad un proxy funcional

El Capítulo 2 te mostró `App\Support\CachedTransactionalConfiguration` — un archivo real que el proyecto `firefly/skeleton` distribuye de fábrica — y prometió que no necesitarías `#[Transactional]` en sí "hasta un capítulo posterior". Este es ese capítulo, y aquí está exactamente por qué existe ese archivo.

`php artisan firefly:cache` emite **dos** artefactos separados para `#[Transactional]`: ejecuta `TransactionalScanner`/`TransactionalManifestCompiler` — uno de los doce pares scanner/compiler — para escribir los datos del manifiesto en `bootstrap/cache/firefly/transactional.php`, *y* ejecuta `ProxyClassGenerator` para emitir un archivo fuente `{Target}__FireflyTransactionalProxy` por cada clase escaneada en un directorio `proxies/`, más un classmap `proxies.php`. `FireflyCacheServiceProvider` registra un autoloader para ese classmap incondicionalmente, así que cada clase proxy generada es cargable en el momento en que el contenedor la pide.

Eso resuelve las **clases proxy**. No hace, por sí solo, que `TransactionalBeanPostProcessor` realmente intercambie nada — para eso, el contenedor necesita los *datos* compilados de `TransactionalManifest` vinculados como un bean, y aquí es donde `TransactionalManifest` se comporta de forma distinta a cualquier otro manifiesto que este libro te haya mostrado. `HandlerManifest` (Capítulo 7), `EventListenerManifest` (Capítulo 8) y `SecurityMethodManifest` (Capítulo 10) están todos vinculados mediante un valor por defecto de proveedor protegido por `bound()` — `$app->instance()` desde `FireflyCacheServiceProvider` lo sobrescribe incondicionalmente, sin importar el orden de registro. El valor por defecto vacío de `TransactionalManifest`, en cambio, es un `#[Bean]` `#[Configuration]` genuino sobre `DataAutoConfiguration`, condicionado con `#[ConditionalOnMissingBean(TransactionalManifest::class)]` — y esa condición se evalúa contra el **registro de definiciones de bean**, no contra las vinculaciones del contenedor de Laravel. Una simple llamada `$app->instance(TransactionalManifest::class, ...)` es *invisible* para ella: el propio `#[Bean]` del valor por defecto vacío igual dispara más tarde y sobrescribe lo que se hubiera vinculado por instancia.

La única costura de anulación real es una **definición de bean competidora** — otra clase `#[Configuration]` que suministra su propio `#[Bean] transactionalManifest(): TransactionalManifest`, registrada en un `#[Order]` por debajo del `1000` de `DataAutoConfiguration`. Eso es todo el contenido del archivo que el Capítulo 2 ya te mostró:

```php
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

Porque `firefly/skeleton` distribuye ya este archivo, en `app/Support/`, descubierto por el escaneo de componentes corriente como cualquier otro `#[Configuration]`, una aplicación LaraFly construida como el Inicio Rápido te hizo construir una obtiene un proxy `#[Transactional]` **completamente funcional** en el momento en que ejecutas `firefly:cache` — sin paso extra, sin cableado manual. Las propias suites de pruebas del framework, que no arrancan a través del skeleton, suministran el equivalente en línea: `packages/data/tests/Fixtures/Capstone/CapstoneTransactionalConfiguration` para el fixture `AccountService` de arriba, y `samples/lumen/tests/Support/LumenTransactionalConfiguration` para cada manejador que este libro te ha mostrado de `samples/lumen`. Los tres tienen la misma forma por la misma razón.

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
| `CachedTransactionalConfiguration` | El único `#[Bean]` competidor que realmente activa el `TransactionalManifest` cacheado — distribuido por el skeleton |
| La lección clave | `TransactionTemplate` es el **único** que llama a `dispatchAfterCommit()` — sin `#[Transactional]`, no hay publicación de eventos de dominio, en silencio |
| `Transfer` | Débito + crédito + ambos `save()` en una sola frontera — una rama de crédito fallida revierte también el débito ya ejecutado |

---

## Ponlo en práctica {.exercises}

1. **Reproduce la pérdida silenciosa de eventos.** En una copia de prueba del proyecto (no el paquete `samples/lumen` distribuido), quita `#[Transactional]` de una copia de `DepositHandler::handle()`, deposita en un monedero a través de la API HTTP, y confirma que el saldo *sí* se actualiza (la fila igual se escribe) mientras que el libro mayor (la tabla `ledger_entries` de `LedgerProjector`) **no recibe ninguna fila nueva en absoluto** — sin error en ningún lado.
2. **Demuestra que `NOT_SUPPORTED` no puede suspender.** Dale a un método `#[Transactional(propagation: Propagation::NOT_SUPPORTED)]`, llámalo desde dentro de otro método `#[Transactional(propagation: Propagation::REQUIRED)]` en la *misma* conexión (a través de `TransactionTemplate`, no por auto-invocación), y confirma — según la descripción "Known-latent" de este capítulo — que el trabajo interno igual corre dentro de la transacción externa en lugar de estar realmente fuera de una.
3. **Lee el código fuente del proxy generado.** Después de ejecutar `php artisan firefly:cache` en un proyecto con una clase `#[Transactional]`, abre el archivo emitido bajo `bootstrap/cache/firefly/proxies/` y relaciona su llamada `__fireflyTxInterceptor->run(...)` y su método `__fireflyTxDescriptor()` con las dos cosas que renderiza `ProxyClassGenerator`, tal como describió este capítulo.
