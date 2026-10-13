<span class="eyebrow">Parte III — Coordinar y Asegurar la Aplicación · Capítulo 8A</span>

# Webhooks y Callbacks: Recibir y Enviar Eventos Firmados {.chtitle}

Al terminar este capítulo sabrás cómo una aplicación LaraFly recibe un webhook y cómo lo envía, y por qué las dos direcciones terminan en el bus de eventos que construyó el Capítulo 8. A la entrada, `firefly/webhooks` da a cada proveedor configurado un endpoint, `POST /webhooks/{provider}`, que rechaza un cuerpo demasiado grande, verifica la firma (`hmac`, `stripe`, `github`, `twilio` o `none`), somete una marca de tiempo firmada a una ventana de repetición, responde a una reentrega como `duplicate` y publica lo que acepta como `webhook.{provider}.{type}` — así que la recarga con tarjeta de Lumen es un `#[EventListener]` corriente. A la salida, `firefly/callbacks` envía por POST cada evento del bus al que se suscribió un socio, firmado con `X-Firefly-Timestamp` y `X-Firefly-Signature`, reintentado con espera creciente tras un circuit breaker por endpoint, y anotado en un registro de ejecuciones que puedes listar y reproducir con `firefly:callbacks:list` y `firefly:callbacks:replay`.

!!! note "Término nuevo: webhook"
    Un **webhook** es una petición HTTP que un sistema hace a otro para decirle que algo ha ocurrido — un pago con tarjeta se ha completado, se ha fusionado una pull request. El receptor no lo pidió en ese momento; registró una URL una vez, y el emisor la llama. De ahí se siguen dos cosas, y este capítulo trata sobre todo de ellas: el receptor tiene que demostrar que la petición viene de verdad del emisor (una firma sobre los bytes exactos), y tiene que contar con recibir el mismo evento más de una vez (los emisores reintentan hasta que ven un 2xx).

---

## Dos paquetes, un bus

`firefly/webhooks` es la mitad de entrada y `firefly/callbacks` la de salida, y ninguno sabe del otro. Lo que comparten es el bus: un webhook aceptado se convierte en un `EventEnvelope` publicado a través del puerto `EventPublisher`, y un callback se dispara cuando llega un envoltorio a él. Todo lo que el Capítulo 8 dijo de ese bus — la comparación por `eventType`, los reintentos, las cartas muertas, el outbox, los brokers — se aplica por tanto a las dos mitades sin una línea de código adicional. Los dos están apagados hasta que se fija su clave maestra, `firefly.webhooks.enabled` y `firefly.callbacks.enabled`, y los dos vienen en la biblioteca raíz.

---

## Recibir: un endpoint firmado por proveedor

La configuración de referencia muestra el bloque de entrada con cada clave opcional comentada en su valor por defecto:

<!-- source: skeleton/config/firefly.php -->
```php
'webhooks' => [
    'enabled' => env('FIREFLY_WEBHOOKS_ENABLED', false),

    // 'path' => 'webhooks',
    // 'max-body-bytes' => 1048576,
    // 'destination' => 'webhooks.{provider}',
    // 'event-type' => 'webhook.{provider}.{type}',
    // 'replay' => ['tolerance' => '5m'],
    // 'idempotency' => [
    //     'store' => env('FIREFLY_WEBHOOKS_IDEMPOTENCY_STORE', 'cache'),
    //     'ttl' => '24h',
    //     'cache-store' => '',
    //     'connection' => null,
    // ],
    // 'providers' => [
    //     'stripe' => ['validator' => 'stripe', 'secret' => env('STRIPE_WEBHOOK_SECRET')],
    //     'github' => ['validator' => 'github', 'secret' => env('GITHUB_WEBHOOK_SECRET')],
    //     'twilio' => ['validator' => 'twilio', 'secret' => env('TWILIO_AUTH_TOKEN'), 'public-url' => 'https://api.example.com/webhooks/twilio'],
    //     'partner' => ['secret' => env('PARTNER_WEBHOOK_SECRET'), 'timestamp-header' => 'X-Firefly-Timestamp', 'signature-header' => 'X-Firefly-Signature', 'idempotency-header' => 'X-Firefly-Delivery'],
    // ],
],
```

