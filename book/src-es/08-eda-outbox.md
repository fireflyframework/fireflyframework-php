<span class="eyebrow">Parte III — Coordinar y Asegurar la Aplicación · Capítulo 8</span>

# Arquitectura Guiada por Eventos y el Outbox Transaccional {.chtitle}

Al terminar este capítulo conocerás las dos superficies de eventos, deliberadamente distintas, que distribuye LaraFly y por qué confundirlas es el error más común de todos; el puerto `EventPublisher` y el `EventEnvelope` que transporta; cómo `#[EventListener]` compara con el **nombre de tipo** de un evento — no con su destino — un tropiezo real que sufrió la construcción de `samples/lumen` al conectar `LedgerProjector` en el Capítulo 6; los adaptadores en memoria y de cola; el modelo de reintento/carta-muerta en el que se envuelve todo listener; y — cerrando la brecha de durabilidad que deja abierta el puente dominio→integración — el genuino **outbox de la misma transacción** que `firefly/eda-postgres` escribe de forma atómica junto con el agregado que lanzó el evento.

!!! note "Término nuevo: evento de integración"
    El Capítulo 6 construyó **eventos de dominio** — `WalletOpened`, `FundsDeposited` — hechos que un agregado lanza para consumidores dentro del *mismo* proceso y el *mismo* desplegable. Un **evento de integración** es el mismo hecho reemitido a través de una frontera de proceso o de servicio: un envoltorio con forma de JSON con un nombre de tipo, una carga útil y encabezados de enrutamiento, transportado por un broker (o, en proceso, por ese mismo bus). Este capítulo trata de la maquinaria al otro lado de esa frontera, y del puente que lleva un evento de dominio hasta ella.

---

## Dos superficies de eventos — no una

LaraFly distribuye dos mecanismos de eventos sin relación entre sí que resultan tener un nombre de atributo de aspecto parecido:

| Superficie | Atributo | Se suscribe a | Entrega | Paquete |
|---|---|---|---|---|
| Eventos de aplicación en proceso | `#[AsEventListener]` | una **clase** de evento PHP | síncrona, mismo proceso, sin broker | `firefly/context` |
| Bus broker de EDA | `#[EventListener]` | un **patrón** de tipo de evento (glob al estilo fnmatch, p. ej. `'user.*'`) | en memoria (síncrona) o en cola (asíncrona) | `firefly/eda` |

`#[AsEventListener]` se dispara cuando el código de la aplicación llama a `ApplicationEventPublisher::publish(object $event)` con un objeto PHP tipado — el mismo mecanismo sobre el que cabalga el despacho post-confirmación del Capítulo 6 (`DomainEventDispatcher` llama exactamente a esto para publicar un `DomainEvent` drenado). `#[EventListener]`, el tema de este capítulo, se dispara cuando llega al bus de **eda** un `EventEnvelope` cuya cadena `eventType` coincide con tu patrón — en memoria, a través de un worker de cola, o (la segunda mitad de este capítulo) a través de una fila del outbox de Postgres. Ningún paquete depende del otro; nada en `firefly/eda` importa siquiera el `Dispatcher` de Illuminate.

---

## El puerto `EventPublisher` y el `EventEnvelope`

Todo en este capítulo pasa por un único puerto pequeño:

```php
interface EventPublisher
{
    public function subscribe(string $eventTypePattern, callable $handler): void;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void;

    public function start(): void;

    public function stop(): void;
}
```

`subscribe()` toma un patrón al estilo fnmatch (`'user.*'`, `'order.created'`, un `'*'` desnudo); `publish()` toma una cadena de destino, una cadena de tipo de evento, una carga útil y encabezados opcionales, y construye el envoltorio que recibe cada suscriptor que coincida:

```php
final readonly class EventEnvelope
{
    public function __construct(
        public string $eventType,
        public string $destination,
        public array $payload = [],
        public array $headers = [],
        ?string $eventId = null,
        ?DateTimeImmutable $timestamp = null,
    ) {}

    public string $eventId;          // uuid4, random_bytes-derived — no ramsey/uuid, no reflection
    public DateTimeImmutable $timestamp;
}
```

`eventType` y `destination` son dos cosas genuinamente distintas, y el resto de este capítulo gira sobre esa distinción: `destination` es *hacia dónde* se enruta el envoltorio en el cable (un nombre de cola, la columna `destination` de una fila del outbox de Postgres); `eventType` es *qué hecho transporta*, y es el **único** campo contra el que se compara jamás un patrón de `#[EventListener]`.

