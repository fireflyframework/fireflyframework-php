<span class="eyebrow">Parte II — Modelar y Persistir el Dominio · Capítulo 5</span>

# Persistencia y el Patrón Repositorio {.chtitle}

Al terminar este capítulo conocerás los dos puertos que implementa en última instancia todo repositorio de LaraFly, cómo `EloquentRepository` te da CRUD completo por el precio de una sola asignación a `$model`, cómo el nombre de un método de consulta derivada se compila en una consulta Eloquent real sin cuerpo propio, el escape `#[Query]` para cualquier cosa que un nombre no pueda expresar con limpieza, cómo las `Specification` componen predicados reutilizables, los objetos de valor `Page`/`Pageable`/`Sort` que transportan la paginación de principio a fin, y el puente de eventos de dominio que conecta la llamada `save()` de un repositorio con el agregado del Capítulo 6.

!!! note "Término nuevo: consulta derivada"
    Una **consulta derivada** es un método de repositorio **sin cuerpo** — solo un nombre como `findByOwnerId` — cuyo SQL compila el framework analizando el propio nombre del método, en el momento en que se llama por primera vez. Tú escribes el nombre; el framework escribe la cláusula `WHERE`.

---

## Los puertos: `CrudRepository` y `PagingAndSortingRepository`

Todo repositorio de LaraFly implementa en última instancia dos pequeñas interfaces de `firefly/data`. PHP no tiene genéricos en tiempo de ejecución, así que las firmas de los puertos son deliberadamente `object`/`mixed`/`array` — los parámetros `@template` del docblock le devuelven a PHPStan los tipos precisos de entidad e id:

```php
/**
 * @template TEntity of object
 * @template TId
 */
interface CrudRepository
{
    /**
     * @param  TEntity  $entity
     * @return TEntity
     */
    public function save(object $entity): object;

    /**
     * @param  iterable<TEntity>  $entities
     * @return list<TEntity>
     */
    public function saveAll(iterable $entities): array;

    /**
     * @param  TId  $id
     * @return TEntity|null
     */
    public function findById(mixed $id): ?object;

    /**
     * @return list<TEntity>
     */
    public function findAll(): array;

    /**
     * @param  iterable<TId>  $ids
     * @return list<TEntity>
     */
    public function findAllById(iterable $ids): array;

    /**
     * @param  TId  $id
     */
    public function existsById(mixed $id): bool;

    public function count(): int;

    /**
     * @param  TEntity  $entity
     */
    public function delete(object $entity): void;

    /**
     * @param  TId  $id
     */
    public function deleteById(mixed $id): void;

    public function deleteAll(): void;
}
```

Spring Data sobrecarga un único `findAll(Pageable)`/`findAll(Sort)`; PHP no tiene sobrecarga de métodos, así que `PagingAndSortingRepository` divide los dos en métodos distintos, tipados con limpieza:

```php
/**
 * @template TEntity of object
 * @template TId
 *
 * @template-extends CrudRepository<TEntity, TId>
 */
interface PagingAndSortingRepository extends CrudRepository
{
    /**
     * @return Page<TEntity>
     */
    public function findPaged(Pageable $pageable): Page;

    /**
     * @return list<TEntity>
     */
    public function findSorted(Sort $sort): array;
}
```

No implementarás ninguna de las dos interfaces directamente. `Firefly\Data\Repository\EloquentRepository` ya implementa ambas sobre `Model::query()`, y cada repositorio concreto de tu aplicación extiende *eso* — la misma relación que describió el Capítulo 2 entre una interfaz y su único bean implementador, ahora un nivel más abajo en la pila.

!!! laravel "Paridad con Laravel"
    `CrudRepository`/`PagingAndSortingRepository` son los equivalentes en LaraFly de `CrudRepository`/`PagingAndSortingRepository` de Spring Data. Si has escrito una `interface OrderRepository extends JpaRepository<Order, UUID>` de Spring, `EloquentRepository` es la misma idea trasladada a Eloquent: obtienes la superficie CRUD completa con un solo `extends`, y añades solo las consultas específicas de tu entidad.