La clave del array bajo `providers` es el id del proveedor y el último segmento de su URL: `stripe` se responde en `/webhooks/stripe`. Un proveedor que firma y no tiene secreto impide el arranque y nombra la clave. Lumen tiene un proveedor, el procesador de tarjetas que recarga los monederos, y usa el validador `hmac` por defecto, así que toda la configuración es un secreto:

<!-- source: samples/lumen/tests/Web/LumenWebhooksTestCase.php -->
```php
            'firefly.webhooks.enabled' => true,
            'firefly.webhooks.idempotency.store' => 'memory',
            'firefly.webhooks.providers' => [
                'card-processor' => ['secret' => self::SECRET],
            ],
```

Cada validador sabe dónde pone su proveedor la firma, qué firma, qué campo nombra el evento y qué valor identifica una entrega:

| `validator` | Firma | Protección frente a repeticiones | Clave de idempotencia | Tipo de evento |
|---|---|---|---|---|
| `hmac` (por defecto) | `X-Signature: sha256=<hex HMAC of the body>`; con `timestamp-header` la carga firmada es `"<timestamp>.<body>"` | la marca de tiempo, cuando se fija `timestamp-header` | `X-Idempotency-Key` | campo `type` del cuerpo |
| `stripe` | `Stripe-Signature: t=…,v1=…` (vale cualquier `v1` mientras se rota un secreto) | `t`, dentro de `tolerance` | campo `id` del cuerpo | campo `type` del cuerpo |
| `github` | `X-Hub-Signature-256: sha256=…` | nada firmado — la clave de idempotencia | `X-GitHub-Delivery` | `X-GitHub-Event` |
| `twilio` | `X-Twilio-Signature`, HMAC-SHA1 en base64 sobre la URL y los parámetros del formulario ordenados | nada firmado — la clave de idempotencia | `I-Twilio-Idempotency-Token` | campo `EventType` del formulario |
| `none` | sin verificar — para un emisor en una red de confianza | — | `X-Idempotency-Key` | campo `type` del cuerpo |

Cada columna es un valor por defecto que la entrada de un proveedor puede sobrescribir (`signature-header`, `signature-prefix`, `algorithm`, `encoding`, `timestamp-header`, `tolerance`, `idempotency-header`, `idempotency-field`, `event-type-header`, `event-type-field`, `destination`, `public-url`). La ruta se monta sin grupo de middleware — sin sesión y sin un token CSRF que un proveedor nunca podría enviar — y cuando `HttpSecurity` está encendido permites `webhooks/**`, porque la firma es la autenticación.

---

## Qué responde el endpoint

| Estado | Cuándo |
|---|---|
| 202 `accepted` | verificada y publicada |
| 200 `duplicate` | una entrega ya aceptada (la misma clave de idempotencia dentro de `idempotency.ttl`) |
| 400 `WEBHOOK_PAYLOAD_INVALID` | el cuerpo dice ser JSON y no es un objeto JSON |
| 401 `WEBHOOK_SIGNATURE_INVALID` | una firma ausente o incorrecta |
| 401 `WEBHOOK_TIMESTAMP_OUT_OF_TOLERANCE` | una marca de tiempo firmada fuera de la ventana de repetición |
| 404 `WEBHOOK_PROVIDER_NOT_FOUND` | no existe ese proveedor |
| 413 `WEBHOOK_PAYLOAD_TOO_LARGE` | por encima de `max-body-bytes`, rechazada antes de calcular ninguna firma |
| 503 `WEBHOOK_PUBLISH_FAILED` | verificada, pero el bus rechazó la publicación — la reclamación de idempotencia se libera, así que el reintento del proveedor se procesa |

