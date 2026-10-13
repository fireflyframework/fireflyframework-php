<span class="eyebrow">Parte III — Coordinar y Asegurar la Aplicación · Capítulo 8</span>

# Arquitectura Guiada por Eventos y el Outbox Transaccional {.chtitle}

Al terminar este capítulo conocerás las dos superficies de eventos, deliberadamente distintas, que distribuye LaraFly y por qué confundirlas es el error más común de todos; el puerto `EventPublisher` y el `EventEnvelope` que transporta; cómo `#[EventListener]` compara con el **nombre de tipo** de un evento — no con su destino — un tropiezo real que sufrió la construcción de `samples/lumen` al conectar `LedgerProjector` en el Capítulo 6; los adaptadores en memoria y de cola; el modelo de reintento/carta-muerta en el que se envuelve todo listener; y — cerrando la brecha de durabilidad que deja abierta el puente dominio→integración — el genuino **outbox de la misma transacción** que `firefly/eda-database` escribe de forma atómica junto con el agregado que lanzó el evento, en cualquiera de las cinco bases de datos; cómo sus consumidores arriendan filas para que puedan correr tantos como quieras a la vez; el `firefly:outbox:relay` opcional; qué añade encima `firefly/eda-postgres`; adónde va una carta muerta cuando tiene que sobrevivir al proceso (`firefly:eda:dead-letters:replay`); y cómo `firefly/messaging` consume de Kafka y RabbitMQ (`firefly:messaging:consume`).

!!! note "Término nuevo: evento de integración"
    El Capítulo 6 construyó **eventos de dominio** — `WalletOpened`, `FundsDeposited` — hechos que un agregado lanza para consumidores dentro del *mismo* proceso y el *mismo* desplegable. Un **evento de integración** es el mismo hecho reemitido a través de una frontera de proceso o de servicio: un envoltorio con forma de JSON con un nombre de tipo, una carga útil y encabezados de enrutamiento, transportado por un broker (o, en proceso, por ese mismo bus). Este capítulo trata de la maquinaria al otro lado de esa frontera, y del puente que lleva un evento de dominio hasta ella.

---

## Dos superficies de eventos — no una

LaraFly distribuye dos mecanismos de eventos sin relación entre sí que resultan tener un nombre de atributo de aspecto parecido:

| Superficie | Atributo | Se suscribe a | Entrega | Paquete |
|---|---|---|---|---|
| Eventos de aplicación en proceso | `#[AsEventListener]` | una **clase** de evento PHP | síncrona, mismo proceso, sin broker | `firefly/context` |
| Bus broker de EDA | `#[EventListener]` | un **patrón** de tipo de evento (glob al estilo fnmatch, p. ej. `'user.*'`) | en memoria (síncrona) o en cola (asíncrona) | `firefly/eda` |

`#[AsEventListener]` se dispara cuando el código de la aplicación llama a `ApplicationEventPublisher::publish(object $event)` con un objeto PHP tipado — el mismo mecanismo sobre el que cabalga el despacho post-confirmación del Capítulo 6 (`DomainEventDispatcher` llama exactamente a esto para publicar un `DomainEvent` drenado). `#[EventListener]`, el tema de este capítulo, se dispara cuando llega al bus de **eda** un `EventEnvelope` cuya cadena `eventType` coincide con tu patrón — en memoria, a través de un worker de cola, o (la segunda mitad de este capítulo) a través de una fila del outbox. Ningún paquete depende del otro; nada en `firefly/eda` importa siquiera el `Dispatcher` de Illuminate.

---

## El puerto `EventPublisher` y el `EventEnvelope`

Todo en este capítulo pasa por un único puerto pequeño:

<!-- source: packages/eda/src/EventPublisher.php -->
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

<!-- source: packages/eda/src/EventEnvelope.php -->
```php
final readonly class EventEnvelope
{
    // …
    public function __construct(
        public string $eventType,
        public string $destination,
        public array $payload = [],
        public array $headers = [],
        ?string $eventId = null,
        ?DateTimeImmutable $timestamp = null,
    // …
    public DateTimeImmutable $timestamp;
// …
}
```

`eventType` y `destination` son dos cosas genuinamente distintas, y el resto de este capítulo gira sobre esa distinción: `destination` es *hacia dónde* se enruta el envoltorio en el cable (un nombre de cola, la columna `destination` de una fila del outbox); `eventType` es *qué hecho transporta*, y es el **único** campo contra el que se compara jamás un patrón de `#[EventListener]`.

`withHeaders(array $extra): self` devuelve una copia con los encabezados fusionados — usado internamente para estampar `x-correlation-id`, y (lo verás de nuevo más abajo) `x-original-topic`/`x-exception` en un envoltorio enviado a la carta muerta. `start()`/`stop()` son no-ops en los adaptadores en memoria y de cola; el propio consumidor del outbox (más adelante en este capítulo) es donde empiezan a hacer trabajo real.

---

## `#[EventListener]` y el tropiezo de la coincidencia por eventType

<!-- source: packages/eda/src/Attributes/EventListener.php -->
```php
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class EventListener
{
    /** @var list<string> */
    public readonly array $patterns;
    // …
    /**
     // …
     */
        // …
        $this->patterns = is_string($patterns) ? [$patterns] : array_values($patterns);
    // …
    }
}
```

