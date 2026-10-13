<span class="eyebrow">Parte III — Coordinar y Asegurar la Aplicación · Capítulo 9B</span>

# Event Sourcing: guardar lo que ocurrió {.chtitle}

Al terminar este capítulo sabrás guardar lo que ocurrió en lugar de lo que es. Modelarás los préstamos de Lumen como un agregado con event sourcing (`EventSourcedAggregateRoot`, `recordThat()`) cuyos eventos llevan un nombre estable y una versión (`#[EventType]`), los añadirás al almacén de eventos de base de datos bajo concurrencia optimista (`firefly.eventsourcing.store.provider`, `ConcurrencyException`) y reconstruirás un préstamo desde su historia o desde una instantánea (`SnapshotAware`, `firefly.eventsourcing.snapshot.threshold`) a través de un `EventSourcedRepository`. Mantendrás al día un modelo de lectura con una `Projection` y su punto de control (`firefly:eventsourcing:projections:run`, `firefly:eventsourcing:projections:rebuild`), leerás eventos guardados con un nombre de campo antiguo a través de un `EventUpcaster`, publicarás cada evento añadido a través del outbox en la propia transacción del append (`firefly.eventsourcing.publish.enabled`) y guardarás las ejecuciones de saga del Capítulo 9A como flujos de eventos (`firefly.orchestration.persistence.provider`).

!!! note "Término nuevo: event sourcing"
    El **event sourcing** guarda un objeto como la secuencia de hechos que le ocurrieron — concedido, amortizado, amortizado, liquidado — en lugar de como una fila con sus últimos valores. El estado actual es lo que obtienes al aplicar esos hechos en orden; nada del pasado se sobrescribe nunca, así que la historia no es una tabla de auditoría que esperas que se mantenga al día, es el propio registro.

---

## Lo que recuerda un préstamo

Un `Wallet` es una fila: el Capítulo 5 guarda su saldo, y el `LedgerProjector` del Capítulo 6 escribe una línea del libro mayor después de que cada cambio se confirma. Es el modelo adecuado para un saldo. Un préstamo es distinto: lo que importa es la historia — cuánto se prestó, cada amortización y cuándo, el momento en que quedó liquidado — y el saldo no es más que aritmética sobre ella. Así que Lumen guarda los préstamos al revés. Ninguna tabla contiene el saldo de un préstamo; el almacén de eventos contiene sus eventos, y el préstamo se reconstruye a partir de ellos cada vez que un comando lo necesita. El monedero se queda exactamente como lo construyeron los Capítulos 5 a 9A, y los dos se encuentran en una sola transacción más adelante en este capítulo.

---

## Eventos con un nombre y una versión

Un evento con event sourcing es un `DomainEvent` corriente de `firefly/domain` con un atributo más. Esta es la amortización:

<!-- source: samples/lumen/src/Domain/Loan/Event/LoanRepaid.php -->
```php
#[EventType(name: 'loan.repaid', version: 2)]
final readonly class LoanRepaid extends DomainEvent
{
    public function __construct(
        public string $loanId,
        public int $amountMinor,
        public int $outstandingMinor,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($eventId, $occurredAt);
    }
}
```

`#[EventType]` da al evento el nombre que guarda el almacén — `loan.repaid`, nunca el nombre de la clase, así que la clase puede moverse o renombrarse sin dejar huérfana ni una sola fila guardada — y la versión de su carga útil. El mapeador por convención guarda las propiedades públicas y reconstruye el evento a través de su constructor, y por eso `eventId` y `occurredAt` se pasan al padre: un evento recargado conserva la identidad y la hora con las que se registró. `php artisan firefly:cache` compila cada clase `#[EventType]` en el manifiesto `eventsourcing.php` (Capítulo 13), y una clase de evento que no esté en él se rechaza en el append, así que nunca se guarda nada que no pueda leerse de vuelta. La versión es 2 porque los primeros préstamos llamaban `amount` al importe; de eso trata la sección sobre upcasting más abajo.

---

## Un agregado que es su historia

