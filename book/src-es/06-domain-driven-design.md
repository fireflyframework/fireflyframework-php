<span class="eyebrow">Parte II — Modelar y Persistir el Dominio · Capítulo 6</span>

# Diseño Guiado por el Dominio {.chtitle}

Al terminar este capítulo conocerás la diferencia que traza `firefly/domain` entre una `Entity` y un `ValueObject`, cómo `AggregateRoot` (y su gemelo en forma de trait, `HasDomainEvents`) convierte a un objeto en la única fuente de sus propios eventos de dominio, cómo se construye e identifica un `DomainEvent`, cómo el agregado `Wallet` hace cumplir sus propias reglas de sobregiro y de moneda sin que nada externo pueda saltárselas, y — cerrando el círculo abierto en el Capítulo 5 — exactamente cómo un evento lanzado viaja desde un método del agregado hasta un hecho publicado al otro lado de una transacción confirmada.

!!! note "Término nuevo: invariante"
    Una **invariante** es una regla que siempre debe cumplirse, sin importar qué camino de código llegó hasta ahí — para `Wallet`, "el saldo nunca es negativo" es una invariante, no una convención. Una invariante vive *dentro* del objeto que protege; una regla aplicada solo por un servicio que quienes la llaman pueden saltarse no es realmente una invariante en absoluto.

---

## Entidades y objetos de valor

`firefly/domain` es deliberadamente el paquete más pequeño de todo el framework: `Entity`, `ValueObject`, `AggregateRoot` y `DomainEvent` — cuatro bloques constructivos de PHP puro con **cero dependencia del framework y cero reflexión**. (Una prueba dedicada busca en el propio código fuente del paquete `ReflectionClass`/`ReflectionMethod`/`getAttributes` y afirma que no hay coincidencias — tu modelo de dominio nunca tiene que saber que LaraFly existe.)

`Entity` traza la primera de las dos distinciones fundacionales de DDD: la identidad, no el valor, decide si dos entidades son la misma cosa.

```php
abstract class Entity
{
    public function __construct(protected int|string|null $id = null) {}

    public function id(): int|string|null
    {
        return $this->id;
    }

    public function isTransient(): bool
    {
        return $this->id === null;
    }

    public function equals(self $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if ($this::class !== $other::class) {
            return false;
        }

        if ($this->isTransient() || $other->isTransient()) {
            return false;
        }

        return $this->id === $other->id;
    }
}
```

Dos entidades son `equals()` solo cuando son la misma clase concreta **y** tienen ids iguales y no nulos. Una entidad **transitoria** — aquella cuyo id todavía es `null`, aún no persistida — es igual solo a sí misma, por pura identidad de objeto; dos instancias transitorias distintas nunca son iguales aunque el resto de su estado sea idéntico. PHP no tiene genéricos en tiempo de ejecución, así que `id()` está tipado `int|string|null` — lo bastante amplio para cubrir tanto una clave entera autoincremental como un id de cadena como el propio `wlt-…` de `Wallet`.

`ValueObject` es la segunda distinción: ninguna identidad en absoluto, inmutable por convención, igual puramente por los valores que contiene.

```php
interface ValueObject {}
```

Es una interfaz marcadora desnuda a propósito — la *igualdad* de un objeto de valor se suministra por separado, mediante un pequeño trait:

```php
trait ValueObjectEquality
{
    public function equals(self $other): bool
    {
        return get_class($this) === get_class($other)
            && get_object_vars($this) == get_object_vars($other);
    }
}
```

`get_object_vars($this)`, no `ReflectionProperty` — deliberadamente, para que `firefly/domain` se mantenga libre de reflexión — compara cada propiedad visible en el ámbito. Esto es exactamente correcto para un objeto de valor `readonly` **plano**; un objeto de valor que anida otro objeto de valor debería componer la igualdad delegando en el propio `equals()` del objeto anidado, en vez de confiar en que `==` recorra correctamente el array de propiedades exterior.

!!! note "Término nuevo: agregado"
    Un **agregado** es un pequeño cúmulo de objetos que debe permanecer consistente como grupo, al que se entra y se muta a través de exactamente un objeto: su **raíz de agregado**. La siguiente sección construye uno.

### `Money`: el objeto de valor de manual

