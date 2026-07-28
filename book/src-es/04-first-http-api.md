<span class="eyebrow">Parte I — Fundamentos · Capítulo 4</span>

# Tu Primera API HTTP {.chtitle}

Al terminar este capítulo sabrás cómo `#[RestController]` y `#[RequestMapping]` convierten una clase corriente en una superficie HTTP enrutada, cómo cada uno de los cinco atributos de vinculación de parámetros extrae una pieza de una petición hacia un argumento tipado del método, cómo `#[Valid]` condiciona un cuerpo de petición a las restricciones de Bean-Validation antes de que tu manejador siquiera se ejecute, y cómo cada error — un fallo de validación, un monedero inexistente, una comprobación de autorización denegada — se renderiza con la misma forma predecible `application/problem+json`. Este capítulo cierra la Parte I convirtiendo el dominio del monedero de Lumen en una API REST real y validada.

!!! note "Término nuevo: despacho"
    El **despacho** (*dispatch*) es el acto de enrutar una petición HTTP entrante hacia el método correcto del controlador correcto, con los argumentos correctos ya extraídos y convertidos. En LaraFly esto ocurre a través de rutas nativas de Laravel — no hay un enrutador de LaraFly aparte compitiendo con el propio de Laravel.

---

## `#[RestController]` y `#[RequestMapping]`

Ya sabes, desde el Capítulo 2, que `#[RestController]` es en sí mismo un estereotipo `#[Component]` — `extends Firefly\Container\Attributes\Component`, así que un controlador se descubre con el mismo escaneo de componentes que un `#[Service]` o un `#[Repository]`, y obtiene inyección de dependencias por constructor completa de forma gratuita. Lo que el Capítulo 2 dejó pendiente es el *otro* recorrido en el que participa un `#[RestController]`: una reflexión aparte, `RouteScanner`, que lee un conjunto distinto de atributos sobre la misma clase para construir la tabla de enrutamiento.

Aquí está toda la API de monedero de Lumen — el ejemplo que recorre el resto de este capítulo — en un único listado real y distribuido:

```php
<?php

declare(strict_types=1);

namespace Lumen\Web;

use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Query\QueryBus;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Validation\Valid;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;
use Lumen\Application\Command\Deposit;
use Lumen\Application\Command\OpenWallet;
use Lumen\Application\Command\Transfer;
use Lumen\Application\Command\Withdraw;
use Lumen\Application\Query\GetBalance;
use Lumen\Application\Query\GetLedger;
use Lumen\Domain\Currency;
use Lumen\Domain\LedgerEntry;
use Lumen\Web\Dto\AmountRequest;
use Lumen\Web\Dto\OpenWalletRequest;
use Lumen\Web\Dto\TransferRequest;

/**
 * The wallet REST surface: thin HTTP-onto-CQRS mapping, no business logic. Every method builds a
 * command/query, dispatches it through the bus, and shapes the return array — the same rule the sample's
 * CQRS handlers already enforce for the domain layer. A domain/business fault raised by the bus (e.g. an
 * unknown wallet's ResourceNotFoundException, an overdraw's ConflictException, or a denied #[PreAuthorize]
 * on withdraw) surfaces as CommandProcessingException/QueryProcessingException and renders RFC-7807
 * problem-details via the framework's global ProblemDetailsRenderer — no local #[ExceptionHandler] needed.
 */
#[RestController]
#[RequestMapping('/api/v1/wallets')]
final class WalletController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

    /** @return array{wallet_id: string} */
    #[PostMapping(status: 201)]
    public function open(#[Valid] #[RequestBody] OpenWalletRequest $body): array
    {
        /** @var string $id */
        $id = $this->commands->send(new OpenWallet($body->owner_id, Currency::from($body->currency)));

        return ['wallet_id' => $id];
    }

    /** @return array{wallet_id: string, balance_minor: int} */
    #[PostMapping('/{id}/deposit')]
    public function deposit(#[PathVariable] string $id, #[Valid] #[RequestBody] AmountRequest $body): array
    {
        /** @var int $balance */
        $balance = $this->commands->send(new Deposit($id, $body->amount_minor));

        return ['wallet_id' => $id, 'balance_minor' => $balance];
    }

    /**
     * Debits `amount_minor` from the wallet. Guarded upstream at the bus: WithdrawHandler carries
     * #[PreAuthorize("hasRole('ADMIN') or hasRole('WALLET_OWNER')")] (S6), enforced by
     * SecurityCommandAuthorizer BEFORE the handler runs. Without an authorized principal in the
     * SecurityContextHolder, the bus denies the command and the AuthorizationException it wraps renders
     * as a 403 problem-details response — this endpoint is secured, not broken.
     *
     * @return array{wallet_id: string, balance_minor: int}
     */
    #[PostMapping('/{id}/withdraw')]
    public function withdraw(#[PathVariable] string $id, #[Valid] #[RequestBody] AmountRequest $body): array
    {
        /** @var int $balance */
        $balance = $this->commands->send(new Withdraw($id, $body->amount_minor));

        return ['wallet_id' => $id, 'balance_minor' => $balance];
    }

    /**
     * Moves `amount_minor` from the source wallet to the destination wallet as one atomic unit of work
     * (TransferHandler's #[Transactional] boundary), then reads back both fresh balances for the response.
     *
     * @return array{source_wallet_id: string, destination_wallet_id: string, source_balance_minor: int, destination_balance_minor: int}
     */
    #[PostMapping('/transfers')]
    public function transfer(#[Valid] #[RequestBody] TransferRequest $body): array
    {
        $this->commands->send(new Transfer($body->source_wallet_id, $body->destination_wallet_id, $body->amount_minor));

        /** @var int $sourceBalance */
        $sourceBalance = $this->queries->ask(new GetBalance($body->source_wallet_id));
        /** @var int $destinationBalance */
        $destinationBalance = $this->queries->ask(new GetBalance($body->destination_wallet_id));

        return [
            'source_wallet_id' => $body->source_wallet_id,
            'destination_wallet_id' => $body->destination_wallet_id,
            'source_balance_minor' => $sourceBalance,
            'destination_balance_minor' => $destinationBalance,
        ];
    }

    /** @return array{wallet_id: string, balance_minor: int} */
    #[GetMapping('/{id}/balance')]
    public function balance(#[PathVariable] string $id): array
    {
        /** @var int|null $balance */
        $balance = $this->queries->ask(new GetBalance($id));
        if ($balance === null) {
            throw new ResourceNotFoundException("Wallet {$id} not found");
        }

        return ['wallet_id' => $id, 'balance_minor' => $balance];
    }

    /** @return array{wallet_id: string, entries: list<LedgerEntry>} */
    #[GetMapping('/{id}/ledger')]
    public function ledger(#[PathVariable] string $id): array
    {
        /** @var list<LedgerEntry> $entries */
        $entries = $this->queries->ask(new GetLedger($id));

        return ['wallet_id' => $id, 'entries' => $entries];
    }
}
```

Cuatro cosas de esta clase merecen destacarse antes que nada. Primero, `#[RequestMapping('/api/v1/wallets')]` es de nivel de clase y antepone su ruta a cada mapeo de método debajo — `open()` mapea `POST /api/v1/wallets`, `deposit()` mapea `POST /api/v1/wallets/{id}/deposit`. Segundo, el constructor del controlador pide `CommandBus` y `QueryBus` — la misma historia de inyección por constructor que cubrió el Capítulo 2, aplicada a un controlador en vez de a un servicio. Tercero, cada método es una traducción fina: construye un DTO de comando o consulta, se lo entrega al bus, da forma al array que el framework serializará como JSON. Aquí no hay lógica de negocio — ninguna comprobación de sobregiro, ninguna comparación de moneda — porque esa lógica vive en el agregado `Wallet` y sus manejadores de comando, que construyen los Capítulos 5 y 6. Cuarto, el docblock no es decoración: te dice, en el mismo archivo donde viven las rutas, exactamente qué fallos puede producir cada endpoint y cómo se renderizarán — el tema de la sección final de este capítulo.

!!! laravel "Paridad con Laravel"
    `#[RestController]` + `#[RequestMapping]` + los cinco atributos de mapeo de verbo de abajo son el equivalente en LaraFly de `@RestController` + `@RequestMapping` + `@GetMapping`/`@PostMapping` de Spring. En una aplicación Laravel corriente, el artefacto correspondiente es una entrada en `routes/api.php` que apunta a un método de controlador; aquí, la ruta *es* el atributo, declarado sobre el método al que despacha, así que hay exactamente un sitio donde mirar para ambas cosas.

---

## Los atributos de mapeo de verbo