`Loan` extiende `EventSourcedAggregateRoot`. Un método de comando comprueba su invariante contra el estado actual y llama a `recordThat()`, que aplica el evento al instante y lo retiene en un búfer hasta que el repositorio lo guarda; los métodos protegidos `apply…()` son el único código que cambia el estado:

<!-- source: samples/lumen/src/Domain/Loan/Loan.php -->
```php
final class Loan extends EventSourcedAggregateRoot implements SnapshotAware
{
    // …
    public static function grant(string $id, string $walletId, Money $principal): self
    {
        if (! $principal->isPositive()) {
            throw new ConflictException('a loan principal must be > 0');
        }

        $loan = new self($id);
        $loan->recordThat(new LoanGranted($id, $walletId, $principal->minorUnits, $principal->currency->value));

        return $loan;
    }

    public function repay(Money $amount): void
    {
        if ($this->settled) {
            throw new ConflictException("loan {$this->id()} is already settled");
        }
        if ($amount->currency !== $this->currency || ! $amount->isPositive()) {
            throw new ConflictException("a repayment must be a positive amount in {$this->currency->value}");
        }
        if ($amount->minorUnits > $this->outstanding()->minorUnits) {
            throw new ConflictException("cannot repay {$amount}; {$this->outstanding()} is outstanding");
        }

        $this->recordThat(new LoanRepaid($this->id(), $amount->minorUnits, $this->outstanding()->minorUnits - $amount->minorUnits));
        if ($this->outstanding()->minorUnits === 0) {
            $this->recordThat(new LoanSettled($this->id(), $this->repayments));
        }
    }
    // …
    protected function applyLoanGranted(LoanGranted $event): void
    {
        $this->walletId = $event->walletId;
        $this->currency = Currency::from($event->currency);
        $this->principalMinor = $event->principalMinor;
    }

    protected function applyLoanRepaid(LoanRepaid $event): void
    {
        $this->repaidMinor += $event->amountMinor;
        $this->repayments++;
    }

    protected function applyLoanSettled(LoanSettled $event): void
    {
        $this->settled = true;
    }
```

Dos reglas hacen que esto funcione. Primera, las invariantes se comprueban *antes* de `recordThat()`, contra un estado que solo han escrito los métodos `apply…()`, así que reproducir los mismos eventos siempre reconstruye un objeto que habría tomado las mismas decisiones. Segunda, la aplicación se enruta por convención — `applyLoanRepaid()` para `LoanRepaid` — y un método `apply…()` no debe fallar: el evento ya ocurrió, y la historia no se puede rechazar al reproducirla. El constructor pertenece a la clase base y recibe solo el id, y así es como el repositorio puede crear un préstamo vacío antes de reproducir uno; `aggregateType()` devuelve `lumen.loan`, el nombre estable que se guarda con cada evento.

---

## El almacén de eventos y el repositorio

Los eventos viven en un `EventStore`. `memory` es el valor por defecto y el adecuado para probar el modelo; `database` los guarda en cuatro tablas portables sobre cualquier conexión de Laravel:

<!-- source: skeleton/config/firefly.php -->
```php
'store' => [
    'provider' => env('FIREFLY_EVENTSOURCING_STORE', 'memory'),
    'connection' => env('FIREFLY_EVENTSOURCING_DB_CONNECTION'),
    'batch-size' => 500,
],
```

El arnés de event sourcing de Lumen fija `firefly.eventsourcing.store.provider=database`, y los almacenes de instantáneas y de puntos de control lo siguen. El repositorio es un constructor de una línea sobre el bean `EventSourcingSupport`:

<!-- source: samples/lumen/src/Infrastructure/Loan/LoanRepository.php -->
```php
#[Component]
#[Lazy]
final class LoanRepository extends EventSourcedRepository
{
    public function __construct(EventSourcingSupport $support)
    {
        parent::__construct($support, Loan::class);
    }
}
```