`Money` es el `ValueObject` de `firefly/domain` aplicado al manejo real de divisas de Lumen — código real y distribuido de `samples/lumen/src/Domain/Money.php`:

```php
<?php

declare(strict_types=1);

namespace Lumen\Domain;

use Firefly\Domain\ValueObject;
use Firefly\Domain\ValueObjectEquality;
use Firefly\Kernel\Exception\Business\ConflictException;

final readonly class Money implements ValueObject
{
    use ValueObjectEquality;

    public function __construct(public int $minorUnits, public Currency $currency) {}

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function __toString(): string
    {
        return number_format($this->minorUnits / 100, 2, '.', '').' '.$this->currency->value;
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new ConflictException(
                "cannot combine {$this->currency->value} with {$other->currency->value}"
            );
        }
    }
}
```

Tres decisiones de diseño aquí recompensan una lectura atenta. `$minorUnits` se almacena como un **entero** — céntimos, no un `float` — precisamente para que la aritmética financiera nunca acumule error de redondeo; `Money(1050, Currency::EUR)` son exactamente 10,50 €, y `add`/`subtract` operan directamente sobre ese entero. `add()` y `subtract()` devuelven ambos una instancia **nueva** de `Money` — `final readonly class` hace de esto la única opción, no una disciplina que debas recordar — así que un depósito nunca muta el importe del que partió; nada más en el código puede estar silenciosamente reteniendo una referencia obsoleta a un `Money` que acaba de cambiar por debajo. Y `assertSameCurrency()` es un ayudante privado por el que ambos métodos públicos pasan primero, así que combinar importes en EUR y USD es una `ConflictException` lanzada en el punto exacto de llamada del error, nunca una suma silenciosamente incorrecta descubierta durante la conciliación.

`final readonly class Money implements ValueObject { use ValueObjectEquality; }` es toda la historia de la igualdad — dos instancias de `Money(1050, Currency::EUR)` construidas independientemente son `equals()` entre sí, porque `ValueObjectEquality` compara su estado público, no su identidad de objeto.

!!! laravel "Paridad con Laravel"
    `ValueObject` + `ValueObjectEquality` se corresponden con la idea `@ValueObject`/`@Embeddable` del mundo JPA, y con la propia característica de clase `readonly` de PHP haciendo la mitad de inmutabilidad del trabajo. En una aplicación Laravel/Eloquent corriente, lo más parecido a `Money` es un objeto de casteo de Eloquent — salvo que aquí `Money` no tiene ni idea de que Eloquent existe, y se ejercita en una prueba unitaria con dos argumentos de constructor y nada más.

---

## La raíz de agregado: la única fuente de sus propios eventos

`Money` resuelve la representación. Algo todavía tiene que *poseer* la decisión de si un depósito o un retiro está permitido siquiera — ese es el trabajo de la raíz de agregado. `AggregateRoot` extiende `Entity` y añade exactamente una cosa: un búfer privado de eventos de dominio pendientes, y el único método protegido que añade a él.

```php
abstract class AggregateRoot extends Entity implements RecordsDomainEvents
{
    /** @var list<DomainEvent> */
    private array $pendingEvents = [];

    protected function raiseEvent(DomainEvent $event): void
    {
        $this->pendingEvents[] = $event;
    }

    /** @return list<DomainEvent> */
    public function pendingEvents(): array
    {
        return $this->pendingEvents;
    }

    /** @return list<DomainEvent> */
    public function pullEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    public function clearEvents(): void
    {
        $this->pendingEvents = [];
    }
}
```

`raiseEvent()` es `protected` — deliberadamente. Solo los métodos *propios* del agregado pueden llamarlo, que es lo que convierte a un agregado en la **frontera de consistencia** que prometió la introducción del capítulo: ningún llamador externo puede meterse a hacer que el agregado emita un evento en su nombre, del mismo modo que ningún llamador externo puede meterse a mutar su estado sin pasar por un método que aplique las reglas. `pendingEvents()` es una instantánea que no drena — segura de llamar desde una aserción de prueba o una línea de log sin efectos secundarios. `pullEvents()` drena el búfer y se lo entrega a quien lo pidió, que es lo que llama la maquinaria post-confirmación del framework. `clearEvents()` descarta el búfer sin publicar nada — se usa en un rollback.