Todo rechazo es problem+json diga lo que diga la cabecera `Accept` del emisor, y todo 4xx nombra al proveedor en un miembro `provider`, así que un secreto mal configurado aparece en tus logs con el proveedor al lado.

---

## La recarga con tarjeta de Lumen

Una entrega aceptada se publica como `webhook.{provider}.{type}` en `webhooks.{provider}`, con el cuerpo decodificado bajo la clave `body` de la carga. Para el `top_up.succeeded` del procesador de tarjetas eso es `webhook.card-processor.top_up.succeeded`, y el código que reacciona a él es un listener como `LedgerProjector`:

<!-- source: samples/lumen/src/Application/Listener/CardTopUpListener.php -->
```php
#[Component]
final class CardTopUpListener
{
    public function __construct(private readonly CommandBus $commands) {}

    #[EventListener('webhook.card-processor.top_up.succeeded')]
    public function onTopUp(EventEnvelope $envelope): void
    {
        $body = $envelope->payload['body'] ?? null;
        $data = is_array($body) ? ($body['data'] ?? null) : null;
        $walletId = is_array($data) ? ($data['wallet_id'] ?? null) : null;
        $amountMinor = is_array($data) ? ($data['amount_minor'] ?? null) : null;

        if (! is_string($walletId) || ! is_int($amountMinor) || $amountMinor <= 0) {
            return;
        }

        $this->commands->send(new Deposit($walletId, $amountMinor));
    }
}
```

El listener nunca comprueba una firma, una marca de tiempo ni un duplicado: cuando un envoltorio llega al bus, las tres cosas ya están resueltas. Lo que hace es el trabajo del dominio — un `Deposit`, por el mismo bus, el mismo handler `#[Transactional]` y los mismos eventos de dominio que un ingreso hecho por REST. La prueba del ejemplo envía la entrega dos veces, como lo haría un procesador que no vio el primer 202:

<!-- source: samples/lumen/tests/Web/CardTopUpWebhookTest.php -->
```php
    $this->postWebhook('/webhooks/card-processor', $body, lumenTopUpHeaders($body, 'evt_1'))
        ->assertStatus(202)
        ->assertJson(['status' => 'accepted', 'provider' => 'card-processor', 'eventType' => 'webhook.card-processor.top_up.succeeded']);

    // The processor never saw the 202 and delivers the same event again: answered, not deposited twice.
    $this->postWebhook('/webhooks/card-processor', $body, lumenTopUpHeaders($body, 'evt_1'))
        ->assertStatus(200)
        ->assertJson(['status' => 'duplicate']);

    $this->getJson("/api/v1/wallets/{$id}/balance")->assertOk()->assertJson(['balance_minor' => 2500]);
```

`postWebhook()` es el POST en crudo del arnés: una firma cubre bytes, así que un helper que volviera a codificar el JSON firmaría algo que el endpoint nunca recibe. La segunda prueba del fichero cambia un dígito del importe después de firmar y obtiene un 401 `WEBHOOK_SIGNATURE_INVALID` con el saldo intacto.

!!! tip "Un listener que falla es una carta muerta, no un 5xx"
    La tercera prueba envía una recarga verificada para un monedero que no existe. El `Deposit` lanza, el listener lanza — y el endpoint sigue respondiendo 202, porque en el bus en memoria todo listener corre dentro de `RetryingEventHandler`, y siempre hay un `DeadLetterStore` enlazado: el envoltorio se guarda ahí, no se pierde. Es la respuesta correcta para el procesador (su entrega era válida; reintentarla fallaría igual), y el sitio correcto donde dejar el problema: corrige la causa y luego `php artisan firefly:eda:dead-letters:replay --event-type=webhook.card-processor.top_up.succeeded` la entrega de nuevo. Con `firefly.eda.dead-letter.store=database` (Capítulo 8) la carta muerta sobrevive a un reinicio.