`get()` reproduce el flujo de eventos de un préstamo (un 404 `AggregateNotFoundException` cuando no tiene ninguno), `find()` devuelve null en su lugar, y `save()` añade los eventos del búfer bajo la versión con la que se cargó el préstamo. `#[Lazy]` está ahí por el bien del ejemplo: `EventSourcingSupport` solo existe donde arrancan los proveedores de `firefly/eventsourcing`, y los demás arneses de test de Lumen nunca piden un préstamo, así que nunca deben construir este bean al arrancar — la misma razón por la que lo lleva `PayoutController` en el Capítulo 9A.

---

## Instantáneas: reproducir menos

Reproducir tres eventos no cuesta nada; reproducir tres mil en cada comando, sí. De un agregado que implementa `SnapshotAware` se toma una instantánea cada vez que un guardado lleva su versión más allá de un múltiplo de `firefly.eventsourcing.snapshot.threshold`, y una carga parte de la instantánea más reciente y reproduce solo lo que vino después:

<!-- source: skeleton/config/firefly.php -->
```php
'snapshot' => [
    'enabled' => env('FIREFLY_EVENTSOURCING_SNAPSHOT_ENABLED', true),
    'store' => env('FIREFLY_EVENTSOURCING_SNAPSHOT_STORE'),
    'threshold' => (int) env('FIREFLY_EVENTSOURCING_SNAPSHOT_THRESHOLD', 50),
    'keep-count' => 3,
],
```

`Loan` dice qué contiene una instantánea — todo lo que reproducir reconstruiría, como valores planos:

<!-- source: samples/lumen/src/Domain/Loan/Loan.php -->
```php
    /** @return array{walletId: string, currency: string, principalMinor: int, repaidMinor: int, repayments: int, settled: bool} */
    public function snapshotState(): array
    {
        return [
            'walletId' => $this->walletId,
            'currency' => $this->currency->value,
            'principalMinor' => $this->principalMinor,
            'repaidMinor' => $this->repaidMinor,
            'repayments' => $this->repayments,
            'settled' => $this->settled,
        ];
    }
```

El umbral por defecto es 50; el arnés lo baja a 3 para que un test corto lo cruce. Un préstamo concedido y luego amortizado tres veces tiene cuatro eventos, y el test muestra qué hace una carga con ellos:

<!-- source: samples/lumen/tests/EventSourcing/LoanRepositoryTest.php -->
```php
    $snapshot = $this->app()->make(SnapshotStore::class)->latest('loan-r2');
    $replayed = $this->app()->make(EventStore::class)->load('loan-r2', 4)->events;
    $loan = $loans->get('loan-r2');

    expect($snapshot?->version)->toBe(3)
        ->and($snapshot?->state)->toMatchArray(['repaidMinor' => 40000, 'repayments' => 2])
        ->and(array_map(static fn (StoredEvent $event): string => $event->eventType, $replayed))->toBe(['loan.repaid'])
        ->and([$loan->repaidMinor(), $loan->outstanding()->minorUnits, $loan->committedVersion()])->toBe([60000, 30000, 4]);
```

La instantánea se tomó en la versión 3, así que cargar el préstamo la restaura y reproduce un evento — el cuarto. Una instantánea es una optimización y nada más: una que no puede escribirse se registra en el log y el guardado se mantiene, una cuyo `snapshotVersion()` ya no coincide con la clase se ignora y el préstamo se reproduce desde su primer evento, así que cambiar la forma de la instantánea es subir una versión, nunca una migración.

---

## Dos escritores, una versión

Cada evento de un flujo tiene una versión — 1, 2, 3 — y el almacén rechaza un segundo evento en una versión ya ocupada (`UNIQUE(aggregate_id, aggregate_version)`). Eso es concurrencia optimista, y es lo que impide que dos peticiones amorticen el mismo préstamo a partir de la misma imagen desfasada de él:

<!-- source: samples/lumen/tests/EventSourcing/LoanRepositoryTest.php -->
```php
    $first = $loans->get('loan-r3');
    $second = $loans->get('loan-r3');

    $first->repay(new Money(10000, Currency::EUR));
    $loans->save($first);
    $second->repay(new Money(10000, Currency::EUR));

    expect(fn () => $loans->save($second))->toThrow(ConcurrencyException::class)
        ->and($loans->get('loan-r3')->repaidMinor())->toBe(10000);
```