`RecordsDomainEvents` es la interfaz que nombra exactamente este contrato del lado del drenaje, deliberadamente **sin** `raiseEvent()` — porque solo el propio agregado puede lanzar sus propios eventos, así que ese método permanece `protected` en cualquier clase que implemente la interfaz:

```php
interface RecordsDomainEvents
{
    /** @return list<DomainEvent> */
    public function pendingEvents(): array;

    /** @return list<DomainEvent> */
    public function pullEvents(): array;

    public function clearEvents(): void;
}
```

### Por qué `Wallet` no extiende `AggregateRoot`

Aquí es donde la historia de agregados de LaraFly tiene que resolver un problema que PyFly y Java nunca enfrentan: PHP tiene herencia simple, y `Wallet` necesita ser a la vez un **modelo Eloquent persistido** y una raíz de agregado. No puede hacer `extends AggregateRoot` y `extends Model` al mismo tiempo. `HasDomainEvents` es el trait que cierra esta brecha — exactamente el mismo búfer y exactamente los mismos cuatro métodos que `AggregateRoot`, pero como un trait que cualquier clase puede `use`, sin importar qué ya extienda:

```php
trait HasDomainEvents
{
    /** @var list<DomainEvent> */
    private array $pendingEvents = [];

    protected function raiseEvent(DomainEvent $event): void
    {
        $this->pendingEvents[] = $event;
    }

    /** @return list<DomainEvent> */
    public function pendingEvents(): array
    {
        return $this->pendingEvents;
    }

    /** @return list<DomainEvent> */
    public function pullEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    public function clearEvents(): void
    {
        $this->pendingEvents = [];
    }
}
```

Aquí está el agregado `Wallet` completo y real — `samples/lumen/src/Domain/Wallet.php` — combinando exactamente este trait con `extends Model` e `implements RecordsDomainEvents`:

```php
<?php

declare(strict_types=1);

namespace Lumen\Domain;

use Firefly\Domain\HasDomainEvents;
use Firefly\Domain\RecordsDomainEvents;
use Firefly\Kernel\Exception\Business\ConflictException;
use Illuminate\Database\Eloquent\Model;
use Lumen\Domain\Event\FundsDeposited;
use Lumen\Domain\Event\FundsWithdrawn;
use Lumen\Domain\Event\WalletOpened;

final class Wallet extends Model implements RecordsDomainEvents
{
    use HasDomainEvents;

    protected $table = 'wallets';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['id', 'owner_id', 'currency', 'balance_minor'];

    public $timestamps = false;

    public static function open(string $id, string $ownerId, Currency $currency): self
    {
        if (trim($ownerId) === '') {
            throw new ConflictException('owner_id is required');
        }

        $wallet = new self([
            'id' => $id,
            'owner_id' => $ownerId,
            'currency' => $currency->value,
            'balance_minor' => 0,
        ]);
        $wallet->raiseEvent(new WalletOpened($id, $ownerId, $currency->value));

        return $wallet;
    }

    public function currency(): Currency
    {
        $value = $this->getAttribute('currency');

        return Currency::from(match (true) {
            is_string($value) => $value,
            default => '',
        });
    }

    public function balanceMoney(): Money
    {
        $value = $this->getAttribute('balance_minor');

        $minor = match (true) {
            is_int($value) => $value,
            is_numeric($value) => (int) $value,
            default => 0,
        };

        return new Money($minor, $this->currency());
    }

    /**
     * `getKey()` is declared `mixed` (a primary key may be any attribute type); Wallet's own key is always the
     * string `id` set in open(), so this narrows for the event payload rather than casting mixed directly.
     */
    private function walletId(): string
    {
        $key = $this->getKey();

        return match (true) {
            is_string($key) => $key,
            is_int($key) => (string) $key,
            default => '',
        };
    }

    public function deposit(Money $amount): void
    {
        $this->assertCurrency($amount);
        if (! $amount->isPositive()) {
            throw new ConflictException('deposit amount must be > 0');
        }
        $new = $this->balanceMoney()->add($amount);
        $this->setAttribute('balance_minor', $new->minorUnits);
        $this->raiseEvent(new FundsDeposited(
            $this->walletId(), $amount->minorUnits, $amount->currency->value, $new->minorUnits
        ));
    }

    public function withdraw(Money $amount): void
    {
        $this->assertCurrency($amount);
        if (! $amount->isPositive()) {
            throw new ConflictException('withdrawal amount must be > 0');
        }
        $remaining = $this->balanceMoney()->subtract($amount);
        if ($remaining->isNegative()) {
            throw new ConflictException(
                "cannot withdraw {$amount}; balance is {$this->balanceMoney()}"
            );
        }
        $this->setAttribute('balance_minor', $remaining->minorUnits);
        $this->raiseEvent(new FundsWithdrawn(
            $this->walletId(), $amount->minorUnits, $amount->currency->value, $remaining->minorUnits
        ));
    }

    private function assertCurrency(Money $amount): void
    {
        if ($amount->currency !== $this->currency()) {
            throw new ConflictException('currency mismatch');
        }
    }
}
```