`withHeaders(array $extra): self` devuelve una copia con los encabezados fusionados — usado internamente para estampar `x-correlation-id`, y (lo verás de nuevo más abajo) `x-original-topic`/`x-exception` en un envoltorio enviado a la carta muerta. `start()`/`stop()` son no-ops en los adaptadores en memoria y de cola; el propio consumidor del outbox (más adelante en este capítulo) es donde empiezan a hacer trabajo real.

---

## `#[EventListener]` y el tropiezo de la coincidencia por eventType

```php
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class EventListener
{
    /** @var list<string> */
    public readonly array $patterns;

    /**
     * @param  string|array<int, string>  $patterns
     */
    public function __construct(string|array $patterns = [], public readonly int $order = 0)
    {
        $this->patterns = is_string($patterns) ? [$patterns] : array_values($patterns);
    }
}
```

`#[EventListener]` es **metadato inerte y nada más**, la misma regla que todo atributo de Firefly — un único patrón de cadena se normaliza a una lista de un elemento, y `IS_REPEATABLE` permite que un método lleve varias suscripciones, cada una con su propio orden. La suscripción en sí ocurre dentro del registro compartido que componen ambos adaptadores distribuidos:

```php
final class SubscriberRegistry
{
    /** @var list<array{pattern: string, handler: callable}> */
    private array $subscribers = [];

    public function subscribe(string $pattern, callable $handler): void
    {
        $this->subscribers[] = ['pattern' => $pattern, 'handler' => $handler];
    }

    public function deliver(EventEnvelope $envelope): void
    {
        foreach ($this->subscribers as $subscriber) {
            if (fnmatch($subscriber['pattern'], $envelope->eventType)) {
                ($subscriber['handler'])($envelope);
            }
        }
    }
}
```

Lee con atención esa llamada a `fnmatch()`: compara `$subscriber['pattern']` contra `$envelope->eventType` — **nunca** contra `$envelope->destination`. Este es exactamente el tropiezo que sufrió la construcción de `samples/lumen` al conectar `LedgerProjector` en el Capítulo 6, y merece la pena repetirlo aquí en su totalidad porque es la forma más fácil de conectar un listener que jamás se dispara en silencio. Todo evento de dominio del monedero lleva `#[PublishDomainEvent('wallet.events')]` — esa cadena es el **destino** al que se enruta el evento una vez que cruza al mundo de los eventos de integración. El propio docblock de `LedgerProjector` enuncia la regla como una advertencia exactamente por este motivo:

```php
#[Component]
final class LedgerProjector
{
    /**
     * The #[EventListener] MUST enumerate the event TYPE names, not the #[PublishDomainEvent('wallet.events')] DESTINATION:
     * SubscriberRegistry::deliver() calls fnmatch($pattern, $envelope->eventType), matching the pattern against the
     * eventType (the short class name, e.g. 'FundsDeposited') and NEVER against the destination. A 'wallet.*'-style pattern
     * would therefore never match any wallet event and this projector would silently never fire.
     */
    #[EventListener(['WalletOpened', 'FundsDeposited', 'FundsWithdrawn', 'TransferCompleted'])]
    public function onWalletEvent(EventEnvelope $envelope): void
    {
        // ...
    }
}
```

Un tentador `#[EventListener(['wallet.*'])]` — razonando por analogía con la cadena de destino — compila, se despliega, y luego no invoca ni una sola vez `onWalletEvent()`, porque ningún `eventType` de un evento del monedero (`'FundsDeposited'`, `'WalletOpened'`, …) empieza jamás por `'wallet.'`; esa palabra solo aparece en el *destino*. El Capítulo 6 te mostró el código fuente completo de `LedgerProjector` y sus consecuencias para el modelo de lectura; este capítulo es donde vive de verdad la regla de la que depende.

!!! warning "`eventType`, nunca `destination`"
    Cada vez que escribas un patrón de `#[EventListener]`, pregúntate "¿este glob coincide con el nombre corto de clase del evento que quiero, y no con la cadena de cola/tema por la que resulta enrutarse?" Las dos son cadenas sin relación fáciles de confundir precisamente porque a menudo *parecen* relacionadas (el destino `'wallet.events'`, el tipo de evento `'WalletOpened'`) — `fnmatch()` solo ve jamás la segunda.

Una segunda ilustración mínima, tomada de la propia documentación de `firefly/eda`, concreta el sitio de llamada, patrón y publicación imperativa juntos:

```php
use Firefly\Container\Attributes\Component;
use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;

#[Component]
final class OrderNotifier
{
    #[EventListener('order.*')]
    public function onOrderEvent(EventEnvelope $envelope): void
    {
        // $envelope->eventType is e.g. "order.placed"; $envelope->payload carries the data.
    }
}

final class OrderService
{
    public function __construct(private readonly EventPublisher $events) {}

    public function place(int $orderId): void
    {
        $this->events->publish('firefly.events', 'order.placed', ['id' => $orderId]);
    }
}
```

Aquí el destino (`'firefly.events'`) y el tipo de evento (`'order.placed'`) son cadenas deliberadamente distintas, y el patrón `'order.*'` coincide con la **segunda** — precisamente para que este ejemplo no pueda confundirse con evidencia de que los patrones alguna vez miran el destino.

`EventListenerScanner` (`packages/eda/src/Scanner/EventListenerScanner.php`) es el único sitio de reflexión en `firefly/eda`, recorre una vez las raíces PSR-4 de la aplicación y compila cada método `#[EventListener]` en un `EventListenerDescriptor`; `EventListenerManifest` carga el resultado sin reflexión alguna, exactamente el mismo idioma de `HandlerManifest` que te mostró el Capítulo 7. `firefly:cache` ejecuta este escáner como uno de sus doce pares, escribiendo `bootstrap/cache/firefly/event-listeners.php`; `FireflyCacheServiceProvider` lo vincula, sustituyendo el valor por defecto vacío con el que arrancaría una aplicación sin caché. `EventListenerWiringPass` recorre después ese manifiesto al arrancar — en *todo* proceso, tanto en la petición web como en el worker de cola — envuelve cada invocación de destino en el decorador de reintento/DLQ de más abajo, y llama a `$bus->subscribe($pattern, $wrapped)` por cada patrón, resolviendo el bean de destino **fresco desde el contenedor en cada despacho**.

---

## Reintento y carta muerta: todo listener se envuelve, siempre

`RetryingEventHandler::wrap()` decora *todo* manejador suscrito — deliberadamente no hay una ruta rápida sin envolver:

```php
final class RetryingEventHandler
{
    public static function wrap(callable $handler, int $retries, float $retryDelay, ?DeadLetterStore $dlq): Closure
    {
        return static function (EventEnvelope $envelope) use ($handler, $retries, $retryDelay, $dlq): void {
            $attempt = 0;

            while (true) {
                try {
                    $handler($envelope);

                    return;
                } catch (Throwable $e) {
                    $attempt++;

                    if ($attempt > $retries) {
                        if ($dlq === null) {
                            throw $e;
                        }

                        $dlq->store(
                            $envelope->withHeaders([
                                'x-original-topic' => $envelope->destination,
                                'x-exception' => $e->getMessage(),
                            ]),
                            $e,
                        );

                        return;
                    }

                    if ($retryDelay > 0.0) {
                        usleep((int) ($retryDelay * $attempt * 1_000_000));
                    }
                }
            }
        };
    }
}
```

Un lanzamiento se reintenta hasta `firefly.eda.retries` veces más con retroceso **lineal** (el intento *N* duerme `retryDelay * N` segundos); en el fallo final, un `DeadLetterStore` vinculado recibe el envoltorio — enriquecido con `x-original-topic`/`x-exception` — y el fallo se traga; sin ningún almacén vinculado, la excepción original se relanza sin cambios. Con `retries === 0` y sin DLQ, el manejador igual se ejecuta a través del envoltorio, solo una vez, el mismo resultado observable que una llamada sin envolver. `InMemoryDeadLetterStore` — una lista en proceso, de solo-anexado, de `DeadLetterEntry` (envoltorio + clase de excepción + mensaje) — es el valor por defecto de `#[ConditionalOnMissingBean]`: nada se pierde en silencio, pero tampoco nada sobrevive al proceso.

!!! note "Dos capas de reintento independientes"
    En el adaptador `QueueEventBus`, la propia maquinaria de reintento a nivel de trabajo/`failed_jobs` de Laravel gobierna el trabajo de **entrega del envoltorio** en sí. `RetryingEventHandler` gobierna si el **propio fallo del manejador** de un `#[EventListener]` individual se reintenta y se envía a la carta muerta — de forma completamente independiente, en ambos adaptadores. Un trabajo que se entrega con éxito pero cuyo listener lanza una excepción es asunto de `RetryingEventHandler`, no de reintento de cola.

---

## En memoria y en cola: los dos adaptadores de M9