<!-- source: samples/lumen/tests/Web/CardTopUpWebhookTest.php -->
```php
    $entries = $this->app()->make(DeadLetterStore::class)->all();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->envelope->eventType)->toBe('webhook.card-processor.top_up.succeeded')
        ->and($entries[0]->exceptionMessage)->toContain('no-such-wallet');
```

---

## Recordar una entrega: idempotencia

`firefly.webhooks.idempotency.store` elige dónde se recuerdan los ids de las entregas aceptadas durante `idempotency.ttl` (24 horas por defecto). La reclamación es atómica, así que dos entregas simultáneas de un mismo webhook producen un 202 y un 200, nunca dos publicaciones:

| Almacén | Respaldo | Notas |
|---|---|---|
| `cache` (por defecto) | el almacén de caché de Laravel que nombra `idempotency.cache-store`, `add()` con un TTL | atómico en los almacenes redis, memcached, database y array |
| `database` | `firefly_webhook_receipts` en `idempotency.connection` | tabla portable, clave primaria (provider, key), sensible a mayúsculas en todos los drivers; púrgala a diario |
| `memory` | el proceso | un solo proceso — las pruebas de Lumen lo usan porque una prueba es un proceso |

<!-- illustrative: the commands an application runs once, and then on a schedule, for the database store -->
```bash
php artisan vendor:publish --tag=firefly-webhooks-migrations
php artisan migrate
php artisan firefly:webhooks:prune
```

Detrás de un proxy, fija el `public-url` del proveedor: una firma de Twilio cubre la URL exacta a la que llamó Twilio, y un balanceador que reescriba el host o el esquema haría parecer falsificadas todas las firmas.

---

## Enviar: callbacks a los socios que los pidieron

La mitad de salida parte del mismo fichero de referencia:

<!-- source: skeleton/config/firefly.php -->
```php
'callbacks' => [
    'enabled' => env('FIREFLY_CALLBACKS_ENABLED', false),

    // 'event-types' => ['order.*', 'invoice.paid'],
    // 'registry' => ['store' => env('FIREFLY_CALLBACKS_REGISTRY', 'memory')],
    // 'log' => ['store' => env('FIREFLY_CALLBACKS_LOG', 'memory')],
    // 'connection' => null,
    // 'subscriptions' => [
    //     ['id' => 'partner-orders', 'url' => 'https://partner.example.com/hooks', 'event-types' => ['order.*'], 'secret' => env('PARTNER_CALLBACK_SECRET')],
    // ],
    // …
],
```

`event-types` es a lo que se suscribe el dispatcher en el bus — una suscripción por patrón, ninguna mientras la lista esté vacía — y cada suscripción lo estrecha después con sus propios patrones. El socio de Lumen, un servicio de conciliación, quiere enterarse de cada movimiento de dinero y de nada más:

<!-- source: samples/lumen/tests/Application/LumenCallbacksTestCase.php -->
```php
            'firefly.callbacks.enabled' => true,
            'firefly.callbacks.event-types' => ['Funds*'],
            'firefly.callbacks.retry.initial-delay' => 0,
            'firefly.callbacks.subscriptions' => [[
                'id' => 'partner-ledger',
                'name' => 'partner ledger',
                'url' => 'https://partner.example.com/hooks/wallet',
                'event-types' => ['FundsDeposited', 'FundsWithdrawn'],
                'secret' => self::SECRET,
            ]],
```

Los patrones comparan con el `eventType` del envoltorio, exactamente como hace `#[EventListener]`, y los eventos de dominio de Lumen llegan al bus con su nombre corto de clase — la lección que `LedgerProjector` enseñó en el Capítulo 8 se aplica sin cambios. Con `registry.store=database`, las suscripciones son filas de `firefly_callback_subscriptions`, gestionadas en tiempo de ejecución a través del puerto `SubscriptionRegistry` en lugar de la configuración.

---

## Cómo es una entrega

Una entrega es un `POST` JSON cuyo cuerpo queda fijado al crear la ejecución, así que cada intento y cada repetición envían los mismos bytes:

<!-- illustrative: the shape of the body a partner receives; the values are an example FundsDeposited -->
```json
{"id": "9f2c…", "eventId": "4b1e…", "eventType": "FundsDeposited", "occurredAt": "2026-10-13T10:00:00+00:00", "data": {"walletId": "w-1", "amountMinor": 700, "currency": "EUR", "balanceMinor": 700}}
```

Las cabeceras son `Content-Type: application/json`, `User-Agent: LaraFly-Callbacks`, `X-Firefly-Event`, `X-Firefly-Event-Id`, `X-Firefly-Delivery` (el id de la ejecución — la clave de idempotencia del receptor), las cabeceras propias de la suscripción y, cuando tiene secreto, `X-Firefly-Timestamp: <unix>` y `X-Firefly-Signature: sha256=<hex HMAC-SHA256(secret, "<timestamp>.<body>")>`. La marca de tiempo está dentro de la firma, así que una entrega capturada no puede repetirse más tarde. La prueba de Lumen comprueba la firma como lo haría el socio, con el propio validador `hmac` del paquete de webhooks:

<!-- source: samples/lumen/tests/Application/PartnerCallbacksTest.php -->
```php
        // The partner's own check, as a LaraFly receiver on firefly/webhooks would run it: throws on a bad signature
        // or a timestamp outside the five-minute window.
        (new HmacSignatureValidator(LumenCallbacksTestCase::SECRET, 'X-Firefly-Signature', 'sha256=', 'sha256', 'hex', 'X-Firefly-Timestamp', 300.0))
            ->verify(new WebhookRequest('lumen', 'POST', $request->url(), $headers, $request->body(), [], 'application/json', time()));
```

Así que un socio LaraFly recibe un callback de LaraFly solo con configuración:

<!-- illustrative: the provider entry a receiving application writes into its own config/firefly.php -->
```php
// config/firefly.php
return [
    'webhooks' => [
        'enabled' => true,
        'providers' => [
            'lumen' => [
                'secret' => env('LUMEN_CALLBACK_SECRET'),
                'signature-header' => 'X-Firefly-Signature',
                'timestamp-header' => 'X-Firefly-Timestamp',
                'idempotency-header' => 'X-Firefly-Delivery',
                'event-type-header' => 'X-Firefly-Event',
            ],
        ],
    ],
];
```

---

## Reintentos, el circuit breaker y el registro de ejecuciones

- **Qué cuenta como entregado.** Un 2xx. Un 408, 429, 5xx o error de transporte se intenta de nuevo tras `retry.initial-delay` × `retry.multiplier`^(n−1), con tope en `retry.max-delay`, hasta `retry.max-attempts` (gana el `max-attempts` propio de una suscripción). Cualquier otro estado es la respuesta definitiva del receptor: fallida, sin reintento. Las redirecciones no se siguen.
- **Un breaker por endpoint.** Cada `scheme://host:port` tiene un circuit breaker de `firefly/resilience`: tras `circuit-breaker.failure-threshold` fallos se abre durante `circuit-breaker.wait-duration-in-open`, y las entregas a ese endpoint fallan en el acto con `circuit open` en lugar de esperar a un socio caído.
- **Adónde pueden apuntar las URLs.** Solo https salvo que `http.require-https` sea false, y solo a un host de `http.allowed-hosts` (o a un subdominio suyo) cuando esa lista está fijada. La URL que se comprueba y a la que se envía es siempre la actual de la suscripción.
- **Dónde corren los intentos.** Allí donde se consume el evento. En el bus en memoria es la petición que publicó el evento — la prueba de Lumen ve el POST antes de que `send()` vuelva — así que elige `queue`, un broker o el outbox (Capítulo 8) para llevar las entregas a workers. Un evento reentregado corresponde a la misma ejecución: una ya entregada no se envía otra vez.

`log.store=memory` guarda las entregas del proceso; `database` las guarda en `firefly_callback_executions`, y los dos comandos lo leen:

<!-- illustrative: operator commands; the id comes from the list output -->
```bash
php artisan firefly:callbacks:list --executions --status=failed --limit=50
php artisan firefly:callbacks:replay --failed
php artisan firefly:callbacks:replay 9f2c4e7a-1b2c-4d5e-8f90-a1b2c3d4e5f6
```

Una repetición reenvía el cuerpo registrado, firmado de nuevo con una marca de tiempo fresca, a la URL **actual** de la suscripción, pasando por las mismas comprobaciones de https y de hosts permitidos; rechaza una suscripción inactiva salvo que se pase `--include-inactive`, y termina con código 1 si alguna repetición no acabó entregada, así que una repetición programada informa de sus propios fallos.

!!! laravel "Paridad con Laravel"
    Laravel no tiene una primitiva para webhooks de entrada: el idioma es un controlador por proveedor, una comprobación de firma escrita a mano y una tabla `processed_webhooks` que te acuerdas de consultar — Cashier trae uno de esos controladores, solo para Stripe. De salida, el idioma es un job en cola por evento con `Http::post()` y tu propio código de reintento y firma. `firefly/webhooks` y `firefly/callbacks` son esos idiomas convertidos en configuración, con las partes difíciles — comprobaciones de firma en tiempo constante sobre bytes en crudo, una reclamación de idempotencia atómica, un breaker por endpoint, un registro reproducible — escritas una sola vez.

---

## Lo que aprendiste {.recap}

| Concepto | Qué hace |
|---|---|
| `POST /webhooks/{provider}` | Un endpoint por proveedor configurado: límite de tamaño, firma, ventana de repetición, idempotencia y después una publicación en el bus |
| Validadores | `hmac` (por defecto), `stripe`, `github`, `twilio`, `none` — cada uno conoce la firma, la clave de idempotencia y el tipo de evento de su proveedor |
| `webhook.{provider}.{type}` | El tipo de evento con el que se publica una entrega aceptada; el código que reacciona es un `#[EventListener]` corriente |
| Almacén de idempotencia | `cache` (por defecto), `database` (`firefly_webhook_receipts`) o `memory`; una reclamación atómica, así que una reentrega es un 200 `duplicate` |
| Un listener que falla | El bus lo convierte en carta muerta y la entrega sigue siendo un 202; `firefly:eda:dead-letters:replay` la entrega de nuevo |
| `firefly.callbacks.subscriptions` / `SubscriptionRegistry` | Quién recibe qué tipos de evento, desde configuración (`memory`) o desde filas (`database`) |
| `X-Firefly-Timestamp` / `X-Firefly-Signature` | HMAC-SHA256 sobre `"<timestamp>.<body>"` — exactamente lo que comprueba el validador `hmac` |
| Reintentos, breaker, registro | Espera creciente ante 408/429/5xx, un circuit breaker por endpoint, un registro de ejecuciones que leen `firefly:callbacks:list` y `firefly:callbacks:replay` |

---

## Ponlo en práctica {.exercises}

1. **Falsifica una entrega.** En `CardTopUpWebhookTest`, firma el cuerpo con otro secreto y comprueba que el 401 nombra al proveedor en su miembro `provider`.
2. **Añade un segundo proveedor.** Da a `LumenWebhooksTestCase` un proveedor `stripe` (`'validator' => 'stripe'`) y escribe una prueba que envíe una cabecera `Stripe-Signature: t=…,v1=…` construida con `hash_hmac('sha256', "{$t}.{$body}", $secret)`. Después mueve `t` diez minutos al pasado y comprueba que el 401 es `WEBHOOK_TIMESTAMP_OUT_OF_TOLERANCE`.
3. **Mira cómo se abre el breaker.** En `PartnerCallbacksTest`, simula al socio con `Http::response('', 503)`, fija `firefly.callbacks.circuit-breaker.failure-threshold` a `1`, haz dos ingresos y lee el error de la segunda ejecución con `ExecutionLog::recent()`.