Esta es una forma genuinamente distinta de la que tendría un lenguaje con herencia múltiple de verdad — y es deliberada, no un rodeo. `Wallet` **es** la fila de Eloquent (`protected $table = 'wallets'`, `$fillable`, una clave de cadena no autoincremental) **y** la raíz de agregado, en un solo objeto. No hay una clase ORM `WalletEntity` separada ni un mapeador cruzando una frontera entre ellas para este agregado: `Wallet::open()` construye a la vez los atributos de la fila *y* lanza `WalletOpened` en el mismo método de fábrica estático, y `deposit()`/`withdraw()` mutan a la vez el atributo de Eloquent *y* lanzan su evento en la misma llamada. El búfer de `HasDomainEvents`, `$pendingEvents`, es una propiedad privada genuinamente **declarada**, no un atributo de base de datos — la magia `__get`/`__set` de Eloquent nunca la intercepta, porque una propiedad declarada siempre eclipsa a los accesores mágicos.

Tres invariantes viven dentro de estos tres métodos, y en ningún otro sitio. `open()` rechaza un `owner_id` en blanco. `deposit()` y `withdraw()` rechazan ambos un importe no positivo y un desajuste de moneda (a través del ayudante compartido `assertCurrency()`). `withdraw()` además rechaza dejar que el saldo se vuelva negativo — la regla de sobregiro. Cada una de estas comprobaciones se ejecuta *antes* de que se llame a `setAttribute()`, así que una operación rechazada deja los atributos persistidos del monedero completamente intactos, y el evento correspondiente nunca se lanza para una operación que nunca ocurrió.

!!! warning "Mantén las invariantes en el modelo, no en el servicio"
    Si la comprobación de sobregiro viviera en un método de servicio en vez de dentro de `withdraw()`, cualquier cosa que llamara a `$wallet->save()` directamente — un trabajo en segundo plano, un script de administración, un futuro desarrollador con prisa — podría saltársela silenciosamente. Como la comprobación está dentro del propio método del agregado, no existe ningún camino de código hacia un `Wallet` sobregirado en absoluto: la regla y la única puerta hacia el estado que protege son el mismo trozo de código.

---

## Eventos de dominio: `DomainEvent`, y los cuatro que distribuye Lumen

`DomainEvent` es la base plana e inmutable que extiende cada evento concreto. Rellena automáticamente dos campos que nunca tienes que fijar tú mismo:

```php
abstract readonly class DomainEvent
{
    public string $eventId;

    public DateTimeImmutable $occurredAt;

    public function __construct(?string $eventId = null, ?DateTimeImmutable $occurredAt = null)
    {
        $this->eventId = $eventId ?? self::uuid4();
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable;
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function eventType(): string
    {
        $class = static::class;
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
```

`eventId` es un uuid-v4 recién generado construido desde `random_bytes()` — sin dependencia de `ramsey/uuid`, sin reflexión — y `occurredAt` por defecto es "ahora" en el momento de la construcción; ambos aceptan también un valor explícito, para reconstitución o para una prueba que necesite una marca de tiempo fija. `eventType()` es el propio nombre corto de la clase concreta — `strrpos`/`substr` sobre `static::class`, de nuevo sin reflexión — y es esta cadena, no la clase PHP, contra la que compara un listener aguas abajo (verás exactamente esa regla de coincidencia en la sección de cierre de este capítulo).