Cada método lleva exactamente un atributo de verbo, y los cinco implementan la misma interfaz marcadora `Mapping`, así que `RouteScanner` los descubre polimórficamente — con `ReflectionAttribute::IS_INSTANCEOF`, el mismo truco que el Capítulo 2 usó para los estereotipos — en vez de necesitar código de escaneo separado por verbo:

| Atributo | Método HTTP |
|---|---|
| `#[GetMapping]` | GET |
| `#[PostMapping]` | POST |
| `#[PutMapping]` | PUT |
| `#[PatchMapping]` | PATCH |
| `#[DeleteMapping]` | DELETE |

Cada uno de los cinco acepta los mismos tres argumentos de constructor — una `path` relativa (unida a la base de nivel de clase), un `status` entero (el código de respuesta por defecto: 200 para los verbos de lectura, y `status: 201` en `WalletController::open()` arriba, ya que abrir un monedero es una creación), y un `name` de ruta opcional:

```php
#[Attribute(Attribute::TARGET_METHOD)]
final class GetMapping implements Mapping
{
    public function __construct(
        public readonly string $path = '',
        public readonly int $status = 200,
        public readonly ?string $name = null,
    ) {}

    public function method(): string
    {
        return 'GET';
    }
}
```

`PostMapping`, `PutMapping`, `PatchMapping` y `DeleteMapping` tienen la misma forma, cada una simplemente devolviendo su propia cadena de método HTTP desde `method()`.

---

## Vinculación de parámetros: cinco atributos, dos convenciones

El Capítulo 2 explicó cómo el contenedor resuelve el constructor de un *bean*. Los parámetros de un *método* de controlador se resuelven de forma distinta — por `ArgumentResolver`, que lee un atributo de vinculación por parámetro y extrae exactamente una pieza de la petición hacia él:

| Atributo | Origen | Notas |
|---|---|---|
| `#[PathVariable(name?)]` | segmento de ruta | siempre obligatorio; por defecto usa el propio nombre del parámetro |
| `#[QueryParam(name?, default?, required?)]` | cadena de consulta | no obligatorio por defecto |
| `#[RequestBody]` | cuerpo de la petición | decodificado vía el `MessageConverter` negociado; combínalo con `#[Valid]` para condicionar sobre validación |
| `#[RequestHeader(name?, default?)]` | cabecera HTTP | no obligatorio |
| `#[UploadedFile(name?)]` | archivo `multipart/form-data` | un `Firefly\Web\Http\UploadedFile` neutral al framework (filename/mimeType/size + `contents()`/`store()` perezosos) |

`WalletController::balance()`, en el listado completo de arriba, muestra el más sencillo de estos: `#[GetMapping('/{id}/balance')]` emparejado con `#[PathVariable] string $id`, que lee el segmento de ruta `{id}` directamente en un parámetro de cadena tipado.

`open()` y `deposit()` muestran la combinación que usarás en cada endpoint de escritura — `#[Valid] #[RequestBody]` apilados sobre el mismo parámetro, cubierta en detalle en la siguiente sección.

Dos formas más de parámetro no necesitan **ningún atributo en absoluto**, y ambas son convenciones genuinas, no omisiones. Un parámetro tipado como una clase sin atributo de vinculación — una interfaz de servicio, por ejemplo — se resuelve como un simple **servicio del contenedor**, exactamente como lo haría `$container->make($type)`; esto le permite a un método alcanzar un colaborador adicional sin añadirlo al propio constructor del controlador. Un parámetro **escalar** sin atributo de vinculación por defecto se convierte en un parámetro de consulta con clave igual a su propio nombre, obligatorio a menos que el propio parámetro tenga un valor por defecto o sea anulable.

Los valores de ruta y de consulta se convierten al tipo escalar declarado del parámetro — `int`, `float` o `bool` — y un valor no convertible, o uno obligatorio ausente, lanza `InvalidRequestException` (HTTP 400, con códigos `MISSING_PARAMETER` / `TYPE_CONVERSION_ERROR`), renderizado a través del mismo flujo de detalles de problema con el que termina este capítulo.

---

## Los DTOs de petición

Cada endpoint de escritura en `WalletController` acepta un DTO pequeño y real — al estilo Pydantic, aquí en PHP puro. Los tres viven en `samples/lumen/src/Web/Dto/` y son clases puras con parámetros promovidos por constructor y restricciones de validación por propiedad:

```php
<?php

declare(strict_types=1);

namespace Lumen\Web\Dto;

use Firefly\Validation\Constraint\CurrencyCode;
use Firefly\Validation\Constraint\NotBlank;

/**
 * Validated request body for `POST /api/v1/wallets`: an owner id and an ISO-4217 currency code. `#[Valid]` on the
 * controller parameter runs BeanValidator against these constraints BEFORE the DTO is hydrated (ArgumentResolver),
 * so an invalid body never reaches WalletController::open() — it renders as a 422 RFC-7807 payload instead.
 */
final class OpenWalletRequest
{
    public function __construct(
        #[NotBlank]
        public readonly string $owner_id,
        #[NotBlank]
        #[CurrencyCode]
        public readonly string $currency,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace Lumen\Web\Dto;

use Firefly\Validation\Constraint\Positive;

/**
 * Validated request body shared by `POST /api/v1/wallets/{id}/deposit` and `.../withdraw`: a strictly positive
 * amount in minor units. Both Deposit and Withdraw take an existing wallet id from the path and only the amount
 * from the body, so one DTO covers both endpoints.
 */
final class AmountRequest
{
    public function __construct(
        #[Positive]
        public readonly int $amount_minor,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace Lumen\Web\Dto;

use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\Positive;

/**
 * Validated request body for `POST /api/v1/wallets/transfers`: both wallet ids travel in the body (a transfer is
 * not scoped under a single wallet's path) plus a strictly positive amount in minor units.
 */
final class TransferRequest
{
    public function __construct(
        #[NotBlank]
        public readonly string $source_wallet_id,
        #[NotBlank]
        public readonly string $destination_wallet_id,
        #[Positive]
        public readonly int $amount_minor,
    ) {}
}
```

Que `AmountRequest` sirva a la vez para depositar y retirar es una decisión de diseño deliberada, no pereza — ambas operaciones mueven una cantidad positiva de dinero en unidades menores, y el id del monedero ya vive en la ruta, no en el cuerpo, así que un único DTO compartido mantiene idénticos los contratos de ambos endpoints sin una clase base compartida.

---

## `#[Valid]` → `BeanValidator` → 422

`#[Valid]`, de `firefly/validation`, es lo que convierte esos atributos por propiedad de metadatos inertes en una barrera efectivamente aplicada. Apilarlo sobre un parámetro `#[RequestBody]` — como hace cada método de escritura de `WalletController` — le dice al despachador: valida el *cuerpo decodificado en bruto* contra las reglas de restricción compiladas de esta clase DTO **antes** de siquiera construir el DTO.

```php
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Valid {}
```

La validación en sí corre a través de `Firefly\Validation\Constraint\BeanValidator`, que delega en un puerto `Validator` (`IlluminateValidator` por defecto) usando reglas que un `ConstraintManifest` compiló de antemano a partir del método `toRules()` de cada atributo de restricción:

```php
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class NotBlank implements Constraint
{
    public function toRules(): array
    {
        return ['required', 'string', 'regex:/\S/'];
    }
}
```

```php
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Positive implements Constraint
{
    public function toRules(): array
    {
        return ['numeric', 'gt:0'];
    }
}
```

Cada atributo de restricción se reduce, de esta forma, a una o más reglas de validación de Illuminate corrientes — `#[NotBlank]` rechaza `null`, `''`, `[]`, y una cadena compuesta solo por espacios; `#[Positive]` exige un valor numérico estrictamente mayor que cero; `#[CurrencyCode]` (usado en `OpenWalletRequest::$currency`) delega en un objeto de regla `Currency` dedicado en vez de en una cadena de regla. Si falla, `BeanValidator` lanza el `ValidationException` del kernel — el mismo tipo de excepción que la sección final de este capítulo mapea a HTTP 422 — llevando un `FieldError` por cada propiedad fallida. Si tiene éxito, el DTO se construye a partir del cuerpo decodificado **en bruto**, no de un subconjunto producido por el validador, así que una propiedad sin ningún atributo de restricción nunca se descarta silenciosamente.

!!! note "Nota"
    Las restricciones validan el array de petición en bruto, no el DTO ya construido. Este orden importa: `#[Valid]` garantiza que tu método manejador solo recibe jamás un DTO cuyos campos restringidos ya han pasado todas las comprobaciones — no existe ningún camino en el que `WalletController::open()` se ejecute con un `OpenWalletRequest` cuyo `owner_id` esté en blanco.

!!! laravel "Paridad con Laravel"
    `#[Valid] #[RequestBody]` es el equivalente en LaraFly del par `@Valid @RequestBody` de Spring. En una aplicación Laravel corriente, lo más parecido es una clase Form Request con un método `rules()`; los atributos por propiedad de `firefly/validation` trasladan esas mismas reglas a los propios campos del DTO, así que la restricción y el campo que restringe se declaran en el mismo sitio.

`firefly/validation` distribuye dieciséis restricciones del dominio financiero además de las de propósito general (`#[NotBlank]`, `#[NotEmpty]`, `#[NotNull]`, `#[Size]`, `#[Min]`/`#[Max]`, `#[Pattern]`, `#[Email]`, y más): `#[Iban]`, `#[Bic]`, `#[Swift]`, `#[Isin]`, `#[Cusip]`, `#[RoutingNumber]`, `#[Luhn]`, `#[CurrencyCode]`, `#[CountryCode]`, `#[LanguageTag]`, `#[UuidValue]`, `#[Phone]`, `#[PostalCode]`, `#[Percentage]`, `#[Money]` y `#[DecimalScale]` — más un escape `#[Rules]` para cualquier cadena de regla de Illuminate en bruto u objeto `ValidationRule` que necesites y que ningún atributo dedicado cubra todavía.

---

## Negociación de contenido

Una vez que un manejador retorna, algo todavía tiene que decidir el formato sobre el cable. `firefly/web` distribuye hoy un solo `MessageConverter` — `JsonMessageConverter`, que coincide con `application/json` y cualquier tipo con sufijo `+json` — elegido a partir de la cabecera `Accept` de la petición (analizada por valores q, con desempate por orden de cabecera) y recurriendo a JSON cuando nada coincide o la cabecera está ausente. Los cuerpos de petición se leen de la misma forma, según `Content-Type`. `MessageConverterRegistry` es un enlace de contenedor corriente y protegido, así que una aplicación puede registrar convertidores adicionales — el soporte de XML es un punto de extensión deliberadamente aplazado, no una funcionalidad distribuida.

---

## Errores en los que los clientes pueden confiar

Una API bien diseñada nunca filtra una traza de pila en bruto, y nunca devuelve un 500 desnudo por algo que quien llama podría haber evitado. La jerarquía de excepciones de `firefly/kernel` es la columna vertebral que hace esto automático: cada excepción del framework extiende `FireflyException`, que lleva un `errorCode()` estable, un `httpStatus()`, una `category()` y una `severity()` — así que lanzar el *tipo* correcto de excepción es la única preocupación HTTP que tu código manejador jamás tiene que pensar.

| Excepción | Código | Estado |
|---|---|---|
| `Business\ResourceNotFoundException` | `RESOURCE_NOT_FOUND` | 404 |
| `Business\ConflictException` | `CONFLICT` | 409 |
| `Business\ValidationException` | `VALIDATION_ERROR` | 422 |
| `Security\AuthenticationException` | `AUTHENTICATION_FAILED` | 401 |
| `Security\AuthorizationException` | `ACCESS_DENIED` | 403 |
| `Infrastructure\ServiceUnavailableException` | `SERVICE_UNAVAILABLE` | 503 |

`WalletController::balance()` lanza directamente la primera de estas, exactamente como debería hacerlo cualquier manejador:

```php
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;

throw new ResourceNotFoundException("Wallet {$id} not found");
```

El agregado `Wallet` del Capítulo 6 lanza `ConflictException` por un sobregiro o un desajuste de moneda, y el `#[PreAuthorize]` de `WithdrawHandler` (el Capítulo 6 lo toca brevemente, la seguridad completa llega más adelante) puede denegar una petición antes de que el cuerpo del manejador siquiera se ejecute, envolviendo una `AuthorizationException`. Las tres llegan al mismo renderizador.

### El renderizador RFC-7807

`Firefly\Web\Exception\ProblemDetailsRenderer` es donde cada una de esas excepciones realmente se convierte en bytes sobre el cable:

```php
final class ProblemDetailsRenderer
{
    public function render(Throwable $e, Request $request): Response
    {
        $exception = match (true) {
            $e instanceof FireflyException => $e,
            $e instanceof HttpExceptionInterface => new FireflyException(
                $e->getMessage() !== '' ? $e->getMessage() : self::statusText($e->getStatusCode()),
                self::errorCode($e->getStatusCode()),
                $e->getStatusCode(),
                ErrorCategory::Framework,
                ErrorSeverity::Warning,
                $e,
            ),
            default => new FireflyException(
                $e->getMessage() !== '' ? $e->getMessage() : 'Internal Server Error',
                'INTERNAL_ERROR',
                500,
                ErrorCategory::Internal,
                ErrorSeverity::Error,
                $e,
            ),
        };

        $payload = ErrorResponse::fromException(
            $exception,
            instance: $request->path(),
            timestamp: (new DateTimeImmutable)->format(DateTimeInterface::ATOM),
        )->toArray();

        return new Response(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $exception->httpStatus(),
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
```

Tres casos se pliegan sobre la misma forma de respuesta. Una `FireflyException` (o una de sus subclases tipadas, como `ResourceNotFoundException`) se renderiza tal cual, con su propio `httpStatus()`. Una excepción de enrutamiento de Laravel/Symfony — una URL sin ninguna ruta coincidente — se convierte **preservando su código de estado real**, así que una ruta no coincidente sigue respondiendo 404, nunca un 500 engañoso. Cualquier otra cosa — un `Throwable` genuinamente inesperado — se convierte en una `FireflyException` genérica, de categoría `Internal`, HTTP 500. Cada camino termina en la misma llamada `ErrorResponse::fromException(...)->toArray()`, así que la forma de la carga útil nunca depende de qué rama la produjo.

Pedir un monedero que nunca se abrió se renderiza así:

```json
{
  "status": 404,
  "title": "Not Found",
  "code": "RESOURCE_NOT_FOUND",
  "category": "business",
  "severity": "warning",
  "detail": "Wallet wlt-999 not found",
  "instance": "/api/v1/wallets/wlt-999",
  "timestamp": "2026-06-07T10:30:00+00:00"
}
```

Un fallo de comprobación `#[Valid]` en `POST /api/v1/wallets` — un `owner_id` vacío — lleva además un array `errors`, una entrada por cada campo fallido:

```json
{
  "status": 422,
  "title": "Unprocessable Entity",
  "code": "VALIDATION_ERROR",
  "category": "validation",
  "severity": "warning",
  "detail": "Validation failed",
  "instance": "/api/v1/wallets",
  "errors": [
    {"field": "owner_id", "message": "is required"}
  ]
}
```

Y un intento de retiro sin ningún principal autenticado `ADMIN` o `WALLET_OWNER` — denegado por el `#[PreAuthorize]` de `WithdrawHandler` antes de que el cuerpo del manejador siquiera se ejecute — se renderiza en 403, sin ningún cambio de código en `WalletController::withdraw()`:

```json
{
  "status": 403,
  "title": "Forbidden",
  "code": "ACCESS_DENIED",
  "category": "security",
  "severity": "warning",
  "detail": "Access is denied",
  "instance": "/api/v1/wallets/wlt-1/withdraw"
}
```

### Manejar una excepción tú mismo

Antes de que una excepción llegue al renderizador genérico, LaraFly le da a tu aplicación la oportunidad de responderla directamente. `#[ExceptionHandler(SomeException::class)]` marca un método como el renderizador de una clase de excepción concreta (o cualquier subclase) — declarado bien **en el propio controlador que la lanzó**, bien en un bean `#[ControllerAdvice]` dedicado para un manejador **global** compartido entre todos los controladores:

```php
use Firefly\Web\Attributes\ControllerAdvice;
use Firefly\Web\Attributes\ExceptionHandler;
use Lumen\Domain\Exception\WalletFrozenException;

#[ControllerAdvice]
final class WalletExceptionAdvice
{
    #[ExceptionHandler(WalletFrozenException::class)]
    public function onFrozen(WalletFrozenException $e): array
    {
        return ['code' => 'WALLET_FROZEN', 'message' => $e->getMessage()];
    }
}
```

Un manejador local del controlador siempre gana sobre uno global `#[ControllerAdvice]` para la misma excepción; dentro del ámbito que gane, se elige la clase coincidente más específica en la jerarquía. El valor de retorno de un manejador que coincide se negocia por contenido exactamente igual que cualquier otro retorno de controlador, pero se renderiza con el propio `httpStatus()` de la excepción en vez del estado por defecto de la ruta. Si nada coincide en ninguno de los dos ámbitos, la excepción cae hacia `ProblemDetailsRenderer` — así que cada error sin manejar, empiece como empiece, sigue terminando como `application/problem+json`, nunca como una página de error de framework sin capturar.

::: figure art/figures/request-lifecycle.svg | Figura 4.1 — Una petición atraviesa la cadena de filtros web, el despacho de rutas, la vinculación de argumentos y la validación y — ante un fallo en cualquier punto — el mismo renderizador RFC-7807, antes de que tu método manejador siquiera se ejecute.

---

## El `RouteManifest` compilado

Ya has visto la misma forma dos veces — el manifiesto de componentes del Capítulo 2, el manifiesto de config-properties de este capítulo — y el enrutamiento la sigue por tercera vez. `RouteScanner` es el único sitio de reflexión en `packages/web/src`: recorre las clases `#[RestController]`, y por cada método mapeado con un verbo compila un `RouteDescriptor` — el método HTTP, la ruta unida, el nombre del controlador y del método, el estado por defecto, un nombre de ruta opcional, y un plan de vinculación de parámetros en array puro. `RouteManifestCompiler` vuelca el resultado con `var_export()` a `bootstrap/cache/firefly/routes.php`; `RouteManifest::load()` lo vuelve a leer sin ninguna reflexión en absoluto.

En el arranque, `RouteWiringPass` registra una **ruta nativa de Laravel** por cada descriptor y le entrega a cada una un cierre de despacho a través de `ControllerDispatcher` — así que el listado de rutas, la generación de URLs, y el propio `route:cache` de Laravel se aplican todos a las rutas de `WalletController` exactamente igual que a cualquier entrada escrita a mano en `routes/api.php`, porque eso es genuinamente lo que son, una vez que se ha ejecutado `firefly:cache`.

---

## Lo que construiste {.recap}

La Parte I está completa. Lumen ahora **arranca** (Capítulo 1), está **conectado** (la inyección por constructor del Capítulo 2 sin código de cableado), está **configurado** (los ajustes tipados y conscientes de perfil del Capítulo 3), y **sirve** — una API REST validada en `/api/v1/wallets` con:

| Pieza | Qué hace |
|---|---|
| `#[RestController]` + `#[RequestMapping]` | Registra el controlador como bean y fija su prefijo de URL |
| `#[GetMapping]`/`#[PostMapping]`/… | Un atributo de verbo por método, compilado en un `RouteDescriptor` |
| `#[PathVariable]` / `#[QueryParam]` / `#[RequestBody]` / `#[RequestHeader]` / `#[UploadedFile]` | Cinco formas de extraer una pieza de la petición hacia un parámetro tipado |
| `#[Valid]` | Condiciona un DTO `#[RequestBody]` a sus reglas de restricción compiladas, lanzando `ValidationException` si falla |
| La jerarquía `FireflyException` | Excepciones tipadas que llevan su propio código de error, estado HTTP, categoría y severidad |
| `ProblemDetailsRenderer` | Convierte cualquier excepción en una respuesta `application/problem+json` consistente |
| `#[ExceptionHandler]` / `#[ControllerAdvice]` | Un escape para renderizar tú mismo una excepción concreta, local o globalmente |

El Capítulo 5 no reemplaza nada en `WalletController` — el controlador que acabas de leer se queda exactamente igual — y en cambio construye la capa de repositorio a través de la cual persisten los manejadores de comando detrás de `CommandBus`/`QueryBus`.

---

## Ponlo en práctica {.exercises}

1. **Añade un endpoint `DELETE`.** En un proyecto de pruebas (no en el paquete distribuido `samples/lumen`), añade `#[DeleteMapping('/{id}', status: 204)]` a un nuevo método de `WalletController` que envíe un hipotético comando `CloseWallet` y devuelva `null`. Confirma que la ruta compila y que un `204` sin cuerpo es lo que produce una petición coincidente.
2. **Traza un fallo de validación de principio a fin.** Envía `POST /api/v1/wallets` con `{"owner_id": "", "currency": "XYZ"}` contra una instancia de Lumen en marcha y lee el cuerpo `application/problem+json` resultante. Empareja cada entrada de `errors` con el atributo de restricción en `OpenWalletRequest` que la produjo.
3. **Escribe un `#[ControllerAdvice]`.** Añade un manejador de excepción global para `Firefly\Kernel\Exception\Business\ConflictException` que devuelva una forma personalizada `{"code": "...", "hint": "..."}` en vez de la carga útil de detalles de problema por defecto, y confirma que se aplica a cada controlador de tu proyecto de pruebas, no solo a uno.