`#[EventListener]` es **metadato inerte y nada más**, la misma regla que todo atributo de Firefly — un único patrón de cadena se normaliza a una lista de un elemento, y `IS_REPEATABLE` permite que un método lleve varias suscripciones, cada una con su propio orden. La suscripción en sí ocurre dentro del registro compartido que componen ambos adaptadores distribuidos:

<!-- source: packages/eda/src/Bus/SubscriberRegistry.php -->
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

<!-- source: samples/lumen/src/Application/Listener/LedgerProjector.php -->
```php
/**
 // …
 * The #[EventListener] MUST enumerate the event TYPE names, not the #[PublishDomainEvent('wallet.events')] DESTINATION:
 * SubscriberRegistry::deliver() calls fnmatch($pattern, $envelope->eventType), matching the pattern against the
 * eventType (the short class name, e.g. 'FundsDeposited') and NEVER against the destination. A 'wallet.*'-style pattern
 * would therefore never match any wallet event and this projector would silently never fire.
 */
    // …
    #[EventListener(['WalletOpened', 'FundsDeposited', 'FundsWithdrawn', 'TransferCompleted'])]
    public function onWalletEvent(EventEnvelope $envelope): void
    {
        // …
        $walletId = $envelope->payload['walletId'] ?? $envelope->payload['sourceWalletId'] ?? '';
        $amountMinor = $envelope->payload['amountMinor'] ?? 0;
        $balanceMinor = $envelope->payload['balanceMinor'] ?? 0;

        LedgerEntry::query()->create([
            'wallet_id' => is_string($walletId) ? $walletId : '',
            'event_type' => $envelope->eventType,
            'amount_minor' => is_int($amountMinor) ? $amountMinor : 0,
            'balance_minor' => is_int($balanceMinor) ? $balanceMinor : 0,
            'occurred_at' => now(),
        ]);
    }
}
```

Un tentador `#[EventListener(['wallet.*'])]` — razonando por analogía con la cadena de destino — compila, se despliega, y luego no invoca ni una sola vez `onWalletEvent()`, porque ningún `eventType` de un evento del monedero (`'FundsDeposited'`, `'WalletOpened'`, …) empieza jamás por `'wallet.'`; esa palabra solo aparece en el *destino*. El Capítulo 6 te mostró el código fuente completo de `LedgerProjector` y sus consecuencias para el modelo de lectura; este capítulo es donde vive de verdad la regla de la que depende.

!!! warning "`eventType`, nunca `destination`"
    Cada vez que escribas un patrón de `#[EventListener]`, pregúntate "¿este glob coincide con el nombre corto de clase del evento que quiero, y no con la cadena de cola/tema por la que resulta enrutarse?" Las dos son cadenas sin relación fáciles de confundir precisamente porque a menudo *parecen* relacionadas (el destino `'wallet.events'`, el tipo de evento `'WalletOpened'`) — `fnmatch()` solo ve jamás la segunda.

Una segunda ilustración mínima, tomada de la propia documentación de `firefly/eda`, concreta el sitio de llamada, patrón y publicación imperativa juntos:

<!-- illustrative: the reader's own #[EventListener] over an event their application publishes -->
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
        $this->events->publish('orders', 'order.placed', ['id' => $orderId]);
    }
}
```

Aquí el destino (`'orders'`) y el tipo de evento (`'order.placed'`) son cadenas deliberadamente distintas, y el patrón `'order.*'` coincide con la **segunda** — precisamente para que este ejemplo no pueda confundirse con evidencia de que los patrones alguna vez miran el destino.

`EventListenerScanner` (`packages/eda/src/Scanner/EventListenerScanner.php`) es el único sitio de reflexión en `firefly/eda`, recorre una vez las raíces PSR-4 de la aplicación y compila cada método `#[EventListener]` en un `EventListenerDescriptor`; `EventListenerManifest` carga el resultado sin reflexión alguna, exactamente el mismo idioma de `HandlerManifest` que te mostró el Capítulo 7. `firefly:cache` ejecuta este escáner como uno de sus catorce pares, escribiendo `bootstrap/cache/firefly/event-listeners.php`; `FireflyCacheServiceProvider` lo vincula, sustituyendo el valor por defecto vacío con el que arrancaría una aplicación sin caché. `EventListenerWiringPass` recorre después ese manifiesto al arrancar — en *todo* proceso, tanto en la petición web como en el worker de cola — envuelve cada invocación de destino en el decorador de reintento/DLQ de más abajo, y llama a `$bus->subscribe($pattern, $wrapped)` por cada patrón, resolviendo el bean de destino **fresco desde el contenedor en cada despacho**.

---

## Reintento y carta muerta: todo listener se envuelve, siempre

`RetryingEventHandler::wrap()` decora *todo* manejador suscrito — deliberadamente no hay una ruta rápida sin envolver:

<!-- source: packages/eda/src/DeadLetter/RetryingEventHandler.php -->
```php
final class RetryingEventHandler
{
    // …
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

<!-- source: packages/eda/src/Bus/InMemoryEventBus.php -->
```php
final class InMemoryEventBus implements EventPublisher
{
    // …
    public function subscribe(string $eventTypePattern, callable $handler): void
    {
        $this->registry->subscribe($eventTypePattern, $handler);
    }
    // …
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        // Delivery is synchronous, so the consume side is NESTED in the publish side: the PRODUCER span
        // wraps the CONSUMER span, and the envelope carries the traceparent between them exactly as it
        // would across a broker.
        $this->tracing->tracePublish($destination, $eventType, $headers, function (array $headers) use ($destination, $eventType, $payload): void {
            $envelope = new EventEnvelope($eventType, $destination, $payload, $headers);

            $this->tracing->traceConsume($envelope, fn (EventEnvelope $received) => $this->registry->deliver($received));
        });
    }

    public function start(): void {}

    public function stop(): void {}
}
```

`publish()` construye el envoltorio y se lo entrega a `SubscriberRegistry::deliver()` **de forma síncrona** — todo manejador que coincida ya se ha ejecutado para cuando `publish()` retorna, y un manejador que lance, lanza dentro de la llamada que publicó. Las dos llamadas a `EdaTracing` que lo envuelven son un no-op hasta que el Capítulo 11 instale un tracer real; lo que compran es que el bus en memoria emita el mismo par de spans productor-luego-consumidor que emitiría un broker, así que una traza no cambia de forma cuando cambias de proveedor.

`QueueEventBus` (`firefly.eda.provider=queue`) mantiene el contrato `EventPublisher` idéntico pero hace que `publish()` sea de disparar-y-olvidar:

<!-- source: packages/eda/src/Bus/QueueEventBus.php -->
```php
final class QueueEventBus implements EventPublisher
{
    // …
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        $this->tracing->tracePublish($destination, $eventType, $headers, function (array $headers) use ($destination, $eventType, $payload): void {
            $job = (new DispatchEventJob(new EventEnvelope($eventType, $destination, $payload, $headers)))
                ->onConnection($this->connection)
                ->onQueue($this->queue);

            // Resolved EVERY call so Bus::fake() intercepts — never hoist into the constructor.
            $this->container->make(Dispatcher::class)->dispatch($job);
        });
    }

    public function deliver(EventEnvelope $envelope): void
    {
        $this->tracing->traceConsume($envelope, fn (EventEnvelope $received) => $this->registry->deliver($received));
    }
// …
}
```

Las mismas dos costuras, repartidas entre dos procesos: `publish()` es el lado productor en la petición web, `deliver()` el lado consumidor en el worker, y el traceparent que viaja en las cabeceras del envoltorio es lo único que los une.

`publish()` retorna antes de que ningún listener se haya ejecutado — la entrega ocurre en el worker de cola que sea que recoja el `DispatchEventJob`. El propio arranque de ese worker vuelve a poblar su `SubscriberRegistry` a partir del mismo manifiesto compilado que lee `EventListenerWiringPass` en todas partes, así que un worker que no comparte nada reconstruye el conjunto idéntico de suscriptores cada vez que arranca. Bajo el driver de cola `sync`, la entrega colapsa al mismo comportamiento síncrono que `InMemoryEventBus` — que es exactamente cómo una prueba ejerce la ruta asíncrona sin que haya ningún worker realmente en ejecución.

---

## El puente de evento de dominio a evento de integración

El Capítulo 6 te mostró el *efecto* del puente — un `WalletOpened` confirmado llegando a `LedgerProjector` como un `EventEnvelope`. Aquí está el mecanismo. Todo `DomainEvent` confirmado a través del despacho post-confirmación de `firefly/data` (la sección de cierre del Capítulo 6) se publica también, en proceso, a `ApplicationEventPublisher` — la propia superficie `#[AsEventListener]` con la que este capítulo abrió al contrastarla con `#[EventListener]`. Como el propio dispatcher de Illuminate compara un evento objeto solo por su **clase concreta** (más las interfaces, nunca las clases padre), un único `#[AsEventListener]` tipado sobre la clase base abstracta `DomainEvent` nunca podría dispararse para ninguna subclase concreta. `DomainEventBridgeWiringPass` esquiva exactamente esta limitación con un único listener comodín (*wildcard*) protegido:

<!-- source: packages/cqrs/src/Boot/DomainEventBridgeWiringPass.php -->
```php
final class DomainEventBridgeWiringPass implements BootPass
{
    // …
    public function run(BootContext $context): void
    {
        $container = $context->container;
        // …
        $dispatcher = $container->make('events');

        $dispatcher->listen('*', DispatcherEventPublisher::guardListener(
            // …
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

<!-- source: packages/cqrs/src/Event/DomainEventBridge.php -->
```php
final class DomainEventBridge
{
    // …
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

## El genuino outbox de la misma transacción (`firefly/eda-database`)

`firefly/eda-database` cierra la brecha escribiendo la fila del outbox **dentro** de la propia transacción abierta del agregado, en la propia conexión del agregado — así que confirma o revierte de forma atómica junto con el agregado, sin doble escritura y sin nada que "esperar" después del hecho — y lo hace igual en PostgreSQL, MySQL, MariaDB, SQL Server y SQLite. `firefly/eda-postgres` es el mismo outbox con dos optimizaciones de PostgreSQL, y el final de esta sección muestra exactamente cuáles son.

::: figure art/figures/outbox-flow.svg | Figura 8.2 — El outbox de la misma transacción: la propia confirmación del agregado y el INSERT del outbox son una unidad atómica; un consumidor reclama filas PENDING de forma independiente.

### Activarlo

El outbox es un ajuste y una tabla. `firefly.eda.provider=database` lo selecciona; la configuración de referencia trae su bloque comentado con los valores por defecto:

<!-- source: skeleton/config/firefly.php -->
```php
// 'database' => [
//     'connection' => 'mysql',
//     'channel' => 'firefly_eda_events',
//     'max-attempts' => 3,
//     'claim-strategy' => env('FIREFLY_EDA_CLAIM_STRATEGY', 'auto'),
//     'lease-seconds' => 300,
//     'poll-interval-ms' => 500,
//     'relay' => [
//         'downstream-provider' => 'rabbitmq',
//     ],
// ],
```

<!-- illustrative: the two commands a deployment runs once it selects provider=database -->
```bash
php artisan migrate               # crea firefly_eda_outbox (y añade las columnas de arrendamiento a una antigua)
php artisan firefly:eda:consume   # la entrega terminal en proceso — ejecuta uno o más como workers de larga duración
```

Nada cambia en el código de Lumen. El `OutboxDatabaseTestCase` de la muestra añade el proveedor de `firefly/eda-database` y fija la clave, y los mismos comandos `OpenWallet` y `Deposit` dejan ahora sus eventos en la tabla hasta que un consumidor viene a por ellos:

<!-- source: samples/lumen/tests/Outbox/OutboxDatabaseDeliveryTest.php -->
```php
    // Committed with the wallet and waiting for a consumer: the projector has not run yet.
    expect(DB::table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PENDING)->orderBy('id')->pluck('event_type')->all())
        ->toBe(['WalletOpened', 'FundsDeposited'])
        ->and(LedgerEntry::query()->where('wallet_id', $id)->count())->toBe(0);

    $exit = Artisan::call('firefly:eda:consume', ['--max-messages' => 2, '--time-limit' => 10, '--poll-timeout' => 100]);
```

Ese es el trato que hace el outbox, dicho en dos aserciones: los eventos son duraderos en el instante en que la cartera confirma, y `LedgerProjector` se ejecuta un momento después, en un worker, en vez de dentro de la petición. El resto de esta sección explica cómo se garantizan las dos mitades.

### El mecanismo: `PreCommitEventHook`

El Capítulo 6 mostró a `DomainEventDispatcher::dispatchAfterCommit()` drenando el `AggregateTracker` y planificando cada evento vía `DB::afterCommit()`. Lleva un colaborador más, opcional — un hook que se ejecuta **antes** de esa planificación, mientras la transacción sigue abierta:

<!-- source: packages/data/src/Domain/PreCommitEventHook.php -->
```php
interface PreCommitEventHook
{
    public function handle(object $event, ?string $connection = null): void;
}
```

Sin ningún hook vinculado — con cualquier proveedor que no sea `database` ni `postgres` — esto es un no-op completo y `DomainEventDispatcher` se comporta exactamente como describió el Capítulo 6. `OutboxPreCommitHook` de `firefly/eda-database` es la implementación que lo activa, con ambos proveedores de outbox:

<!-- source: packages/eda-database/src/Outbox/OutboxPreCommitHook.php -->
```php
class OutboxPreCommitHook implements PreCommitEventHook
{
    // …
    public function handle(object $event, ?string $connection = null): void
    {
        if (! $event instanceof DomainEvent) {
            return;
        }

        // The aggregate's connection — the one carrying the open transaction.
        $publisher = $this->writerFor($this->connections->connection($connection));

        (new EdaCommandEventPublisher($publisher, $this->defaultDestination, $this->destinations, $this->correlation))
            ->publish($event);
    }

    /** The writer for one event, on the aggregate's own connection. */
    protected function writerFor(ConnectionInterface $connection): DatabaseEventPublisher
    {
        return new DatabaseEventPublisher($connection, $this->registry, $this->channel, $this->tracing);
    }
}
```

El hook homónimo de `firefly/eda-postgres` solo sobrescribe `writerFor()`, devolviendo un `PostgresEventPublisher` que además notifica sobre una conexión `pgsql`.

`$connection` se propaga sin cambios desde el dispatcher, así que la fila del outbox de un agregado `#[Transactional(connection: 'x')]` aterriza en la conexión `x` — nunca un valor por defecto fijo — y el hook reutiliza el *mismo* mapeo de `EdaCommandEventPublisher` (tipo de evento, carga útil, destino, id de correlación) que usa el puente post-confirmación, así que la fila que esto escribe es idéntica byte a byte en forma a lo que habría producido el puente ordinario.

### El INSERT, y `pg_notify` en la misma transacción

<!-- source: packages/eda-database/src/DatabaseEventPublisher.php -->
```php
class DatabaseEventPublisher implements EventPublisher, OutboxWriter
{
    // …
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        $this->tracing->tracePublish($destination, $eventType, $headers, function (array $headers) use ($destination, $eventType, $payload): void {
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

            $this->afterInsert($id);
        });
    }
// …
}
```

<!-- source: packages/eda-postgres/src/PostgresEventPublisher.php -->
```php
final class PostgresEventPublisher extends DatabaseEventPublisher
{
    // …
    protected function afterInsert(int $id): void
    {
        if ($this->emitNotify) {
            // In-tx pg_notify: Postgres queues it and delivers on COMMIT, so the consumer's LISTEN wakes exactly when
            // the PENDING row becomes visible. (sqlite and every other driver: emitNotify=false.)
            $this->connection->statement('SELECT pg_notify(?, ?)', [$this->channel, (string) $id]);
        }
    }
}
```

Como `publish()` se ejecuta sobre **cualquiera que sea la conexión con la que se construyó**, y esa conexión está en medio de una transacción cuando `OutboxPreCommitHook` la llama, el `INSERT` se alista en la propia transacción del agregado: confirma cuando el agregado confirma, y revierte cuando el agregado revierte. Con `firefly.eda.provider=postgres` el escritor es `PostgresEventPublisher`, cuyo `afterInsert()` — invocado desde el mismo closure — lanza además `SELECT pg_notify(?, ?)` **dentro de la misma transacción**; con `provider=database` no hay NOTIFY y el consumidor sondea. Postgres encola un `NOTIFY` lanzado dentro de una transacción y solo lo entrega de verdad una vez que esa transacción confirma, así que un consumidor a la escucha se despierta con baja latencia en el instante exacto en que la fila se vuelve visible para otras conexiones — y nunca se despierta para una fila que termina revertida.

Ese cuerpo entero vive dentro de `EdaTracing::tracePublish()` — el puerto único por el que pasan ahora los tres publicadores de broker, `eda-rabbitmq` y `eda-kafka` junto a este. Como propagar significa *escribir* algo en el sobre, el puerto le entrega al closure de envío las cabeceras que debe llevar y el adaptador construye la fila a partir de ellas, así que el `traceparent` que estampa el span productor va en la fila que confirma con el agregado — este es el único adaptador donde el span productor y el registro duradero son genuinamente atómicos, en lugar de un span que termina en cuanto un socket aceptó el mensaje. Con el trazado apagado, el `EdaTracing` vinculado es `NoOpEdaTracing`, que llama al closure con las cabeceras del llamador sin cambios, así que el camino es byte a byte el que ya era; y `transaction_id` se sigue leyendo de `x-correlation-id`, nunca de un traceparent, así que la clave de idempotencia del relay no cambia solo porque una petición resultara estar trazada.

La tabla del outbox (`firefly_eda_outbox`, un único esquema compartido por la migración, el publicador, el consumidor y el relay) lleva: `id, destination, channel, event_type, payload, headers, transaction_id, status, attempts, error_message, created_at, processed_at, failed_at, claimed_by, claimed_until` — las dos últimas son el arrendamiento de la reclamación —, con `status` siendo uno de `PENDING` / `PUBLISHED` / `FAILED`.

Una prueba genuina y distribuida demuestra ambas mitades de la garantía directamente contra una transacción real:

<!-- source: packages/eda-postgres/tests/OutboxSameTransactionTest.php -->
```php
it('writes the outbox row INSIDE the caller transaction (present after commit)', function () {
    // …
    DB::transaction(function () use ($publisher): void {
        $publisher->publish('users', 'user.created', ['id' => 1], ['x-a' => 'b']);
    });
    // …
    expect(DB::table(OutboxSchema::TABLE)
        ->where('event_type', 'user.created')
        ->where('status', OutboxSchema::STATUS_PENDING)
// …
});

it('rolls the outbox row back WITH the aggregate (absent after rollback)', function () {
    // …
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

Con `provider=postgres` vinculado, tanto `OutboxPreCommitHook` (en-tx) *como* el `DomainEventBridge` post-confirmación ordinario podrían, en principio, intentar escribir el mismo evento en el outbox. `PostgresOutboxAutoConfiguration` lo evita en `#[Order(900)]` (y `DatabaseOutboxAutoConfiguration` hace lo mismo con `provider=database`) — por delante de los valores por defecto `#[Order(1000)]` de `firefly/data` y `firefly/cqrs` — vinculando, en conjunto, como un único paquete:

- su **propio** `DomainEventDispatcher`, portando el `OutboxPreCommitHook`, que gana el `#[ConditionalOnMissingBean(DomainEventDispatcher::class)]` de `DataAutoConfiguration`, y
- un `NoOpEventPublisher` como el `CommandEventPublisher`, que gana el `#[ConditionalOnMissingBean(CommandEventPublisher::class)]` de `CqrsAutoConfiguration` — silenciando **solo** el tramo post-confirmación de eda.

El resultado: un evento de dominio se escribe en el outbox **exactamente una vez**, en-tx, de forma atómica junto con el agregado. El despacho post-confirmación genérico (no de eda) `#[AsEventListener]` con el que abrió este capítulo queda completamente intacto — esos listeners en proceso siguen disparándose exactamente como antes. Los cuatro beans están además protegidos por el valor de su proveedor (`#[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'postgres')]`, o `'database'`), así que instalar el paquete sin seleccionarlo como proveedor activo es totalmente inerte.

### Reclamar filas: `DatabaseEventConsumer`

Una vez que una fila es `PENDING`, `DatabaseEventConsumer` (de `firefly/eda-database`) es la ruta de entrega en proceso terminal, siempre disponible — `php artisan firefly:eda:consume` lo conduce como un worker de larga duración, igual con `provider=database` que con `provider=postgres`:

<!-- source: packages/eda-database/src/DatabaseEventConsumer.php -->
```php
class DatabaseEventConsumer implements EventConsumer
{
    // …
    public function poll(int $timeoutMs): ?ReceivedEnvelope
    {
        $received = $this->claimOne();
        if ($received !== null) {
            return $received;
        }

        try {
            ($this->awaitWork)($timeoutMs);
        } catch (Throwable) {
            // The wait is an optimization; the claim below delivers regardless.
        }

        return $this->claimOne();
    }

    public function ack(ReceivedEnvelope $received): void
    {
        ClaimableRows::owned($this->connection, OutboxRow::asInt($received->deliveryTag), $this->claimant)->update([
            'status' => OutboxSchema::STATUS_PUBLISHED,
            'processed_at' => $this->connection->raw('CURRENT_TIMESTAMP'),
            'claimed_by' => null,
            'claimed_until' => null,
        ]);
    }
// …
}
```

Una reclamación es un **arrendamiento**: `claimOne()` pide una fila a la `OutboxClaimStrategy` configurada, y la estrategia escribe `claimed_by` y `claimed_until` en ella y lo confirma *antes* de que corra el manejador. Cómo se elige la fila depende de lo que hable el servidor — `FOR UPDATE SKIP LOCKED` dentro de una transacción de reclamación corta en PostgreSQL, MySQL 8.0.1+ y MariaDB 10.6+, la pista de tabla `UPDLOCK, READPAST` en SQL Server, un `UPDATE … WHERE status = 'PENDING' AND claimed_until < now` condicional en todos los demás — pero toda estrategia termina con la fila arrendada a exactamente un consumidor. `ack()` y `nack()` solo liquidan una fila que este consumidor todavía tiene, así que un consumidor que sobrevivió a su arrendamiento no liquida nada. Fíjate en que **no hay ninguna llamada a `EventPublisher::publish()` en ningún lugar de esta clase**: lee y actualiza la tabla del outbox directamente, así que no hay un `INSERT` de vuelta. Un lanzamiento del manejador llama a `nack()`, que incrementa `attempts` y libera la fila para un reintento inmediato o, pasado `firefly.eda.database.max-attempts` (3 por defecto; `firefly.eda.postgres.max_attempts` es su alias), la marca `FAILED` in situ — una fila `status='FAILED'` *es* la carta muerta del outbox.

Con `provider=postgres`, `PostgresEventConsumer` es esta clase más el despertar de Postgres — `LISTEN` al suscribirse, y una espera de `NOTIFY` en lugar de una pausa entre reclamaciones vacías:

<!-- source: packages/eda-postgres/src/PostgresEventConsumer.php -->
```php
    public function subscribe(array $destinations): void
    {
        if ($this->connection->getDriverName() === 'pgsql') {
            $this->connection->getPdo()->exec('LISTEN '.$this->channel);
        }
    }
```

Esta es una ventana de estado `PENDING`→`PUBLISHED` **duradera**, no una marca de agua alta en memoria: un consumidor reiniciado retoma en la fila reclamable más antigua y nunca reproduce una ya `PUBLISHED`. Pueden correr a la vez tantos workers consumidores como quieras, en cualquiera de las cinco bases de datos — cada fila se entrega una vez mientras su consumidor vive dentro de su arrendamiento (`firefly.eda.database.lease-seconds`, 300 por defecto); un consumidor que cae a mitad de un manejador deja su fila para reclamarla de nuevo cuando acaba el arrendamiento, así que a través de una caída la entrega es al-menos-una-vez y los listeners deberían seguir siendo idempotentes.

### Cómo se elige una reclamación, servidor a servidor

`claimOne()` nunca fija su SQL en el código. Una `OutboxClaimStrategy` es la que elige, y `firefly.eda.database.claim-strategy=auto` (el valor por defecto) escoge una a partir de lo que el servidor informa de sí mismo — `DatabaseCapabilities` de `firefly/data` lee la versión, nunca el nombre del driver, así que una conexión `mysql` cuyo servidor es MariaDB se reconoce como MariaDB:

| Estrategia | Cómo elige una fila | `auto` la elige en |
|---|---|---|
| `skip-locked` | `SELECT … FOR UPDATE SKIP LOCKED` en una transacción de reclamación corta (MySQL y MariaDB la ejecutan en `READ COMMITTED`, así que los publicadores nunca esperan a sus bloqueos de hueco) | PostgreSQL; MySQL 8.0.1+; MariaDB 10.6+ |
| `read-past` | `SELECT TOP n … WITH (UPDLOCK, READPAST, ROWLOCK)` en una transacción de reclamación corta | SQL Server |
| `optimistic-lease` | una lectura sin bloqueo y luego un `UPDATE … WHERE status = 'PENDING' AND (claimed_until IS NULL OR claimed_until < now)` condicional por fila; la fila se gana cuando cambia exactamente una fila | SQLite; cualquier servidor que la sonda no reconozca; MySQL y MariaDB antiguos |

Una estrategia forzada por nombre que el servidor no puede ejecutar falla en la primera reclamación con un error que nombra el servidor y su versión — nunca se degrada en silencio — y la elección se hace en esa primera reclamación, así que arrancar una aplicación no abre ninguna conexión para averiguarlo. Los instantes del arrendamiento son el reloj de la aplicación en UTC con precisión de milisegundos, así que mantén los relojes de los nodos sincronizados muy por dentro de `firefly.eda.database.lease-seconds`.

### Tantos consumidores como quieras

Como toda estrategia termina con la fila arrendada a exactamente un reclamante, escalar la entrega es arrancar otro `firefly:eda:consume`. Cada fila se entrega una vez mientras su worker vive dentro de su arrendamiento; un worker que muere a mitad de una entrega deja su fila arrendada hasta que acaba el arrendamiento, y entonces otro worker la entrega — a través de una caída, la entrega es al-menos-una-vez. `packages/eda-database/tests/Integration/OutboxConcurrencyIntegrationTest.php` hace competir a dos procesos sobre un mismo outbox en cada servidor, y la muestra ejecuta su propio viaje de ida y vuelta en cada uno de ellos, una entrada del dataset por carril:

<!-- source: samples/lumen/tests/Integration/OutboxDatabaseRoundTripTest.php -->
```php
    $exit = Artisan::call('firefly:eda:consume', ['--max-messages' => 2, '--time-limit' => 20, '--poll-timeout' => 500]);

    expect($exit)->toBe(0)
        ->and(DB::table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PENDING)->count())->toBe(0)
        ->and(LedgerEntry::query()->where('wallet_id', $id)->orderBy('id')->pluck('event_type')->all())
        ->toBe(['WalletOpened', 'FundsDeposited']);
})->with(IntegrationDsn::lanes())->group('integration');
```

Con `provider=database` el informe de salud del actuator gana un componente `outbox` — el driver, la estrategia en uso y el atasco de filas `PENDING`, o `DOWN` con el error — así que una flota de consumidores que ha dejado de dar abasto se ve antes de que nadie pregunte por qué llega tarde el libro mayor.

### Reenviar a un broker: `firefly:outbox:relay`

`firefly:eda:consume` entrega las filas a los propios listeners de esta aplicación. Cuando otros servicios también tienen que oír los eventos, el relay reenvía las filas confirmadas a un segundo broker y marca cada una como `PUBLISHED` — el mismo arrendamiento, el mismo `max-attempts`, la traza que lleva cada fila continuada a través del salto. `firefly.eda.database.relay.downstream-provider` nombra el broker (`rabbitmq`, `kafka`, un class-string de `EventPublisher` o un id del contenedor), y el relay rechaza como destino al propio escritor del outbox:

<!-- illustrative: an operator running the optional relay as its own worker -->
```bash
php artisan firefly:outbox:relay --batch-size=50 --sleep=1
```

### Qué añade `firefly/eda-postgres`

Todo lo anterior funciona en PostgreSQL tal cual. `firefly.eda.provider=postgres` es ese mismo outbox — la misma tabla, las mismas reclamaciones, el mismo consumidor — más las dos cosas que solo ofrece PostgreSQL: `PostgresEventPublisher` lanza `pg_notify` dentro de la transacción que escribe, y `PostgresEventConsumer` hace `LISTEN` y espera a la notificación en vez de dormir `poll-interval-ms` entre reclamaciones vacías. Toda clave `firefly.eda.postgres.*` sigue funcionando como alias de su gemela `firefly.eda.database.*`, y la clave de database gana cuando las dos están fijadas, así que moverse entre los dos proveedores no pierde ningún ajuste.

!!! laravel "Paridad con Laravel"
    Laravel puro no tiene ningún patrón de outbox transaccional de primera parte en absoluto — el idioma que tendrías que construir a mano es una tabla `outbox` más un comando planificado que la sondea, sin ninguna ayuda del framework para mantener el `INSERT` dentro de la misma transacción que tu escritura de dominio. `firefly/eda-database` es exactamente ese idioma, en cualquier base de datos que hable Laravel, pero con la atomicidad garantizada por construcción (`PreCommitEventHook` se ejecuta mientras la transacción sigue abierta) en vez de dejada a la disciplina del desarrollador.

---

## Cartas muertas que sobreviven al proceso

El modelo de reintento del principio de este capítulo termina en una carta muerta, y por defecto esa carta muerta vive en `InMemoryDeadLetterStore` — desaparece cuando el proceso termina. Eso vale para una prueba y está mal para un worker, así que `firefly.eda.dead-letter.store` elige el almacén bajo cualquier proveedor:

<!-- source: skeleton/config/firefly.php -->
```php
'dead-letter' => [
    'store' => env('FIREFLY_EDA_DEAD_LETTER_STORE', 'memory'),
    // 'connection' => 'mysql',
],
```

`database` escribe una fila de `firefly_eda_dead_letters` por cada envoltorio agotado, en `firefly.eda.dead-letter.connection` (la conexión por defecto si no se fija). Los dos almacenes implementan `ReplayableDeadLetterStore`, que es lo que necesita el comando de repetición: republica las entradas, de la más antigua a la más reciente, a través del `EventPublisher` vinculado hacia su destino original, y elimina cada una que se republicó:

<!-- illustrative: an operator inspecting, then replaying, the dead letters of one event type -->
```bash
php artisan firefly:eda:dead-letters:replay --event-type=FundsDeposited --dry-run
php artisan firefly:eda:dead-letters:replay --event-type=FundsDeposited --limit=100
```

`--id`, `--destination` y `--keep` acotan y conservan; `--dry-run` lista y no cambia nada. `firefly/messaging` tiene el mismo par — `firefly.messaging.dead-letter.store=memory|database` y `php artisan firefly:messaging:dead-letters:replay` — para su `MessageBrokerPort` en bruto. Una fila del outbox que agota `max-attempts` es distinta: se marca como `FAILED` en su sitio, porque la fila *es* la carta muerta del outbox, y las dos pruebas corren en cada servidor de la matriz (consulta la matriz de soporte de bases de datos en la documentación).

---

## Mensajería sobre Kafka y RabbitMQ

`firefly/messaging` es el hermano de más bajo nivel del bus de eventos: cargas `Message` en bruto sobre un `MessageBrokerPort`, consumidas por métodos `#[MessageListener]`. `firefly.messaging.provider=kafka` (con `ext-rdkafka`) y `=rabbitmq` vinculan un contenedor de listeners sobre las mismas factorías de clientes que usan los adaptadores del bus de eventos, y un worker consume de él:

<!-- illustrative: a messaging worker bounded by a message count and a time limit, as a supervisor would run it -->
```bash
php artisan firefly:messaging:consume --max-messages=1000 --time-limit=3600
```

El contenedor es el dueño de los reintentos: una entrega se intenta `firefly.messaging.listener.retry.max-attempts` veces (cinco por defecto) con un retroceso exponencial desde un segundo, duplicándose, con tope en treinta; un listener con `retries: N` obtiene N + 1 intentos con su propio `retryDelay` lineal en su lugar. Después, una carta muerta — Kafka produce el registro en el `deadLetterTopic` del listener o en `<topic>.DLT`, RabbitMQ lo republica en `deadLetterTopic` o deja que el exchange de cartas muertas de la cola lo mueva a `<queue>.dlq` — que lleva `x-original-topic`, `x-exception`, `x-exception-message` y `x-delivery-attempts`. Un registro se confirma, o un mensaje se reconoce, solo después de que sus listeners lo manejaran o de que pasara a carta muerta, así que la entrega es al-menos-una-vez; los grupos de consumidores son reales (un grupo de consumidores de Kafka por grupo de listeners, una cola duradera de RabbitMQ por grupo). Con `memory` y `queue` el comando se niega con el código de salida 1, porque esos proveedores no tienen bucle de consumo.

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
| `OutboxPreCommitHook` / `DatabaseEventPublisher` | Escribe la fila del outbox **dentro** de la propia transacción abierta del agregado — genuinamente atómico, en cualquiera de las cinco bases de datos; `PostgresEventPublisher` añade `pg_notify` |
| `DatabaseEventConsumer` / `PostgresEventConsumer` | Arrienda filas `PENDING` (SKIP LOCKED, READPAST o un UPDATE condicional — cualquier número de workers), conduce directamente los manejadores de `#[EventListener]`, sin `INSERT` de vuelta; Postgres añade `LISTEN`/`NOTIFY` |
| `OutboxClaimStrategy` | `skip-locked`, `read-past` u `optimistic-lease`, elegida por `auto` a partir de la versión del servidor; todas arriendan una fila a exactamente un reclamante |
| `firefly:outbox:relay` | Opcional: reenvía las filas confirmadas a un segundo broker (`firefly.eda.database.relay.downstream-provider`) |
| `firefly.eda.dead-letter.store=database` | Cartas muertas en `firefly_eda_dead_letters`, reproducidas por `firefly:eda:dead-letters:replay` (la mensajería tiene el mismo par) |
| `firefly:messaging:consume` | El worker del contenedor de listeners de Kafka/RabbitMQ: reintentos del contenedor, topics o colas de cartas muertas, confirmaciones al-menos-una-vez |

---

## Ponlo en práctica {.exercises}

1. **Reproduce el tropiezo, deliberadamente.** En una copia de trabajo del proyecto, cambia los patrones de `#[EventListener]` de `LedgerProjector` a `['wallet.*']` y vuelve a ejecutar su suite de pruebas. Confirma que el proyector jamás se dispara en silencio y que nunca se escribe ninguna fila en `ledger_entries` — luego restaura los patrones originales de nombre de tipo y confirma que vuelven a pasar.
2. **Fuerza una carta muerta.** Escribe un pequeño manejador `#[EventListener]` que siempre lance una excepción, configura `firefly.eda.retries=2` y vincula un `InMemoryDeadLetterStore`, publica un evento que coincida, e inspecciona `DeadLetterStore::all()` después. Confirma que el `exceptionMessage` de la entrada coincide con lo que lanzó tu manejador.
3. **Demuestra el outbox en un servidor real.** Exporta el DSN de un carril (el Capítulo 12 enseña cómo) y ejecuta `composer test:integration -- samples/lumen/tests/Integration/OutboxDatabaseRoundTripTest.php`. Confirma que las dos filas están en `PENDING` antes de que corra el consumidor y en `PUBLISHED` después, y lee luego `OutboxSameTransactionTest` para la mitad del rollback: la fila de una unidad de trabajo fallida no llega a existir.