Lumen distribuye cuatro eventos así, uno por cada transición de estado que puede producir el monedero o una transferencia. Aquí están los tres que lanza el propio `Wallet` — código real y distribuido de `samples/lumen/src/Domain/Event/`:

```php
<?php

declare(strict_types=1);

namespace Lumen\Domain\Event;

use Firefly\Cqrs\Attributes\PublishDomainEvent;
use Firefly\Domain\DomainEvent;

#[PublishDomainEvent('wallet.events')]
final readonly class WalletOpened extends DomainEvent
{
    public function __construct(
        public string $walletId,
        public string $ownerId,
        public string $currency,
    ) {
        parent::__construct();
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Lumen\Domain\Event;

use Firefly\Cqrs\Attributes\PublishDomainEvent;
use Firefly\Domain\DomainEvent;

#[PublishDomainEvent('wallet.events')]
final readonly class FundsDeposited extends DomainEvent
{
    public function __construct(
        public string $walletId,
        public int $amountMinor,
        public string $currency,
        public int $balanceMinor,
    ) {
        parent::__construct();
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Lumen\Domain\Event;

use Firefly\Cqrs\Attributes\PublishDomainEvent;
use Firefly\Domain\DomainEvent;

#[PublishDomainEvent('wallet.events')]
final readonly class FundsWithdrawn extends DomainEvent
{
    public function __construct(
        public string $walletId,
        public int $amountMinor,
        public string $currency,
        public int $balanceMinor,
    ) {
        parent::__construct();
    }
}
```

Un cuarto evento, `TransferCompleted`, no lo lanza el propio `Wallet` sino la capa de aplicación una vez que ambas piernas de una transferencia tienen éxito (la siguiente sección muestra exactamente dónde). Cada uno lleva `#[PublishDomainEvent('wallet.events')]` — un atributo de `firefly/cqrs`, no de `firefly/domain`, que nombra el **destino** al que un evento se republica una vez que cruza al mundo de los eventos de integración; `firefly/domain` en sí no tiene ninguna opinión sobre destinos en absoluto, solo sobre lanzar y drenar.

Fíjate en lo que cada evento lleva y no lleva. `FundsDeposited` y `FundsWithdrawn` llevan ambos el saldo **posterior a la operación** (`$balanceMinor`), no solo el importe que se movió — un suscriptor que actualiza un modelo de lectura nunca tiene que recargar el monedero para conocer su nuevo saldo; todo lo que necesita ya está en el hecho que recibió. Ese diseño rinde frutos directamente en `LedgerProjector`, más adelante en este capítulo.

!!! laravel "Paridad con Laravel"
    `AggregateRoot`/`HasDomainEvents` + `DomainEvent` se corresponden con el `AbstractAggregateRoot` de Spring Data y su mecanismo `registerEvent()`/`@DomainEvents`/`@AfterDomainEventPublication`. `raiseEvent()` es `registerEvent()`; `pullEvents()` es el drenaje que `@AfterDomainEventPublication` realiza automáticamente. En Laravel/Eloquent puro, el idioma incorporado más parecido es un modelo disparando un evento de Laravel directamente desde dentro de un mutador — salvo que eso se dispara **de inmediato**, gane o pierda, mientras que `raiseEvent()` solo almacena en búfer, y nada se publica hasta que una transacción que lo rodea realmente confirma.

---

## El modelo de eventos post-confirmación

Almacenar un evento en búfer no es lo mismo que publicarlo — y la brecha entre ambas cosas es exactamente lo que te protege de que un listener reaccione alguna vez a un cambio que se deshizo. El camino completo desde un evento lanzado hasta un hecho publicado atraviesa tres paquetes trabajando juntos:

1. Un método mutador del agregado — `Wallet::deposit()`, por ejemplo — llama a `raiseEvent()`, añadiendo al búfer privado. Todavía no se publica nada.
2. Cuando la entidad se persiste a través de un repositorio de Firefly, `EloquentRepository::save()` (la sección de cierre del Capítulo 5) la registra en el `AggregateTracker` de `firefly/data` — pero **solo** mientras hay una transacción activamente abierta en la propia conexión de esa entidad.
3. Cuando el método `#[Transactional]` que lo rodea confirma, el framework drena el `pullEvents()` de cada recorder rastreado y publica cada uno, programado a través del propio `DB::afterCommit()` de Laravel sobre la misma conexión en la que corrió la transacción.
4. Laravel solo dispara los callbacks de `afterCommit()` después de la confirmación real **más externa**, y los **descarta** por completo en un rollback — así que un listener solo observa jamás un evento de una unidad de trabajo que genuinamente tuvo éxito.

`DepositHandler` — el manejador de comando real y distribuido detrás de `WalletController::deposit()` del Capítulo 4 — es todo este ciclo en cinco líneas:

```php
<?php

declare(strict_types=1);

namespace Lumen\Application\Command;

use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Lumen\Domain\Money;
use Lumen\Infrastructure\WalletRepository;

/**
 * Handles Deposit: loads the aggregate, credits the amount in the wallet's own currency, persists, and returns the
 * new balance in minor units. #[Transactional] for the same load-bearing reason as OpenWalletHandler — it is the only
 * thing that runs save() at transactionLevel() > 0 so the aggregate is tracked and FundsDeposited publishes on commit.
 *
 * Intentionally NOT final: the generated transactional proxy subclasses this handler.
 */
#[CommandHandler]
class DepositHandler
{
    public function __construct(private readonly WalletRepository $wallets) {}

    #[Transactional]
    public function handle(Deposit $command): int
    {
        $wallet = $this->wallets->findById($command->walletId)
            ?? throw new ResourceNotFoundException("Wallet [{$command->walletId}] not found");

        $wallet->deposit(new Money($command->amountMinor, $wallet->currency()));
        $this->wallets->save($wallet);

        return $wallet->balanceMoney()->minorUnits;
    }
}
```

`#[Transactional]` (tema completo de un capítulo posterior) es lo que hace posible el paso 2 de arriba: sin él, `save()` volcaría (*flush*) la escritura pero nunca vería `transactionLevel() > 0`, así que el agregado nunca se rastrearía y `FundsDeposited` nunca se publicaría, sin importar con cuánta corrección lo lanzara `Wallet::deposit()`. `$wallet->deposit(...)` ejecuta las comprobaciones de invariantes y encola el evento; `$this->wallets->save($wallet)` persiste la fila **y** registra el agregado para el despacho post-confirmación, en la misma llamada; el método retorna, la transacción confirma, y solo entonces `FundsDeposited` llega a algún listener.

`TransferHandler` muestra el mismo ciclo con dos agregados y una garantía genuina de todo-o-nada:

```php
<?php

declare(strict_types=1);

namespace Lumen\Application\Command;

use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Data\Transaction\Propagation;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Lumen\Domain\Money;
use Lumen\Infrastructure\WalletRepository;

/**
 * Handles Transfer: an ATOMIC debit(source) + credit(destination) + save(both) inside one #[Transactional] boundary.
 *
 * The transferred value carries the SOURCE currency (a transfer moves a specific amount of money, not an abstract
 * number of minor units), so the credit leg deposits that same Money into the destination. When the two wallets share
 * a currency the credit succeeds and both saves commit together; when they differ the destination's deposit() throws
 * a currency-mismatch ConflictException AFTER the source was already debited, and — because #[Transactional] rolls
 * back on any Throwable — the source debit is undone too. That is the money-cannot-vanish invariant: there is no path
 * where the source loses funds the destination never receives.
 *
 * Intentionally NOT final: the generated transactional proxy subclasses this handler (same reason as the S4 handlers).
 */
#[CommandHandler]
class TransferHandler
{
    public function __construct(private readonly WalletRepository $wallets) {}

    #[Transactional(propagation: Propagation::REQUIRED)]
    public function handle(Transfer $command): void
    {
        $source = $this->wallets->findById($command->sourceWalletId)
            ?? throw new ResourceNotFoundException("Wallet [{$command->sourceWalletId}] not found");
        $destination = $this->wallets->findById($command->destinationWalletId)
            ?? throw new ResourceNotFoundException("Wallet [{$command->destinationWalletId}] not found");

        $amount = new Money($command->amountMinor, $source->currency());
        $source->withdraw($amount);       // debit (raises FundsWithdrawn)
        $this->wallets->save($source);    // persist + track the debit INSIDE the tx, so it can genuinely roll back
        $destination->deposit($amount);   // credit — throws on currency mismatch -> whole tx rolls back
        $this->wallets->save($destination);
        // commit here -> FundsWithdrawn + FundsDeposited drain atomically after the unit of work commits.
    }
}
```