`InMemoryEventBus` es el valor por defecto del esqueleto (`firefly.eda.provider` sin fijar, o `memory`) — síncrono, cero servicios externos:

```php
final class InMemoryEventBus implements EventPublisher
{
    public function __construct(private readonly SubscriberRegistry $registry) {}

    public function subscribe(string $eventTypePattern, callable $handler): void
    {
        $this->registry->subscribe($eventTypePattern, $handler);
    }

    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        $this->registry->deliver(new EventEnvelope($eventType, $destination, $payload, $headers));
    }

    public function start(): void {}

    public function stop(): void {}
}
```

`publish()` construye el envoltorio y llama a `deliver()` **de forma síncrona** — todo manejador que coincida ya se ha ejecutado para cuando `publish()` retorna. `QueueEventBus` (`firefly.eda.provider=queue`) mantiene el mismo contrato `EventPublisher` idéntico pero hace que `publish()` sea de disparar-y-olvidar:

```php
final class QueueEventBus implements EventPublisher
{
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        $job = (new DispatchEventJob(new EventEnvelope($eventType, $destination, $payload, $headers)))
            ->onConnection($this->connection)
            ->onQueue($this->queue);

        // Resolved EVERY call so Bus::fake() intercepts — never hoist into the constructor.
        $this->container->make(Dispatcher::class)->dispatch($job);
    }

    public function deliver(EventEnvelope $envelope): void
    {
        $this->registry->deliver($envelope);
    }
}
```

`publish()` retorna antes de que ningún listener se haya ejecutado — la entrega ocurre en el worker de cola que sea que recoja el `DispatchEventJob`. El propio arranque de ese worker vuelve a poblar su `SubscriberRegistry` a partir del mismo manifiesto compilado que lee `EventListenerWiringPass` en todas partes, así que un worker que no comparte nada reconstruye el conjunto idéntico de suscriptores cada vez que arranca. Bajo el driver de cola `sync`, la entrega colapsa al mismo comportamiento síncrono que `InMemoryEventBus` — que es exactamente cómo una prueba ejerce la ruta asíncrona sin que haya ningún worker realmente en ejecución.

---

## El puente de evento de dominio a evento de integración

El Capítulo 6 te mostró el *efecto* del puente — un `WalletOpened` confirmado llegando a `LedgerProjector` como un `EventEnvelope`. Aquí está el mecanismo. Todo `DomainEvent` confirmado a través del despacho post-confirmación de `firefly/data` (la sección de cierre del Capítulo 6) se publica también, en proceso, a `ApplicationEventPublisher` — la propia superficie `#[AsEventListener]` con la que este capítulo abrió al contrastarla con `#[EventListener]`. Como el propio dispatcher de Illuminate compara un evento objeto solo por su **clase concreta** (más las interfaces, nunca las clases padre), un único `#[AsEventListener]` tipado sobre la clase base abstracta `DomainEvent` nunca podría dispararse para ninguna subclase concreta. `DomainEventBridgeWiringPass` esquiva exactamente esta limitación con un único listener comodín (*wildcard*) protegido:

```php
final class DomainEventBridgeWiringPass implements BootPass
{
    public function run(BootContext $context): void
    {
        $container = $context->container;
        $dispatcher = $container->make('events');

        $dispatcher->listen('*', DispatcherEventPublisher::guardListener(
            static function (string $eventName, array $payload) use ($container): void {
                $event = $payload[0] ?? null;
                if ($event instanceof DomainEvent) {
                    $container->make(DomainEventBridge::class)->publish($event);
                }
            },
        ));
    }
}
```

`DomainEventBridge::publish()` mapea el `DomainEvent` sobre el puerto `EventPublisher` de eda a través de `EdaCommandEventPublisher`: `eventType` = `$event->eventType()` (el nombre corto de la clase — la misma cadena contra la que comparan los patrones de `#[EventListener]`), `payload` = los campos públicos del evento vía `get_object_vars()`, y `destination` = una anulación explícita, si no el `#[PublishDomainEvent(destination:)]` del evento (`'wallet.events'` para todo evento del monedero), si no `firefly.cqrs.default_destination` (`'cqrs.events'`). El id de correlación activo (el `CorrelationContext` del Capítulo 7) se estampa en el encabezado `x-correlation-id` del envoltorio.