Los dos escritores cargaron la versión 1. El primer guardado añadió la versión 2; el segundo intentó añadir también la versión 2 y recibió un 409 `ConcurrencyException`. Su decisión se tomó contra un préstamo que ya no existe, así que la respuesta honesta es recargar y decidir de nuevo — o dejar que el 409 llegue al cliente, que la capa web presenta como problem+json igual que cualquier otro conflicto.

---

## Una transacción con el monedero

Conceder un préstamo ingresa el principal en el monedero; amortizarlo vuelve a sacar el importe. Lumen hace las dos cosas en un solo comando, así que el almacén de eventos y el monedero de Eloquent comparten una sola frontera `#[Transactional]`:

<!-- source: samples/lumen/src/Application/Loan/RepayLoanHandler.php -->
```php
    #[PreAuthorize("hasRole('ADMIN') or (hasRole('WALLET_OWNER') and hasPermission(#command, 'debit'))")]
    #[Transactional]
    public function handle(RepayLoan $command): int
    {
        $loan = $this->loans->get($command->loanId);
        $wallet = $this->wallets->findById($loan->walletId())
            ?? throw new ResourceNotFoundException("Wallet [{$loan->walletId()}] not found");

        $amount = new Money($command->amountMinor, $loan->currency());
        $loan->repay($amount);
        $this->loans->save($loan);

        $wallet->withdraw($amount);
        $this->wallets->save($wallet);

        return $loan->outstanding()->minorUnits;
    }
```

El orden es deliberado. Primero se añade la amortización, y solo entonces se carga el monedero — así que cuando el monedero no puede pagar, `Wallet::withdraw()` lanza la excepción *después* de que se escribiera el evento, y la única transacción deshace ambas cosas:

<!-- source: samples/lumen/tests/EventSourcing/LoanCommandsTest.php -->
```php
    $this->commands()->send(new Withdraw($walletId, 85000));

    $caught = null;
    try {
        $this->commands()->send(new RepayLoan($loanId, 30000));
    } catch (CommandProcessingException $e) {
        $caught = $e;
    }

    // The repayment was appended, then the wallet refused the withdrawal: one transaction, so both are undone.
    expect($caught?->getPrevious())->toBeInstanceOf(ConflictException::class)
        ->and(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_id', $loanId)->count())->toBe(1)
        ->and($this->app()->make(LoanRepository::class)->get($loanId)->outstanding()->minorUnits)->toBe(90000)
        ->and($this->queries()->ask(new GetBalance($walletId)))->toBe(5000);
```

El flujo sigue conteniendo solo `loan.granted`, el préstamo sigue debiendo 900,00 y el monedero sigue teniendo sus 50,00: nada se quedó a medias. El manejador está protegido como el `Withdraw` del Capítulo 10: un `ADMIN` puede amortizar cualquier préstamo, y un `WALLET_OWNER` solo uno abonado en un monedero suyo — `WalletPermissionEvaluator` carga el préstamo para averiguar ese monedero, y deniega si no lo encuentra. Es `#[Lazy]` por la razón del repositorio. Una consecuencia más de compartir la transacción: `save()` vacía el búfer del préstamo, así que el cableado posterior al commit de `firefly/data` (Capítulo 9) nunca despacha sus eventos una segunda vez; el `FundsWithdrawn` del propio monedero, sí.

---

## Proyecciones y sus puntos de control

Los eventos responden a "qué le pasó a este préstamo"; son una mala respuesta a "qué préstamos siguen abiertos". Esa pregunta corresponde a un modelo de lectura, y una **proyección** mantiene uno a partir de los eventos. `LoanBookProjection` es un componente que el ejecutor encuentra por sí solo:

<!-- source: samples/lumen/src/Application/Loan/LoanBookProjection.php -->
```php
    public function name(): string
    {
        return self::NAME;
    }

    public function handles(): array
    {
        return ['loan.granted', 'loan.repaid', 'loan.settled'];
    }

    public function handle(StoredEvent $event): void
    {
        $payload = $event->payload;
        $loan = DB::table(self::TABLE)->where('loan_id', $event->aggregateId);

        if ($event->eventType === 'loan.granted') {
            DB::table(self::TABLE)->insert([
                'loan_id' => $event->aggregateId,
                'wallet_id' => self::text($payload, 'walletId'),
                'currency' => self::text($payload, 'currency'),
                'principal_minor' => self::number($payload, 'principalMinor'),
                'repaid_minor' => 0,
                'outstanding_minor' => self::number($payload, 'principalMinor'),
                'repayments' => 0,
                'status' => 'open',
            ]);
        } elseif ($event->eventType === 'loan.repaid') {
            $loan->incrementEach(
                ['repaid_minor' => self::number($payload, 'amountMinor'), 'repayments' => 1],
                ['outstanding_minor' => self::number($payload, 'outstandingMinor')],
            );
        } else {
            $loan->update(['status' => 'settled']);
        }
    }
```

Una proyección lee el **flujo global** — todos los eventos de todos los agregados, en un solo orden — desde su **punto de control**, la posición hasta la que ha proyectado. El test muestra la distancia entre los dos mundos y el comando que la cierra:

<!-- source: samples/lumen/tests/EventSourcing/LoanBookProjectionTest.php -->
```php
    // The events are stored; the loan book has not been told yet.
    expect(DB::table(LoanBookProjection::TABLE)->count())->toBe(0);

    $exit = Artisan::call('firefly:eventsourcing:projections:run', ['projection' => LoanBookProjection::NAME]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('lumen.loan-book: caught-up, 2 event(s) handled, at position 2')
        ->and((array) DB::table(LoanBookProjection::TABLE)->where('loan_id', $loanId)->first())->toMatchArray([
            'wallet_id' => $walletId,
            'principal_minor' => 90000,
            'repaid_minor' => 30000,
            'outstanding_minor' => 60000,
            'repayments' => 1,
            'status' => 'open',
        ])
        ->and(DB::table(EventSourcingSchema::CHECKPOINTS)->where('projection', LoanBookProjection::NAME)->value('position'))->toEqual(2);
```

Cada lote es una transacción sobre la conexión del almacén de puntos de control: el ejecutor entrega a la proyección los eventos que `handles()`, mueve el punto de control desde la posición que leyó hasta la última del lote, y confirma ambas cosas. `LoanBookProjection` escribe a través de la conexión por defecto, que es esa conexión mientras `firefly.eventsourcing.store.connection` sea null, así que sus filas y su punto de control se confirman juntos y cada evento se proyecta exactamente una vez; una proyección que escribe en cualquier otro sitio es al-menos-una-vez y debe ser idempotente. Cada lote se toma bajo un arrendamiento sobre el puerto `DistributedLock` (`firefly.scheduling.lock.provider`, durante `firefly.eventsourcing.projection.lease-ttl`), así que puedes ejecutar el comando en todos los nodos y solo uno hace avanzar cada proyección a la vez — y el avance del punto de control está protegido por una valla, así que un ejecutor cuyo arrendamiento caducó a mitad de lote revierte su lote en lugar de sobrescribir una posición más reciente. Cada escritura de `handle()` nombra la fila por su clave primaria, y eso es lo que permite a SQL Server ejecutarla sin esperar a las filas sin confirmar de otro trabajador. Los operadores manejan las proyecciones desde la línea de comandos:

<!-- illustrative: the commands an operator runs in the application's directory -->
```bash
php artisan firefly:eventsourcing:projections
php artisan firefly:eventsourcing:projections:run
php artisan firefly:eventsourcing:projections:run --live
php artisan firefly:eventsourcing:projections:rebuild lumen.loan-book
```