Si el `deposit()` del destino lanza — un desajuste de moneda — el `rollbackFor` por defecto de `#[Transactional]` (cualquier `Throwable`) revierte todo el método, deshaciendo también el débito del origen. Ni `FundsWithdrawn` ni `FundsDeposited` se habían publicado todavía en ese punto — ambos solo estaban almacenados en búfer en sus respectivos agregados — así que un listener nunca ve la mitad de una transferencia que en realidad nunca ocurrió. El dinero no puede ni desvanecerse ni duplicarse: o ambas piernas confirman y ambos eventos se publican, o ninguna de las dos cosas.

!!! note "Rehidratación y la fábrica"
    Cada llamada a `findById()` de arriba reconstruye un `Wallet` a partir de una fila almacenada mediante la propia hidratación de Eloquent — nunca a través de `Wallet::open()`. Eso es correcto: `open()` es para monederos **nuevos**, y volver a llamarlo sobre una fila ya persistida re-lanzaría `WalletOpened` para un monedero que lleva meses existiendo. Un `Wallet` cargado desde el almacenamiento es exactamente tan válido como uno recién abierto; simplemente no lleva ningún evento nuevo, porque no le ha ocurrido nada nuevo todavía.

---

## Del evento de dominio al modelo de lectura: `LedgerProjector`

El último tramo del viaje — un `FundsDeposited` publicado que realmente llega a algo útil — se cierra con un listener real y distribuido. `LedgerProjector` convierte cada evento de monedero confirmado en una fila de solo-anexión en `ledger_entries`, la tabla que `GetLedgerHandler` (el `WalletController::ledger()` del Capítulo 4) lee de vuelta:

```php
<?php

declare(strict_types=1);

namespace Lumen\Application\Listener;

use Firefly\Container\Attributes\Component;
use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\EventEnvelope;
use Lumen\Domain\LedgerEntry;

/**
 * The read-model projector: turns committed wallet integration events into `ledger_entries` rows (an append-only
 * audit ledger the GetLedger query reads back). It closes the full event chain the sample exercises — a
 * #[Transactional] command commits, the DomainEventDispatcher drains the aggregate's domain events after commit, the
 * firefly/cqrs domain->integration bridge republishes each one to the eda EventPublisher (the memory InMemoryEventBus
 * on the default provider), and the SubscriberRegistry delivers the matching envelope here.
 *
 * The #[EventListener] MUST enumerate the event TYPE names, not the #[PublishDomainEvent('wallet.events')] DESTINATION:
 * SubscriberRegistry::deliver() calls fnmatch($pattern, $envelope->eventType), matching the pattern against the
 * eventType (the short class name, e.g. 'FundsDeposited') and NEVER against the destination. A 'wallet.*'-style pattern
 * would therefore never match any wallet event and this projector would silently never fire.
 */
#[Component]
final class LedgerProjector
{
    #[EventListener(['WalletOpened', 'FundsDeposited', 'FundsWithdrawn', 'TransferCompleted'])]
    public function onWalletEvent(EventEnvelope $envelope): void
    {
        // The envelope payload is array<string, mixed> (get_object_vars of the domain event, seen through the broker
        // boundary), so each field is narrowed to its projected type — a WalletOpened carries no amount/balance, so
        // those default to 0, and TransferCompleted carries no walletId, so it defaults to ''.
        $walletId = $envelope->payload['walletId'] ?? '';
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

Lee el docblock con atención — nombra cada salto que el evento realmente da: el comando `#[Transactional]` confirma; el despachador de `firefly/data` drena los eventos almacenados en búfer del agregado; el puente dominio-a-integración de `firefly/cqrs` republica cada uno sobre el bus de eventos de `firefly/eda` (el bus en memoria por defecto); y `SubscriberRegistry` entrega el `EventEnvelope` resultante a cada `#[EventListener]` cuyo patrón coincida con el **nombre de tipo** del evento — `FundsDeposited`, no la cadena de destino `#[PublishDomainEvent('wallet.events')]`. Que `$envelope->payload['balanceMinor']` exista siquiera, sin necesidad de recargar el monedero, es el rédito directo de que `FundsDeposited` lleve el saldo posterior a la operación desde el momento en que `Wallet::deposit()` lo lanzó.