---

## El puerto y el adaptador: `WalletRepository`

El Capítulo 2 introdujo la división hexagonal entre un *puerto* del que dependen tus capas de dominio y aplicación, y el *adaptador* que realmente habla con el almacenamiento. La persistencia es donde esa división demuestra su valor. Aquí está el puerto completo, sin cambios desde el Capítulo 2:

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

Y aquí está el adaptador — la clase que extiende `EloquentRepository` y hace el trabajo real:

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
 * Because this class `implements WalletRepository` (a real PHP interface) in addition to `extends
 * EloquentRepository`, every interface method needs an EXPLICIT body — the framework's own convention of leaning on
 * `EloquentRepository::__call()` (magic dispatch, documented via `@method` docblocks only) does NOT satisfy an
 * `implements` contract; PHP fatals at class-load time if an abstract interface method is left undefined.
 *
 * `save()`/`findById()` keep the PARENT's exact parameter type (`object`/`mixed`) and only narrow the RETURN type to
 * `Wallet` — not the parameter to `Wallet`, even though that is what `WalletRepository` declares. This is a real PHP
 * variance constraint, not a stylistic choice: overriding `EloquentRepository::save(object $entity): object` with a
 * narrower parameter (`Wallet $wallet`) is an invalid override (contravariance requires the override's parameter to
 * be the same type or WIDER than the parent's, never narrower) and fatals at class-load — confirmed empirically
 * while building this adapter. Widening the implementation's parameter back to `object`/`mixed` still satisfies
 * `WalletRepository`'s narrower `Wallet`/`string` parameters, because interface conformance uses the same
 * contravariant-parameter/covariant-return rule: an implementation may accept MORE than the interface promises and
 * return exactly what it promises. An `instanceof` guard narrows the parameter back to `Wallet` before delegating to
 * `parent::save()`, so the generic template parameter on the parent resolves to `Wallet` and the return type lines
 * up with no extra type-override annotation or PHPStan suppression comment needed.
 *
 * `findByOwnerId()` — a derived query with no parent counterpart, so no variance constraint applies — delegates
 * explicitly to the protected `dispatchQuery()` dispatcher (the same engine `__call()` would have reached, just
 * invoked directly so the method has a real body, per the `implements` requirement above).
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

Fíjate en la línea `protected string $model = Wallet::class;` — esa única asignación es lo que le dice a `EloquentRepository` contra qué modelo debe correr `query()`, y es la razón completa por la que la superficie CRUD entera (`save`, `saveAll`, `findById`, `findAll`, `findAllById`, `existsById`, `count`, `delete`, `deleteById`, `deleteAll`, más paginación y ordenación) aparece en `EloquentWalletRepository` de forma gratuita. `$this->model::query()` es la única línea al final de cada método heredado.

!!! warning "Una restricción de varianza de PHP, no una decisión de diseño"
    Una clase que a la vez `extends EloquentRepository` e `implements WalletRepository` debe dar a cada método de la interfaz un cuerpo explícito — el despacho mágico `__call()` por sí solo no satisface una cláusula `implements`. Y como `WalletRepository::save(Wallet $wallet): Wallet` restringe el `save(object $entity): object` del padre, sobrescribir con el tipo de parámetro *más estrecho* es una sobrescritura inválida en PHP (los tipos de parámetro deben permanecer iguales o ensancharse, nunca estrecharse); `EloquentWalletRepository::save()` mantiene la firma `object $entity` del padre y solo estrecha el tipo de *retorno*, protegiendo el requisito real de `Wallet` con una comprobación `instanceof` dentro del cuerpo del método en su lugar.

---

## Consultas derivadas: el nombre del método es la consulta

`findByOwnerId` de arriba no tiene lógica de consulta en su propio cuerpo — `dispatchQuery()` es el motor compartido debajo tanto de él como del despacho mágico `__call()`. Un método de repositorio cuyo nombre sigue la gramática de abajo no necesita ningún cuerpo cuando se llama a través de `__call()`; `EloquentRepository::dispatchQuery()` analiza el nombre del método con `DerivedQueryParser::parse()` — análisis de cadenas puro, sin reflexión, el nombre del método es la única entrada — y conduce un `Builder` de Eloquent en consecuencia.

### La gramática

| Pieza | Valores | Notas |
|---|---|---|
| Prefijo | `findBy` / `countBy` / `existsBy` / `deleteBy` | `find` también acepta `findFirstBy…` (límite 1), `findTop{N}By…` (límite N), `findDistinctBy…` (añade `DISTINCT`) antes del `By`. |
| Conector | `And` / `Or` | Divide grupos de predicados; solo se reconoce antes de un nuevo campo en CamelCase. |
| Operador | ver abajo | Se empareja **de más largo a más corto** contra el final de cada grupo de predicados; `Equals` (sin token) es el implícito por defecto. |
| Modificador de mayúsculas | sufijo `IgnoreCase` | Encamina la familia de igualdad/LIKE a través de `LOWER(col) op LOWER(?)`. |
| Ordenación | `OrderBy{Field}{Asc\|Desc}` final | Encadenable; la dirección por defecto es `asc` si se omite. |

Los operadores, en el orden exacto de coincidencia-más-larga-primero que recorre el analizador:

| Operador | Efecto SQL | Argumentos consumidos |
|---|---|---|
| `GreaterThanEqual` | `>=` | 1 |
| `LessThanEqual` | `<=` | 1 |
| `GreaterThan` | `>` | 1 |
| `LessThan` | `<` | 1 |
| `Between` | `BETWEEN ? AND ?` | 2 |
| `NotLike` | `not like` | 1 |
| `Like` | `like` | 1 |
| `NotIn` | `NOT IN (?)` | 1 (array) |
| `In` | `IN (?)` | 1 (array) |
| `Containing` | `like '%value%'` | 1 |
| `StartingWith` | `like 'value%'` | 1 |
| `EndingWith` | `like '%value'` | 1 |
| `IsNotNull` | `IS NOT NULL` | 0 |
| `IsNull` | `IS NULL` | 0 |
| `Not` | `!=` | 1 |
| `True` | `= true` | 0 |
| `False` | `= false` | 0 |
| *(ninguno)* | `Equals` → `=` | 1 |

Cada segmento de campo en CamelCase se mapea a una columna `snake_case`, y cada columna derivada se valida contra un patrón de identificador desnudo antes de poder llegar a una consulta — el nombre del método es una entrada no confiable, alcanzable a través del `__call` público, así que esta validación es lo que mantiene libre de inyección el fragmento `LOWER(col)` en bruto de la vía `IgnoreCase`. Los argumentos se vinculan a los predicados en el orden de declaración, de izquierda a derecha, avanzando un cursor tantos valores como consuma cada operador.

Un `RecordRepository` hipotético muestra la gramática de un vistazo — las etiquetas `@method` son cómo PHPStan ve un retorno tipado para lo que, en tiempo de ejecución, es despacho dinámico `__call`:

```php
/**
 * @extends EloquentRepository<Record>
 *
 * @method list<Record> findByStatusAndAmountGreaterThan(string $status, int $amount)
 * @method list<Record> findTop2ByStatusOrderByAmountDesc(string $status)
 * @method bool existsByEmailIgnoreCase(string $email)
 * @method int countByStatus(string $status)
 * @method int deleteByStatus(string $status)
 */
#[Repository]
class RecordRepository extends EloquentRepository
{
    protected string $model = Record::class;
}
```

`EloquentWalletRepository::findByOwnerId()` es la versión real y distribuida de exactamente este patrón — la única diferencia es que `implements WalletRepository`, así que su cuerpo llama a `dispatchQuery()` explícitamente en vez de depender de `__call()`, por la razón de varianza explicada arriba. De cualquier forma, `findByOwnerId('alice')` se compila a `WHERE owner_id = ?` sin SQL escrito a mano.

!!! tip "Cuando un nombre se volvería absurdo, usa `#[Query]`"
    Los nombres derivados se leen bien hasta dos o tres predicados. Pasado eso, recurre al escape de consulta explícita de abajo en vez de a un nombre de método de cincuenta caracteres.

---

## El escape de consulta explícita `#[Query]`

Un método puede declarar su SQL directamente, saltándose por completo la gramática de consulta derivada:

```php
final class Query
{
    public function __construct(
        public string $sql,
        public bool $native = false,
    ) {}
}
```

```php
#[Repository]
class RecordRepository extends EloquentRepository
{
    protected string $model = Record::class;

    #[Query('select * from records where email = :email order by amount asc')]
    public function findByEmailRaw(string $email): array
    {
        return $this->dispatchQuery(__FUNCTION__, func_get_args());
    }
}
```

Los marcadores de posición nombrados `:param` se reescriben a `?` posicionales en el orden en que aparecen, y los propios argumentos del método se vinculan posicionalmente. Los métodos `#[Query]` los descubre el mismo `TransactionalScanner` que compila el manifiesto `#[Transactional]` (la sección sobre transacciones de un capítulo posterior cubre ese escáner en detalle), así que el SQL de un método declarado se resuelve desde el manifiesto compilado con cero reflexión por petición — tanto la vía de consulta derivada como un método `#[Query]` explícito confluyen en el único método `dispatchQuery()` compartido por `__call()` y cualquier cuerpo de método que lo llame directamente.

---

## `Specification`: predicados componibles y reutilizables

Las consultas derivadas responden preguntas fijas. Una **`Specification`** es un predicado reutilizable que puedes nombrar una vez y componer libremente en el punto de llamada — "monederos con al menos este saldo", combinado con otras condiciones según haga falta:

```php
/** @template TModel of Model */
interface Specification
{
    public function toBuilder(Builder $query): Builder;
}
```

`Specifications` es la fábrica estática — las interfaces de PHP no pueden llevar cuerpos de fábrica estáticos:

```php
Specifications::allOf(...$specifications); // AND-folds left-to-right; zero-arg = match-all
Specifications::anyOf(...$specifications); // OR-folds left-to-right; zero-arg = match-all
Specifications::not($specification);
Specifications::where(fn (Builder $q) => $q->where('status', 'open'));
```

Aplicar una especificación construida corre a través de dos métodos de repositorio que ya provee cada `EloquentRepository`:

```php
abstract class EloquentRepository implements PagingAndSortingRepository
{
    /**
     * @param  Specification<Model>  $specification
     * @return list<TModel>
     */
    public function findBySpecification(Specification $specification): array
    {
        return $this->narrow($specification->toBuilder($this->query())->get()->all());
    }

    /**
     * @param  Specification<Model>  $specification
     * @return Page<TModel>
     */
    public function findBySpecificationPaged(Specification $specification, Pageable $pageable): Page
    {
        $total = $specification->toBuilder($this->query())->count();

        $items = $this->applySort($specification->toBuilder($this->query()), $pageable->sort)
            ->skip($pageable->offset())
            ->take($pageable->size)
            ->get()
            ->all();

        return new Page($this->narrow($items), $total, $pageable->page, $pageable->size);
    }
}
```

Una especificación para monederos por encima de un saldo mínimo, compuesta con un filtro de moneda, se lee exactamente como la regla que expresa:

```php
$rich = Specifications::where(
    fn (Builder $q) => $q->where('balance_minor', '>=', 100_000)
);
$inEur = Specifications::where(fn (Builder $q) => $q->where('currency', 'EUR'));

$richEurWallets = Specifications::allOf($rich, $inEur);
```

`$repo->findBySpecificationPaged($richEurWallets, Pageable::of(1, 20))` ejecuta ese predicado compuesto, cuenta las coincidencias, ordena y recorta — devolviendo una `Page` sin SQL propio más allá de los dos cierres `where()`.

---

## Paginación: `Page`, `Pageable` y `Sort`

Tres pequeños objetos de valor inmutables transportan de principio a fin una petición de página y devuelven un resultado de página. `Pageable` es la petición — un número de página basado en 1, un tamaño, y un `Sort` opcional:

```php
final readonly class Pageable
{
    public function __construct(
        public int $page = 1,
        public int $size = 20,
        public ?Sort $sort = null,
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException('Page number is 1-based and must be >= 1.');
        }

        if ($size < 1) {
            throw new InvalidArgumentException('Page size must be >= 1.');
        }
    }

    public static function of(int $page, int $size, ?Sort $sort = null): self
    {
        return new self($page, $size, $sort);
    }

    public static function unpaged(?Sort $sort = null): self
    {
        return new self(1, PHP_INT_MAX, $sort);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->size;
    }
}
```

`Sort` y `Order` componen una ordenación a partir de piezas pequeñas e inmutables — cada combinador devuelve un `Sort` *nuevo*, nunca muta aquel con el que empezaste:

```php
final readonly class Sort
{
    /**
     * @param  list<Order>  $orders
     */
    public function __construct(public array $orders = []) {}

    public static function by(string ...$properties): self
    {
        return new self(array_map(
            static fn (string $property): Order => Order::asc($property),
            array_values($properties),
        ));
    }

    public function descending(): self
    {
        return new self(array_map(
            static fn (Order $order): Order => Order::desc($order->property),
            $this->orders,
        ));
    }
}
```

`Order::asc('created_at')`/`Order::desc('created_at')` empareja un nombre de propiedad con un enum `Direction::Asc`/`Direction::Desc` cuyo *valor* de cadena subyacente **es** la dirección de `orderBy()` de Eloquent — `$order->direction->value` no necesita ninguna tabla de traducción en absoluto.

`Page` es lo que vuelve — el recorte de filas más todo lo que un cliente necesita para renderizar un paginador:

```php
/**
 * @template T
 */
final readonly class Page
{
    /**
     * @param  list<T>  $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page = 1,
        public int $size = 20,
    ) {}

    public function totalPages(): int
    {
        return $this->size > 0 ? (int) ceil($this->total / $this->size) : 0;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->totalPages();
    }

    /**
     * @template U
     *
     * @param  callable(T): U  $mapper
     * @return self<U>
     */
    public function map(callable $mapper): self
    {
        return new self(array_map($mapper, $this->items), $this->total, $this->page, $this->size);
    }
}
```

`EloquentRepository::findPaged()` construye una `Page` en el borde de Eloquent: una consulta `count()` para `total`, luego `skip($pageable->offset())->take($pageable->size)` para el recorte, con `applySort()` plegando cada orden de `Sort` en una llamada `orderBy()` en medio. `Page::map()` es a lo que recurre un manejador de consulta para convertir una página de entidades en una página de DTOs **sin perder los metadatos de paginación** — los mismos números `total`/`page`/`size` sobreviven intactos a la transformación.

---

## Borrado suave, auditoría y bloqueo optimista

Tres bloques constructivos más viven en `EloquentRepository`, apoyados sobre los propios mecanismos de Eloquent en vez de reinventados. Ninguno lo ejercita `Wallet` en este libro, pero cada uno está a una sola declaración `use` de distancia en cualquier modelo que lo necesite.

El **borrado suave** reutiliza tal cual el trait nativo `SoftDeletes` de Eloquent:

```php
final class SoftRecord extends Model
{
    use SoftDeletes;
}
```

Una vez que un modelo lo usa, `delete()`/`deleteById()` a través del repositorio borra suavemente la fila, y cada lectura ordinaria excluye de forma transparente las filas eliminadas mediante el propio ámbito global de Eloquent. `findAllIncludingDeleted()` y `restore(mixed $id): ?object` completan el ciclo de vida.

La **auditoría** es un trait `Auditable` opcional que estampa `created_by`/`updated_by` al escribir, impulsado por un puerto `AuditorAware`:

```php
interface AuditorAware
{
    public function currentAuditor(): int|string|null;
}
```

El **bloqueo optimista** protege escrituras concurrentes con una columna entera `version` vía `HasOptimisticLock`, que sobrescribe el `performUpdate()` interno de Eloquent para añadir `WHERE version = <versión cargada>` a cada `UPDATE` — una escritura contra una versión obsoleta no coincide con ninguna fila y lanza `OptimisticLockException` en vez de sobrescribir silenciosamente el cambio de otro.

---

## El puente de eventos de dominio

`EloquentRepository::save()` hace algo más, además de persistir la fila, que importa enormemente para el Capítulo 6: si la entidad guardada también implementa `RecordsDomainEvents` — que es el caso de `Wallet` — **y** hay una transacción activa en ese momento sobre la propia conexión de esa entidad, `save()` registra la entidad en `AggregateTracker`, el registro de unidad de trabajo de `firefly/data`:

```php
abstract class EloquentRepository implements PagingAndSortingRepository
{
    public function save(object $entity): object
    {
        if ($entity instanceof Model) {
            $entity->save();
        }

        if ($entity instanceof RecordsDomainEvents
            && $this->tracker !== null
            && $this->connectionFor($entity)->transactionLevel() > 0) {
            $this->tracker->track($entity);
        }

        return $entity;
    }
}
```

Ese registro es lo que permite al framework drenar y publicar los eventos de dominio lanzados por un `Wallet` después de que confirme el método `#[Transactional]` que lo rodea — y nunca si hace rollback. El Capítulo 6 construye el agregado que lanza esos eventos; este es el punto de conexión, en el lado de la persistencia, que los captura.

---

## Lo que aprendiste {.recap}

| Concepto | Qué hace |
|---|---|
| `CrudRepository` / `PagingAndSortingRepository` | Los puertos agnósticos de persistencia que implementa en última instancia todo repositorio |
| `EloquentRepository` | La base respaldada por Eloquent: fija `$model`, hereda CRUD + paginación + ordenación completos |
| Consulta derivada (`findByOwnerId`) | Un nombre de método sin cuerpo analizado por `DerivedQueryParser` y conducido contra el `Builder` de Eloquent |
| `#[Query('sql')]` | Un escape de SQL explícito, descubierto en tiempo de escaneo, resuelto con cero reflexión por petición |
| `Specification` / `Specifications` | Predicados componibles (`allOf`/`anyOf`/`not`/`where`) aplicados vía `findBySpecification(Paged)` |
| `Page` / `Pageable` / `Sort` / `Order` | Objetos de valor inmutables que transportan una petición de página y devuelven un resultado de página (con metadatos) |
| `SoftDeletes` / `Auditable` / `HasOptimisticLock` | Traits opcionales para borrado suave, estampado `created_by`/`updated_by`, y escrituras protegidas por versión |
| El puente de eventos de dominio | `save()` registra una entidad `RecordsDomainEvents` en `AggregateTracker` mientras hay una transacción activa |

---

## Ponlo en práctica {.exercises}

1. **Añade un contador derivado.** En una copia de trabajo del proyecto, declara `public function countByCurrency(string $currency): int` sin cuerpo en un repositorio que extienda `EloquentRepository`, y confirma que llamarlo se compila a `SELECT COUNT(*) … WHERE currency = ?` sin SQL propio.
2. **Compón dos especificaciones.** Construye una `Specification` para "saldo de al menos N" y otra para "en la moneda C" con `Specifications::where(...)`, combínalas con `Specifications::allOf(...)`, y ejecuta el compuesto a través de `findBySpecificationPaged` contra una pequeña tabla poblada de prueba.
3. **Lee una página de monederos.** Llama a la contraparte de paginación de `EloquentWalletRepository::findAll(...)` (añade un llamador de `findPaged` a través de `WalletRepository` en tu proyecto de pruebas) con `Pageable::of(1, 2, Sort::by('created_at')->descending())` contra tres monederos de prueba poblados, y confirma que `total`, `totalPages` y `hasNext` coinciden todos con lo que esperas antes y después de `Page::map()`.