El primero enumera cada proyección con su punto de control, la cabeza global y su retraso; el segundo pone al día todas las proyecciones y termina, y `--live` sigue el flujo indefinidamente bajo un supervisor de procesos; el último reinicia la proyección y su punto de control y reproduce en ella todo el flujo — `LoanBookProjectionTest` borra la cartera de préstamos y la recupera así. El flujo global se numera en orden de **commit**, no en orden de inserción: una secuencia entrega su valor cuando se inserta una fila, así que una transacción que tiene la posición 10 podría confirmarse después de la que tiene la 11, y un lector que hubiera guardado el punto de control 11 se saltaría la 10 para siempre. El almacén numera los eventos confirmados en una breve transacción propia antes de cada lectura global, así que un lector nunca ve aparecer un evento por debajo de una posición que ya ha pasado.

---

## Upcasting de un campo renombrado

Los eventos son inmutables, así que una forma cambiada es una versión nueva. Los primeros préstamos guardaron sus amortizaciones como `loan.repaid` versión 1, con el importe llamado `amount`; la clase dice ahora `amountMinor`, como cualquier otro campo monetario de Lumen. Las filas guardadas nunca se reescriben. Un `EventUpcaster` hace avanzar una carga útil de la versión 1 cada vez que se lee una:

<!-- source: samples/lumen/src/Infrastructure/Loan/LoanRepaidV1Upcaster.php -->
```php
#[Component]
final class LoanRepaidV1Upcaster implements EventUpcaster
{
    public function eventType(): string
    {
        return 'loan.repaid';
    }

    public function fromVersion(): int
    {
        return 1;
    }

    public function priority(): int
    {
        return 0;
    }

    public function upcast(StoredEvent $event): StoredEvent
    {
        $payload = $event->payload;
        $payload['amountMinor'] = $payload['amount'] ?? 0;
        unset($payload['amount']);

        return $event->upcasted('loan.repaid', 2, $payload);
    }
}
```

El upcasting ocurre en el almacén, al leer, así que el repositorio y todas las proyecciones ven el mismo evento de la versión 2. El test escribe una amortización exactamente como la dejó un despliegue antiguo y la lee de las dos maneras:

<!-- source: samples/lumen/tests/EventSourcing/LoanRepaidUpcasterTest.php -->
```php
    // A repayment exactly as the first loans were stored: version 1, its amount still called `amount`.
    $this->app()->make(EventStore::class)->append('loan-legacy', Loan::aggregateType(), [
        new NewEvent('loan.repaid', ['loanId' => 'loan-legacy', 'amount' => 20000, 'outstandingMinor' => 30000], 1),
    ], 1);

    $loan = $loans->get('loan-legacy');
    Artisan::call('firefly:eventsourcing:projections:run', ['projection' => LoanBookProjection::NAME]);

    expect([$loan->repaidMinor(), $loan->outstanding()->minorUnits, $loan->repayments()])->toBe([20000, 30000, 1])
        ->and(DB::table(LoanBookProjection::TABLE)->where('loan_id', 'loan-legacy')->value('repaid_minor'))->toEqual(20000)
        ->and(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_id', 'loan-legacy')->where('event_type', 'loan.repaid')->value('event_version'))->toEqual(1);
```

La fila de `firefly_eventsourcing_events` sigue diciendo versión 1; el préstamo y la cartera de préstamos leen los dos 200,00 amortizados. Los upcasters se encadenan por tipo y versión (`v1 → v2 → v3`), gana el de mayor `priority()` entre dos para el mismo paso, y un evento cuya versión sigue sin ser la registrada tras el upcasting se rechaza indicando el upcaster que le falta — el primer test de ese fichero falla exactamente así hasta que existe `LoanRepaidV1Upcaster`.

---

## Publicar a través del outbox, en la misma transacción

Otros servicios quieren enterarse de que un préstamo se ha amortizado. El outbox del Capítulo 8 ya resuelve "publicar solo lo que se confirmó, y no perderlo nunca"; el almacén de eventos se enchufa a él:

<!-- source: skeleton/config/firefly.php -->
```php
'publish' => [
    'enabled' => env('FIREFLY_EVENTSOURCING_PUBLISH_ENABLED', false),
    'destination' => env('FIREFLY_EVENTSOURCING_PUBLISH_DESTINATION', 'eventsourcing.events'),
    'exclude-aggregate-types' => ['orchestration.execution'],
],
```