::: figure art/figures/cqrs-eda-bridge.svg | Figura 6.1 — El puente dominio-a-integración: un comando confirma, los eventos de dominio almacenados en búfer se drenan y se republican sobre el bus de eventos, y un listener como LedgerProjector reacciona al otro lado.

Un relato completo del bus de comandos de `firefly/cqrs` y del bus de eventos de `firefly/eda` es terreno de un capítulo posterior. Lo que importa aquí es la forma: `Wallet` nunca importa `firefly/eda`, nunca importa `LedgerProjector`, y no tiene ni idea de que existe un libro mayor. Solo lanza un hecho. Todo lo que ocurre aguas abajo de ese hecho es asunto de otro — que es todo el sentido de un evento de dominio.

---

## Lo que aprendiste {.recap}

| Concepto | Qué hace |
|---|---|
| `Entity` | Igualdad basada en identidad: misma clase, ids iguales y no nulos; una entidad transitoria es igual solo a sí misma |
| `ValueObject` / `ValueObjectEquality` | Un marcador para estado sin identidad e inmutable, más igualdad estructural vía `get_object_vars()` |
| `AggregateRoot` | La frontera de consistencia libre de persistencia: un búfer privado de eventos más un `raiseEvent()` protegido |
| `HasDomainEvents` | El mismo búfer como trait, para una clase (como un `Model` de Eloquent) que ya extiende otra cosa |
| `RecordsDomainEvents` | El contrato del lado del drenaje (`pendingEvents`/`pullEvents`/`clearEvents`) — deliberadamente sin `raiseEvent()` |
| `DomainEvent` | Una base plana e inmutable que rellena automáticamente `eventId` (uuid-v4) y `occurredAt`; `eventType()` es el nombre corto de la clase |
| `Wallet::open`/`deposit`/`withdraw` | Las tres invariantes reales — propietario obligatorio, importe positivo, sin sobregiro, coincidencia de moneda — aplicadas antes de cualquier mutación |
| El modelo post-confirmación | `save()` rastrea una entidad `RecordsDomainEvents` mientras hay una transacción abierta; los eventos se drenan y publican solo después de que esa transacción confirma |
| `LedgerProjector` | Un `#[EventListener]` real reaccionando a los hechos publicados, emparejado por nombre de tipo de evento |

---

## Ponlo en práctica {.exercises}

1. **Añade un comportamiento `freeze()`.** En una copia de trabajo del proyecto (no en el paquete distribuido `samples/lumen`), añade un evento `WalletFrozen` (`walletId: string`, `reason: string`), un método `freeze(string $reason): void` en `Wallet` que lo lance, y protege `deposit()`/`withdraw()` con una comprobación que lance `ConflictException` cuando el monedero esté congelado. Escribe una pequeña prueba que demuestre que el saldo de un monedero congelado nunca cambia ante un intento de depósito, y que nunca se encola ningún `FundsDeposited` para el intento rechazado.
2. **Demuestra que la invariante se cumple.** Abre un monedero, deposita una pequeña cantidad, y luego intenta retirar más que el saldo. Afirma la `ConflictException` lanzada, luego afirma que el `balanceMoney()` del monedero permanece completamente sin cambios y que `pendingEvents()` no muestra ningún `FundsWithdrawn` — la regla se disparó antes de cualquier mutación, exactamente como describe este capítulo.
3. **Traza la frontera de la transacción.** Quita temporalmente `#[Transactional]` de una copia de `DepositHandler`, deposita en un monedero, y confirma (mediante una lectura fresca en una nueva petición) que la fila nunca llegó a confirmarse realmente — demostrando que el *flush* por sí solo de `EloquentRepository::save()` no es durabilidad, y que la publicación de eventos de dominio depende de esa misma confirmación.