```php
final class DomainEventBridge
{
    public function publish(DomainEvent $event): void
    {
        $eventClass = $event::class;

        try {
            $this->publisher->publish($event);
        } catch (Throwable $e) {
            if ($this->failureStrategy === EventFailureStrategy::Raise) {
                throw $e instanceof CommandProcessingException ? $e : new CommandProcessingException($eventClass, $e);
            }

            $this->logger?->error(
                "CQRS domain-event bridge failed to publish [{$eventClass}]: {$e->getMessage()}",
                ['exception' => $e],
            );
        }
    }
}
```

Esto se ejecuta **después** de que la propia transacción del agregado ya ha confirmado — la escritura ya tuvo éxito para cuando se ejecuta este código. `firefly.cqrs.event_failure_strategy` (por defecto `log`) decide qué ocurre si la publicación al broker falla a continuación: `log` traga el fallo y lo registra — el resultado del comando se mantiene; la publicación de integración fue de mejor esfuerzo. `raise` la relanza, envuelta en `CommandProcessingException`, exponiendo el fallo post-confirmación al llamador original aunque la escritura subyacente ya sea duradera. En cualquier caso, el puente se dispara **solo** para una unidad de trabajo genuinamente confirmada: una transacción revertida no lanza ningún `DomainEvent` en absoluto (Laravel descarta los callbacks de `afterCommit` en un rollback), así que jamás se publica nada para ella — la misma garantía que el modelo post-confirmación del Capítulo 6 ya te dio para los listeners en proceso, ahora extendida a través de la frontera del proceso.

::: figure art/figures/cqrs-eda-bridge.svg | Figura 8.1 — El puente dominio-a-integración: un listener comodín protegido sobre el dispatcher en proceso captura todo DomainEvent confirmado y lo republica a través del CommandEventPublisher resuelto.

Cuando no hay ningún `EventPublisher` de `firefly/eda` vinculado en absoluto, `CqrsAutoConfiguration` recae en `NoOpEventPublisher`, que descarta el evento (registrándolo opcionalmente a nivel debug) — instalar `firefly/cqrs` sin `firefly/eda` sigue arrancando limpiamente y simplemente no emite eventos de integración.

**La brecha que esto deja abierta.** La escritura confirma; *después*, en un paso separado, se intenta la publicación al broker. Entre esos dos pasos hay una ventana real — un proceso caído, una caída del broker — en la que el propio estado de la base de datos del agregado ya avanzó pero el evento de integración que describía ese avance nunca se envió. Esta es una garantía honesta, al-menos-una-vez-*ish*, no atómica, adecuada para muchísimas aplicaciones y explícitamente inadecuada para movimientos de dinero sobre los que otros servicios deben reaccionar de forma fiable. Cerrar esa brecha es exactamente lo que construye el resto de este capítulo.

---

## El genuino outbox de la misma transacción (`firefly/eda-postgres`)

`firefly/eda-postgres` cierra la brecha escribiendo la fila del outbox **dentro** de la propia transacción abierta del agregado, en la propia conexión del agregado — así que confirma o revierte de forma atómica junto con el agregado, sin doble escritura y sin nada que "esperar" después del hecho.

::: figure art/figures/outbox-flow.svg | Figura 8.2 — El outbox de la misma transacción: la propia confirmación del agregado y el INSERT del outbox son una unidad atómica; un consumidor reclama filas PENDING de forma independiente.

### El mecanismo: `PreCommitEventHook`

El Capítulo 6 mostró a `DomainEventDispatcher::dispatchAfterCommit()` drenando el `AggregateTracker` y planificando cada evento vía `DB::afterCommit()`. Lleva un colaborador más, opcional — un hook que se ejecuta **antes** de esa planificación, mientras la transacción sigue abierta:

```php
interface PreCommitEventHook
{
    public function handle(object $event, ?string $connection = null): void;
}

final class DomainEventDispatcher
{
    public function __construct(
        private readonly AggregateTracker $tracker,
        private readonly ApplicationEventPublisher $publisher,
        private readonly ?PreCommitEventHook $preCommitHook = null,
    ) {}

    private function afterCommit(object $event, ?string $connection): void
    {
        // SP-4 same-tx seam: when bound, write the event to the outbox WITHIN the still-open tx (this method runs
        // during TransactionTemplate's pre-commit drain), so it commits/rolls back atomically with the aggregate.
        $this->preCommitHook?->handle($event, $connection);

        DB::connection($connection)->afterCommit(function () use ($event): void {
            $this->publisher->publish($event);
        });
    }
}
```

Sin ningún hook vinculado — sin `firefly/eda-postgres`, o con cualquier proveedor que no sea `postgres` — esto es un no-op completo y `DomainEventDispatcher` se comporta exactamente como describió el Capítulo 6. `OutboxPreCommitHook` de `firefly/eda-postgres` es la implementación que lo activa:

```php
final class OutboxPreCommitHook implements PreCommitEventHook
{
    public function handle(object $event, ?string $connection = null): void
    {
        if (! $event instanceof DomainEvent) {
            return;
        }

        $conn = $this->connections->connection($connection); // the aggregate's OWN connection — carries the open tx
        $emitNotify = $conn instanceof Connection && $conn->getDriverName() === 'pgsql';
        $publisher = new PostgresEventPublisher($conn, $this->channel, $emitNotify);

        (new EdaCommandEventPublisher($publisher, $this->defaultDestination, $this->destinations, $this->correlation))
            ->publish($event);
    }
}
```

`$connection` se propaga sin cambios desde el dispatcher, así que la fila del outbox de un agregado `#[Transactional(connection: 'x')]` aterriza en la conexión `x` — nunca un valor por defecto fijo — y el hook reutiliza el *mismo* mapeo de `EdaCommandEventPublisher` (tipo de evento, carga útil, destino, id de correlación) que usa el puente post-confirmación, así que la fila que esto escribe es idéntica byte a byte en forma a lo que habría producido el puente ordinario.

### El INSERT, y `pg_notify` en la misma transacción

```php
final class PostgresEventPublisher implements EventPublisher
{
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        $id = $this->connection->table(OutboxSchema::TABLE)->insertGetId([
            'destination' => $destination,
            'channel' => $this->channel,
            'event_type' => $eventType,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'headers' => json_encode($headers === [] ? new stdClass : $headers, JSON_THROW_ON_ERROR),
            'transaction_id' => $headers['x-correlation-id'] ?? null,
            'status' => OutboxSchema::STATUS_PENDING,
            'attempts' => 0,
            'created_at' => $this->connection->raw('CURRENT_TIMESTAMP'),
        ]);

        if ($this->emitNotify) {
            // In-tx pg_notify: Postgres queues it and delivers on COMMIT, so the consumer's LISTEN wakes exactly
            // when the PENDING row becomes visible. (sqlite: emitNotify=false.)
            $this->connection->statement('SELECT pg_notify(?, ?)', [$this->channel, (string) $id]);
        }
    }
}
```

Como `publish()` se ejecuta sobre **cualquiera que sea la conexión con la que se construyó**, y esa conexión está en medio de una transacción cuando `OutboxPreCommitHook` la llama, el `INSERT` se alista en la propia transacción del agregado: confirma cuando el agregado confirma, y revierte cuando el agregado revierte. Cuando el driver es `pgsql`, la misma sentencia también dispara `SELECT pg_notify(?, ?)` — **dentro de la misma transacción**. Postgres encola un `NOTIFY` lanzado dentro de una transacción y solo lo entrega de verdad una vez que esa transacción confirma, así que un consumidor a la escucha se despierta con baja latencia en el instante exacto en que la fila se vuelve visible para otras conexiones — y nunca se despierta para una fila que termina revertida.

La tabla del outbox (`firefly_eda_outbox`, un único esquema compartido por la migración, el publicador, el consumidor y el relay) lleva: `id, destination, channel, event_type, payload, headers, transaction_id, status, attempts, error_message, created_at, processed_at, failed_at`, con `status` siendo uno de `PENDING` / `PUBLISHED` / `FAILED`.

Una prueba genuina y distribuida demuestra ambas mitades de la garantía directamente contra una transacción real:

```php
it('writes the outbox row INSIDE the caller transaction (present after commit)', function () {
    $publisher = new PostgresEventPublisher(DB::connection(), 'firefly_eda_events');

    DB::transaction(function () use ($publisher): void {
        $publisher->publish('users', 'user.created', ['id' => 1], ['x-a' => 'b']);
    });

    expect(DB::table(OutboxSchema::TABLE)
        ->where('event_type', 'user.created')
        ->where('status', OutboxSchema::STATUS_PENDING)
        ->count())->toBe(1);
});

it('rolls the outbox row back WITH the aggregate (absent after rollback)', function () {
    $publisher = new PostgresEventPublisher(DB::connection(), 'firefly_eda_events');

    try {
        DB::transaction(function () use ($publisher): void {
            $publisher->publish('users', 'user.created', ['id' => 2]);
            throw new RuntimeException('business failure after the outbox write');
        });
    } catch (RuntimeException) {
        // expected
    }

    // The INSERT rolled back atomically with the aggregate's tx: no row survives.
    expect(DB::table(OutboxSchema::TABLE)->count())->toBe(0);
});
```