Con `firefly.eventsourcing.publish.enabled` activado y `firefly.eda.provider=database` (o `postgres`), cada evento añadido se escribe en `firefly_eda_outbox` *dentro de la transacción del append*, con sus cabeceras `x-event-id`, `x-event-version`, `x-aggregate-id`, `x-aggregate-type`, `x-aggregate-version` y `x-occurred-at`. El arnés de outbox de Lumen publica los préstamos en `lumen.loans`:

<!-- source: samples/lumen/tests/EventSourcing/LoanOutboxPublicationTest.php -->
```php
    expect(DB::table(OutboxSchema::TABLE)->where('destination', LumenEventSourcingOutboxTestCase::DESTINATION)->orderBy('id')->pluck('event_type')->all())
        ->toBe(['loan.granted', 'loan.repaid'])
        ->and(DB::table(OutboxSchema::TABLE)->where('destination', 'wallet.events')->orderBy('id')->pluck('event_type')->all())
        ->toBe(['WalletOpened', 'FundsDeposited', 'FundsWithdrawn'])
        ->and(is_string($headers) ? json_decode($headers, true) : null)->toHaveKey('x-aggregate-id', $loanId)
        ->and(DB::table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PENDING)->count())->toBe(5);
```

Dos orígenes, una tabla, una transacción: los eventos del préstamo vienen del almacén de eventos, los del monedero del hook previo al commit de `firefly/data`, y se confirman o se revierten juntos — el segundo test del fichero repite la amortización sin fondos y encuentra solo `loan.granted` en el outbox. Después, `php artisan firefly:eda:consume` entrega los cinco, al menos una vez, exactamente como en el Capítulo 8. El almacén de eventos y el outbox deben compartir conexión, o el arranque se rechaza nombrando ambas claves. Con cualquier otro proveedor de eda los eventos se publican después de que el append se confirme, como mucho una vez; cuando cada evento tiene que llegar al broker, usa el outbox.

---

## Orquestación sobre el almacén de eventos

Los puntos de control del Capítulo 9A iban a `memory`, a `cache` o a tres tablas portables. `firefly/eventsourcing` añade un cuarto proveedor de persistencia, `event-sourced`, que guarda cada ejecución de saga, TCC y workflow como un agregado en el almacén de eventos:

<!-- illustrative: an application's own .env file -->
```dotenv
FIREFLY_EVENTSOURCING_STORE=database
FIREFLY_ORCHESTRATION_PERSISTENCE=event-sourced
```

Nada cambia en la saga. Lumen ejecuta sobre él la misma saga de pagos salientes:

<!-- source: samples/lumen/tests/EventSourcing/EventSourcedPayoutTest.php -->
```php
    $result = $this->app()->make(SagaEngine::class)->execute('payout', ['walletId' => $walletId, 'iban' => 'ES9121000418450200051332', 'amountMinor' => 20000]);
    $stream = $this->app()->make(EventStore::class)->load($result->correlationId);

    expect($result->success)->toBeTrue()
        ->and($this->app()->make(ExecutionPersistenceProvider::class))->toBeInstanceOf(EventSourcedExecutionPersistenceProvider::class)
        ->and($stream->aggregateType())->toBe('orchestration.execution')
        ->and($stream->events[0]->eventType)->toBe('orchestration.execution.started')
        ->and(DB::table(OrchestrationSchema::EXECUTIONS)->where('correlation_id', $result->correlationId)->value('status'))->toBe('COMPLETED')
        ->and($this->queries()->ask(new GetBalance($walletId)))->toBe(30000);
```

El flujo de la ejecución empieza con `orchestration.execution.started` y registra cada punto de control como los eventos que lo cambiaron, así que una reproducción devuelve exactamente el estado que se guardó. Las consultas que ejecutan los motores — ejecuciones en curso, obsoletas y vencidas, atributos de búsqueda — siguen leyendo las propias tablas de orquestación, ahora una proyección *inline* escrita en la misma transacción que los eventos; `php artisan firefly:eventsourcing:projections:rebuild orchestration.executions` las vuelve a escribir a partir de los flujos, que es lo que hace el segundo test del fichero después de borrarlas. Las ejecuciones quedan excluidas de la publicación por defecto (`exclude-aggregate-types`).