Ninguna cantidad de prosa sustituye a esa segunda prueba: la fila del outbox de la unidad de trabajo fallida simplemente no existe después, porque nunca fue una escritura separada para empezar — fue una sentencia más dentro de la misma transacción que se revirtió.

### Evitar la doble publicación

Con `provider=postgres` vinculado, tanto `OutboxPreCommitHook` (en-tx) *como* el `DomainEventBridge` post-confirmación ordinario podrían, en principio, intentar escribir el mismo evento en el outbox. `PostgresOutboxAutoConfiguration` lo evita en `#[Order(900)]` — por delante de los valores por defecto `#[Order(1000)]` de `firefly/data` y `firefly/cqrs` — vinculando, en conjunto, como un único paquete:

- su **propio** `DomainEventDispatcher`, portando el `OutboxPreCommitHook`, que gana el `#[ConditionalOnMissingBean(DomainEventDispatcher::class)]` de `DataAutoConfiguration`, y
- un `NoOpEventPublisher` como el `CommandEventPublisher`, que gana el `#[ConditionalOnMissingBean(CommandEventPublisher::class)]` de `CqrsAutoConfiguration` — silenciando **solo** el tramo post-confirmación de eda.

El resultado: un evento de dominio se escribe en el outbox **exactamente una vez**, en-tx, de forma atómica junto con el agregado. El despacho post-confirmación genérico (no de eda) `#[AsEventListener]` con el que abrió este capítulo queda completamente intacto — esos listeners en proceso siguen disparándose exactamente como antes. Los cuatro beans están además protegidos por `#[ConditionalOnProperty('firefly.eda.provider', 'postgres')]`, así que instalar el paquete sin seleccionarlo como proveedor activo es totalmente inerte.

### Reclamar filas: `PostgresEventConsumer`

Una vez que una fila es `PENDING`, `PostgresEventConsumer` es la ruta de entrega en proceso terminal, siempre disponible — `php artisan firefly:eda:consume` lo conduce como un worker de larga duración:

```php
final class PostgresEventConsumer implements EventConsumer
{
    public function subscribe(array $destinations): void
    {
        if ($this->connection->getDriverName() === 'pgsql') {
            $this->connection->getPdo()->exec('LISTEN '.$this->channel);
        }
    }

    public function poll(int $timeoutMs): ?ReceivedEnvelope
    {
        try {
            ($this->awaitNotification)($timeoutMs);
        } catch (\Throwable) {
            // NOTIFY is a low-latency optimization; on ANY failure, fall through to the durable claim below.
        }

        $pgsql = $this->connection->getDriverName() === 'pgsql';
        $query = $this->connection->table(OutboxSchema::TABLE)
            ->where('status', OutboxSchema::STATUS_PENDING)
            ->orderBy('id');
        if ($pgsql) {
            $query->lock('for update skip locked'); // claim — Laravel has no skipLocked() helper
        }
        $row = $query->first();

        if ($row === null) {
            return null;
        }

        return new ReceivedEnvelope(new EventEnvelope(
            OutboxRow::asString($row->event_type),
            OutboxRow::asString($row->destination),
            OutboxRow::asMap($row->payload),
            OutboxRow::asStringMap($row->headers),
        ), OutboxRow::asInt($row->id));
    }

    public function ack(ReceivedEnvelope $received): void
    {
        $this->connection->table(OutboxSchema::TABLE)
            ->where('id', $received->deliveryTag)
            ->where('status', OutboxSchema::STATUS_PENDING)
            ->update(['status' => OutboxSchema::STATUS_PUBLISHED, 'processed_at' => $this->connection->raw('CURRENT_TIMESTAMP')]);
    }
}
```

`poll()` primero espera brevemente (de mejor esfuerzo, protegido contra cualquier fallo) un `NOTIFY`, y luego — sin importar si llegó uno — reclama la fila `PENDING` más antigua con `FOR UPDATE SKIP LOCKED` en pgsql (un `ORDER BY id` sencillo bajo sqlite, para las pruebas). Fíjate en que **no hay ninguna llamada a `EventPublisher::publish()` en ningún lugar de esta clase** — lee y actualiza la tabla del outbox directamente, así que no hay un `INSERT` de vuelta ni riesgo de que un consumidor realimente su propia salida a la tabla de la que acaba de leer. `ConsumerLoop` entrega el envoltorio reclamado al mismo `SubscriberRegistry` al que ya se suscribieron tus manejadores de `#[EventListener]`, exactamente igual que hacen los adaptadores en memoria y de cola; el guardián `WHERE status = 'PENDING'` de `ack()` hace que la confirmación sea idempotente, y un lanzamiento del manejador llama en cambio a `nack()`, que incrementa `attempts` y o bien reinicia la fila a `PENDING` (reintento) o, pasado `firefly.eda.postgres.max_attempts` (3 por defecto), la marca `FAILED` in situ — no hay una tabla de carta muerta separada para Postgres; una fila `status='FAILED'` *es* la carta muerta, consultable en la misma tabla `firefly_eda_outbox`.