---

## En cada base de datos

Todo lo anterior se ejecuta sobre la base de datos SQLite `:memory:` del ejemplo en la puerta por defecto, y de nuevo sobre servidores reales en las vías de integración del Capítulo 12: `LoanLedgerRoundTripTest` concede, amortiza y liquida un préstamo con su instantánea, sus filas del outbox y su cartera de préstamos, y lee una amortización de la versión 1, en PostgreSQL, MySQL, MariaDB, SQL Server y un fichero SQLite; `EventSourcedPayoutRoundTripTest` hace lo mismo para el pago saliente con event sourcing. Ejecuta una vía con su variable DSN definida y `composer test:integration -- samples/lumen/tests/Integration`. La numeración en orden de commit es lo que esas vías ponen más a prueba: usa `READ COMMITTED` en MySQL y MariaDB y `READPAST` en SQL Server para que un append abierto ni se vea ni haga esperar.

!!! laravel "Paridad con Laravel"
    Los eventos de Laravel son notificaciones: `Event::dispatch()` no deja nada detrás salvo que un listener lo anote, y un modelo de Eloquent solo conserva la última versión de su fila. Un registro de auditoría es una segunda tabla que mantienes al día a mano. `firefly/eventsourcing` lo invierte para los agregados que lo necesitan — los eventos son el registro, la fila es una proyección que puedes borrar y reconstruir — mientras Eloquent sigue siendo exactamente lo que es para todo lo demás, en la misma transacción.

---

## Lo que aprendiste {.recap}

| Concepto | Qué hace |
|---|---|
| `EventSourcedAggregateRoot` / `recordThat()` | El estado es la reproducción de los eventos registrados; los métodos `apply…()` son los únicos que escriben |
| `#[EventType]` | Un nombre guardado estable y una versión de la carga útil, compilados por `firefly:cache` |
| `EventSourcedRepository` | `get()` reproduce, `save()` añade bajo la versión cargada — un escritor desfasado recibe un 409 `ConcurrencyException` |
| `SnapshotAware` | Una instantánea cada `firefly.eventsourcing.snapshot.threshold` eventos; una carga reproduce solo lo que vino después |
| `Projection` + punto de control | Un modelo de lectura a partir del flujo global en orden de commit, filas y punto de control en una transacción, bajo un arrendamiento |
| `EventUpcaster` | Hace avanzar una versión antigua de un evento al leer, en el almacén, igual para repositorios que para proyecciones |
| `firefly.eventsourcing.publish.enabled` | Escribe cada evento añadido en el outbox dentro de la transacción del append |
| Orquestación `event-sourced` | Ejecuciones de saga, TCC y workflow como flujos de eventos, con las tablas de orquestación como su modelo de lectura inline |

---

## Ponlo en práctica {.exercises}

1. **Liquida de una vez.** Añade a `LoanCommandsTest` un test que amortice un préstamo entero con un solo `RepayLoan` y compruebe que ese único guardado añadió dos eventos — `loan.repaid` y `loan.settled` — y luego comprueba si ese guardado cruzó el umbral de instantáneas.
2. **Una tercera versión.** Imagina que la versión 3 de `loan.repaid` añade un campo `channel` cuyo valor por defecto es `wallet`. Escribe el upcaster de la versión 2 a la 3, sube la versión de `#[EventType]` y confirma con `LoanRepaidUpcasterTest` que una fila de la versión 1 recorre ahora `v1 → v2 → v3` al leerse.
3. **Un segundo modelo de lectura.** Escribe una proyección que cuente las amortizaciones por monedero en una tabla propia, ejecuta `php artisan firefly:eventsourcing:projections:run` y después reconstruye solo esa con `php artisan firefly:eventsourcing:projections:rebuild` — el punto de control de la cartera de préstamos no se mueve.