Esta es una ventana de estado `PENDING`→`PUBLISHED` **duradera**, no una marca de agua alta en memoria: un consumidor reiniciado retoma en la fila `PENDING` más antigua todavía pendiente y nunca reproduce una fila ya `PUBLISHED`. Un único worker consumidor da entrega en proceso exactamente-una-vez; ejecutar más de uno a la vez degrada a al-menos-una-vez (escribe tus listeners de forma idempotente si lo haces).

!!! laravel "Paridad con Laravel"
    Laravel puro no tiene ningún patrón de outbox transaccional de primera parte en absoluto — el idioma que tendrías que construir a mano es una tabla `outbox` más un comando planificado que la sondea, sin ninguna ayuda del framework para mantener el `INSERT` dentro de la misma transacción que tu escritura de dominio. `firefly/eda-postgres` es exactamente ese idioma, pero con la atomicidad garantizada por construcción (`PreCommitEventHook` se ejecuta mientras la transacción sigue abierta) en vez de dejada a la disciplina del desarrollador.

---

## Lo que aprendiste {.recap}

| Concepto | Qué hace |
|---|---|
| Dos superficies de eventos | `#[AsEventListener]` (en proceso, `firefly/context`) frente a `#[EventListener]` (bus broker, `firefly/eda`) — mecanismos sin relación |
| `EventPublisher` / `EventEnvelope` | El puerto del bus broker; `destination` es *hacia dónde*, `eventType` es *qué hecho* |
| `SubscriberRegistry::deliver()` | Compara `fnmatch($pattern, $envelope->eventType)` — **nunca** el destino; el tropiezo exacto que sufrió `LedgerProjector` |
| `RetryingEventHandler` | Envuelve siempre a todo listener: reintento con retroceso lineal, luego carta muerta (o relanzamiento sin almacén vinculado) |
| `InMemoryEventBus` / `QueueEventBus` | Adaptadores `EventPublisher` síncrono frente a respaldado-por-cola (disparar-y-olvidar), puerto idéntico |
| `DomainEventBridge` | El disparador post-confirmación que republica un `DomainEvent` confirmado como evento de integración — de mejor esfuerzo, no atómico |
| `OutboxPreCommitHook` / `PostgresEventPublisher` | Escribe la fila del outbox **dentro** de la propia transacción abierta del agregado — genuinamente atómico |
| `PostgresEventConsumer` | Reclama filas `PENDING` (`FOR UPDATE SKIP LOCKED` + `LISTEN`/`NOTIFY`), conduce directamente los manejadores de `#[EventListener]`, sin `INSERT` de vuelta |

---

## Ponlo en práctica {.exercises}

1. **Reproduce el tropiezo, deliberadamente.** En una copia de trabajo del proyecto, cambia los patrones de `#[EventListener]` de `LedgerProjector` a `['wallet.*']` y vuelve a ejecutar su suite de pruebas. Confirma que el proyector jamás se dispara en silencio y que nunca se escribe ninguna fila en `ledger_entries` — luego restaura los patrones originales de nombre de tipo y confirma que vuelven a pasar.
2. **Fuerza una carta muerta.** Escribe un pequeño manejador `#[EventListener]` que siempre lance una excepción, configura `firefly.eda.retries=2` y vincula un `InMemoryDeadLetterStore`, publica un evento que coincida, e inspecciona `DeadLetterStore::all()` después. Confirma que el `exceptionMessage` de la entrada coincide con lo que lanzó tu manejador.
3. **Demuestra tú mismo el rollback del outbox.** Usando una conexión Postgres (`@group('integration')`, según las propias convenciones de prueba del framework), reproduce los dos casos de `OutboxSameTransactionTest` que citó este capítulo — publicar-y-luego-confirmar y publicar-y-luego-lanzar — y confirma que la presencia o ausencia de la fila coincide con la descripción de este capítulo.
