<span class="eyebrow">Parte II — Modelar y Persistir el Dominio · Capítulo 5</span>

# Persistencia y el Patrón Repositorio {.chtitle}

Al terminar este capítulo conocerás los dos puertos que implementa en última instancia todo repositorio de LaraFly, cómo `EloquentRepository` te da CRUD completo por el precio de una sola asignación a `$model`, cómo el nombre de un método de consulta derivada se compila en una consulta Eloquent real sin cuerpo propio, el escape `#[Query]` para cualquier cosa que un nombre no pueda expresar con limpieza, cómo las `Specification` componen predicados reutilizables, los objetos de valor `Page`/`Pageable`/`Sort` que transportan la paginación de principio a fin, y el puente de eventos de dominio que conecta la llamada `save()` de un repositorio con el agregado del Capítulo 6.

!!! note "Término nuevo: consulta derivada"
    Una **consulta derivada** es un método de repositorio **sin cuerpo** — solo un nombre como `findByOwnerId` — cuyo SQL compila el framework analizando el propio nombre del método, en el momento en que se llama por primera vez. Tú escribes el nombre; el framework escribe la cláusula `WHERE`.

---

## Los puertos: `CrudRepository` y `PagingAndSortingRepository`

Todo repositorio de LaraFly implementa en última instancia dos pequeñas interfaces de `firefly/data`. PHP no tiene genéricos en tiempo de ejecución, así que las firmas de los puertos son deliberadamente `object`/`mixed`/`array` — los parámetros `@template` del docblock le devuelven a PHPStan los tipos precisos de entidad e id:

<!-- source: packages/data/src/Repository/CrudRepository.php -->
```php
/**
 // …
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

<!-- source: packages/data/src/Repository/PagingAndSortingRepository.php -->
```php
/**
 // …
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
     // …
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

<!-- source: samples/lumen/src/Infrastructure/WalletRepository.php -->
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

<!-- source: samples/lumen/src/Infrastructure/EloquentWalletRepository.php -->
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

El propio `RecordRepository` de prueba del framework — contra el que corre la suite de consultas derivadas de `packages/data` — muestra la gramática de un vistazo. Las etiquetas `@method` son cómo PHPStan ve un retorno tipado para lo que, en tiempo de ejecución, es despacho dinámico `__call`:

<!-- source: packages/data/tests/Fixtures/Repository/RecordRepository.php -->
```php
/**
 // …
 * @extends EloquentRepository<Record>
 *
 * @method list<Record> findByStatusAndAmountGreaterThan(string $status, int $amount)
 * @method list<Record> findTop2ByStatusOrderByAmountDesc(string $status)
 * @method bool existsByEmailIgnoreCase(string $email)
 * @method int countByStatus(string $status)
 * @method int deleteByStatus(string $status)
 * @method Page<Record> findByStatus(string $status, Pageable $pageable)
 */
#[Repository]
class RecordRepository extends EloquentRepository
{
    protected string $model = Record::class;
```

Ninguno de esos seis métodos está declarado en ninguna parte de la clase: `__call()` analiza cada nombre la primera vez que se usa y el manifiesto compilado responde a partir de entonces.

`EloquentWalletRepository::findByOwnerId()` es la versión real y distribuida de exactamente este patrón — la única diferencia es que `implements WalletRepository`, así que su cuerpo llama a `dispatchQuery()` explícitamente en vez de depender de `__call()`, por la razón de varianza explicada arriba. De cualquier forma, `findByOwnerId('alice')` se compila a `WHERE owner_id = ?` sin SQL escrito a mano.

!!! tip "Cuando un nombre se volvería absurdo, usa `#[Query]`"
    Los nombres derivados se leen bien hasta dos o tres predicados. Pasado eso, recurre al escape de consulta explícita de abajo en vez de a un nombre de método de cincuenta caracteres.

---

## El escape de consulta explícita `#[Query]`

Un método puede declarar su SQL directamente, saltándose por completo la gramática de consulta derivada:

<!-- source: packages/data/src/Repository/Attributes/Query.php -->
```php
final class Query
{
    public function __construct(
        public string $sql,
        public bool $native = false,
    ) {}
}
```

<!-- source: packages/data/tests/Fixtures/Repository/RecordRepository.php -->
```php
/**
 // …
 * @return list<array<string, mixed>>
 */
#[Query('select * from records where email = :email order by amount asc')]
public function findByEmailRaw(string $email): array
{
    $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
    assert(is_array($rows));

    /** @var list<array<string, mixed>> $rows */
    return $rows;
}
```

El cuerpo es la misma línea única que lleva todo método de repositorio declarado: entregar el nombre propio del método y sus argumentos al despachador compartido, que encuentra el método en el conjunto `#[Query]` del manifiesto y ejecuta el SQL. El `assert()` y el `@var` están ahí por PHPStan, que no puede ver a través de `mixed`.

Los marcadores de posición nombrados `:param` se reescriben a `?` posicionales en el orden en que aparecen, y los propios argumentos del método se vinculan posicionalmente. Los métodos `#[Query]` los descubre el mismo `TransactionalScanner` que compila el manifiesto `#[Transactional]` (la sección sobre transacciones de un capítulo posterior cubre ese escáner en detalle), así que el SQL de un método declarado se resuelve desde el manifiesto compilado con cero reflexión por petición — tanto la vía de consulta derivada como un método `#[Query]` explícito confluyen en el único método `dispatchQuery()` compartido por `__call()` y cualquier cuerpo de método que lo llame directamente.

---

## `Specification`: predicados componibles y reutilizables

Las consultas derivadas responden preguntas fijas. Una **`Specification`** es un predicado reutilizable que puedes nombrar una vez y componer libremente en el punto de llamada — "monederos con al menos este saldo", combinado con otras condiciones según haga falta:

<!-- source: packages/data/src/Repository/Specification/Specification.php -->
```php
interface Specification
{
    // …
    public function toBuilder(Builder $query): Builder;
}
```

`Specifications` es la fábrica estática — las interfaces de PHP no pueden llevar cuerpos de fábrica estáticos:

<!-- illustrative: the four factory calls a reader makes from their own code -->
```php
Specifications::allOf(...$specifications); // AND-folds left-to-right; zero-arg = match-all
Specifications::anyOf(...$specifications); // OR-folds left-to-right; zero-arg = match-all
Specifications::not($specification);
Specifications::where(fn (Builder $q) => $q->where('status', 'open'));
```

Aplicar una especificación construida corre a través de dos métodos de repositorio que ya provee cada `EloquentRepository`:

<!-- source: packages/data/src/Repository/EloquentRepository.php -->
```php
public function findBySpecification(Specification $specification): array
{
    $query = $this->reading(__FUNCTION__);

    return $this->translating(fn (): array => $this->narrow($specification->toBuilder($query)->get()->all()));
}
// …
public function findBySpecificationPaged(Specification $specification, Pageable $pageable): Page
{
    $query = $this->reading(__FUNCTION__);

    return $this->translating(fn (): Page => $this->pageOf($specification->toBuilder($query), $pageable));
}
```

Cuatro ayudantes cargan con todo lo que esos dos cuerpos no deletrean, y la división de su visibilidad es el
sentido del asunto. `reading()` y `translating()` son **`protected`** — son la costura a la que recurre un
repositorio subclase, y es lo que pone a tu alcance la receta de «anotar una sobrescritura» de más abajo —
mientras que `pageOf()` y `narrow()` son fontanería `private`. `reading(__FUNCTION__)` abre la consulta y
aplica el `#[EntityGraph]` que el manifiesto compilado guarde *para esta clase de repositorio y este método*,
que es por lo que anotar una sobrescritura es la receta entera. `narrow()` es el que el cuerpo paginado de
arriba no llama: se queda solo con las filas que realmente son `TModel` y devuelve un `list<TModel>`.
`pageOf()` cuenta sobre un clon del builder y luego lo recorta, así que la cuenta y el recorte ven el mismo
predicado. Y `translating()` envuelve la llamada terminal para que un fallo del driver salga del repositorio
como una `DataAccessException` tipada y no como una `QueryException` en bruto — el asunto de la última sección de este capítulo.

Una especificación para monederos por encima de un saldo mínimo, compuesta con un filtro de moneda, se lee exactamente como la regla que expresa:

<!-- illustrative: two specifications an application composes out of its own predicates -->
```php
$rich = Specifications::where(
    fn (Builder $q) => $q->where('balance_minor', '>=', 100_000)
);
$inEur = Specifications::where(fn (Builder $q) => $q->where('currency', 'EUR'));

$richEurWallets = Specifications::allOf($rich, $inEur);
```

`$repo->findBySpecificationPaged($richEurWallets, Pageable::of(1, 20))` ejecuta ese predicado compuesto, cuenta las coincidencias, ordena y recorta — devolviendo una `Page` sin SQL propio más allá de los dos cierres `where()`.

---

## Consulta por ejemplo

A veces el predicado que quieres es sencillamente *«otra fila que se parezca a esta»*. Spring Data lo llama un `Example`, y `firefly/data` lo porta de la manera que no cuesta nada aprender: **un `Example` es una `Specification`.** No tiene una vía de llamada aparte, ni un método de repositorio especial por el que sea la única forma de alcanzarlo, ni reglas de composición propias. Todo lo que mostró la sección anterior se le aplica sin cambios.

<!-- source: packages/data/src/Repository/Example/Example.php -->
```php
final readonly class Example implements Specification
{
    private const string IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/D';
    // …
    public static function of(object|array $probe, ?ExampleMatcher $matcher = null): self
    {
        $attributes = match (true) {
            $probe instanceof Model => [...$probe->getRawOriginal(), ...$probe->getDirty()],
            is_object($probe) => get_object_vars($probe),
            default => $probe,
        };
        // …
        return new self($validated, $matcher ?? ExampleMatcher::matching());
    }
```

La **sonda** es aquello que se parece a lo que quieres. Puede ser un modelo Eloquent con algunos atributos fijados, cualquier objeto plano (sus propiedades públicas), o un array pelado de `columna => valor`. Una sonda de modelo aporta exactamente los atributos que se *fijaron* — `new Record(['status' => 'open'])` es una sonda de una sola propiedad, no una sonda de todas las columnas de la tabla — porque los atributos se leen como los originales en bruto del modelo superpuestos con su conjunto sucio.

Cada clave de la sonda se valida como identificador pelado ahí mismo, en `of()`, y una inválida es una `InvalidArgumentException` antes de que se construya consulta alguna. Eso no es decoración defensiva: una clave llega hasta un fragmento de SQL en bruto cuando se ignora la caja o cuando un `LIKE` necesita su cláusula `ESCAPE`, así que se comprueba en el único sitio donde comprobarla una vez basta — la misma disciplina que el analizador de consultas derivadas aplica a los nombres de campo.

Como un `Example` es una `Specification`, los métodos de repositorio que ya conoces lo aceptan directamente, y hay cuatro métodos de conveniencia nombrados como un lector de Spring Data espera:

<!-- source: packages/data/tests/Repository/Example/QueryByExampleTest.php -->
```php
$repo = new RecordRepository;

expect($repo->findByExample(Example::of(['status' => 'open', 'amount' => 150])))->toHaveCount(1)
    ->and($repo->findByExample(Example::of(new Record(['status' => 'closed']))))->toHaveCount(3)
    ->and($repo->countByExample(Example::of(['status' => 'open'])))->toBe(3)
    ->and($repo->existsByExample(Example::of(['status' => 'archived'])))->toBeFalse()
    ->and($repo->findByExample(Example::of([])))->toHaveCount(6);
```

Una sonda vacía coincide con todo, que es la respuesta honesta en vez de un error: sin propiedades no hay predicado. `findOneByExample()` es la variante de como mucho uno — `null` si no hay ninguna fila, la fila si hay una, y `IncorrectResultSizeDataAccessException` si hay más de una, porque una sonda ambigua es un error de programación y no un resultado:

<!-- source: packages/data/tests/Repository/Example/QueryByExampleTest.php -->
```php
expect($repo->findOneByExample(Example::of(['amount' => 150]))?->email)->toBe('b@x.test')
    ->and($repo->findOneByExample(Example::of(['amount' => 999])))->toBeNull()
    ->and(fn () => $repo->findOneByExample(Example::of(['status' => 'open'])))->toThrow(IncorrectResultSizeDataAccessException::class);
```

### El emparejador lleva las reglas

Una sonda por sí sola dice *qué* comparar. Un `ExampleMatcher` dice *cómo*. Es un objeto de valor inmutable cuyos `with*` devuelven todos una instancia nueva, así que un emparejador se puede construir una vez y compartir sin riesgo:

<!-- source: packages/data/src/Repository/Example/ExampleMatcher.php -->
```php
public function withIgnorePaths(string ...$paths): self
{
    return $this->copy(ignoredPaths: array_values(array_unique([...$this->ignoredPaths, ...$paths])));
}

/** No paths: every string property ignores case. With paths: only those. */
public function withIgnoreCase(string ...$paths): self
{
    return $paths === []
        ? $this->copy(ignoreCaseAll: true)
        : $this->copy(ignoreCasePaths: array_values(array_unique([...$this->ignoreCasePaths, ...$paths])));
}

public function withIncludeNullValues(): self
{
    return $this->copy(includeNullValues: true);
}

public function withStringMatcher(StringMatcher $matcher): self
{
    return $this->copy(defaultStringMatcher: $matcher);
}

public function withMatcher(string $path, GenericPropertyMatcher $matcher): self
{
    return $this->copy(propertyMatchers: [...$this->propertyMatchers, $path => $matcher]);
}
```

Las reglas que esos cinco combinadores expresan, al completo:

| Regla | Por defecto | Cómo cambiarla |
|---|---|---|
| Cómo se combinan las propiedades | `AND` (`matching()` = `matchingAll()`) | `ExampleMatcher::matchingAny()` para `OR` |
| Un valor `null` en la sonda | se omite por completo | `withIncludeNullValues()` lo convierte en `IS NULL` |
| Una propiedad que no quieres comparar | se compara | `withIgnorePaths('id', 'created_at')` |
| Cómo se compara un valor de **cadena** | `StringMatcher::EXACT` (`=`) | `withStringMatcher(StringMatcher::CONTAINING)` y compañía |
| Sensibilidad a mayúsculas | sensible a la caja | `withIgnoreCase()` para todas las cadenas, `withIgnoreCase('email')` para una |
| Una propiedad que necesita sus propias reglas | sigue los valores por defecto de arriba | `withMatcher('email', GenericPropertyMatcher::startsWith()->caseSensitive())` |

Solo los valores de **cadena** reciben emparejamiento de cadena. Un `int`, un `float` o un `bool` se compara siempre con `=`, diga lo que diga el emparejador de cadenas por defecto — un emparejador `CONTAINING` no convierte calladamente `amount => 5` en `LIKE '%5%'`.

Un emparejador por ruta gana a los ajustes generales del emparejador para esa ruta y deja intacta cualquier otra:

<!-- source: packages/data/tests/Repository/Example/ExampleMatcherTest.php -->
```php
$matcher = ExampleMatcher::matching()
    ->withIgnoreCase()
    ->withMatcher('email', GenericPropertyMatcher::startsWith()->caseSensitive());

expect($matcher->isIgnoreCase('status'))->toBeTrue()
    ->and($matcher->isIgnoreCase('email'))->toBeFalse()
    ->and($matcher->stringMatcherFor('email'))->toBe(StringMatcher::STARTING)
    ->and($matcher->stringMatcherFor('status'))->toBe(StringMatcher::EXACT)
```

`StringMatcher` tiene cuatro casos — `EXACT`, `CONTAINING`, `STARTING`, `ENDING` — y los tres de `LIKE` escapan el valor antes de que se acerque siquiera a un patrón:

<!-- source: packages/data/src/Repository/Example/StringMatcher.php -->
```php
public function pattern(string $value): string
{
    $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);

    return match ($this) {
        self::EXACT => $escaped,
        self::CONTAINING => '%'.$escaped.'%',
        self::STARTING => $escaped.'%',
        self::ENDING => '%'.$escaped,
    };
}
```

Así que una sonda de `under_` busca un guion bajo literal, no «cualquier carácter». El fragmento que `Example` emite lleva `ESCAPE '!'`, la única forma de escape que se lee igual en sqlite, mysql, pgsql y sqlsrv, cosa que una contrabarra no consigue (mysql se la come dentro de la cadena antes).

El emparejador `REGEX` de Spring está deliberadamente ausente: no hay SQL portable para él.

### Compone, porque es una especificación

El sentido de portar `Example` *como* una `Specification` en vez de al lado de una es este — un ejemplo puede ser una hoja de un predicado mayor, y pagina exactamente igual que cualquier otro:

<!-- source: packages/data/tests/Repository/Example/QueryByExampleTest.php -->
```php
$page = $repo->findByExamplePaged(Example::of(['status' => 'open']), Pageable::of(1, 2, Sort::by('amount')->descending()));

expect($page->total)->toBe(3)
    ->and($page->items)->toHaveCount(2)
    ->and($page->items[0]->amount)->toBe(150);

$combined = Specifications::allOf(
    Example::of(['status' => 'closed']),
    Specifications::where(static fn (Builder $q): Builder => $q->where('amount', '>', 100)),
);

expect($repo->findBySpecification($combined))->toHaveCount(1);
```

!!! tip "Dónde se gana el sueldo la consulta por ejemplo"
    Un formulario de búsqueda de administración con ocho campos opcionales es el caso canónico. Construye la sonda con lo que la persona haya rellenado, ignora el resto, y no habrás escrito ni una línea de construcción condicional de consultas — los campos vacíos sencillamente no están en la sonda.

---

## Decir más sobre un método: `#[Modifying]`, `#[Projection]`, `#[Lock]`, `#[EntityGraph]`

Cuatro atributos permiten que un método de repositorio diga algo que el *nombre* del método no puede. Antes de cualquiera de ellos, una regla que atrapa a todo lector exactamente una vez:

!!! warning "Estos atributos necesitan un método **declarado**"
    PHP no puede adjuntar un atributo a un método que nunca se declara, y una consulta derivada alcanzada a través de `__call()` nunca se declara — existe solo como una etiqueta `@method` en un docblock. Así que un método que lleva uno de estos cuatro atributos tiene un cuerpo real, y ese cuerpo es siempre la misma línea: `return $this->dispatchQuery(__FUNCTION__, func_get_args());` (más el `assert()` que PHPStan necesite). Declarar el método no cambia nada de cómo se resuelve; solo le da al atributo un sitio donde vivir.

El escáner registra los cuatro en el manifiesto compilado en tiempo de construcción, y `EloquentRepository` los honra en el despacho **sin reflexión en tiempo de petición** — el constructor del DTO de un `#[Projection]`, por ejemplo, se refleja exactamente una vez, cuando `firefly:cache` compila el manifiesto.

### `#[Modifying]` — este método escribe

<!-- source: packages/data/tests/Fixtures/Repository/RecordRepository.php -->
```php
/** A statement that needs a transaction: returns the affected-row count. */
#[Modifying]
#[Query('update records set status = :status where amount < :amount')]
public function closeSmall(string $status, int $amount): int
{
    $affected = $this->dispatchQuery(__FUNCTION__, func_get_args());
    assert(is_int($affected));

    return $affected;
}

/** A statement that may run without a transaction. */
#[Modifying(requiresTransaction: false)]
#[Query('delete from records where status = :status')]
public function purgeStatus(string $status): int
{
    $affected = $this->dispatchQuery(__FUNCTION__, func_get_args());
    assert(is_int($affected));

    return $affected;
}
```

El SQL se ejecuta como sentencia y no como consulta, y el método devuelve el número de filas afectadas. Dos cosas se rechazan en tiempo de *escaneo*, así que te enteras de ellas por `firefly:cache` o por el primer arranque y nunca por la petición de una persona usuaria: `#[Modifying]` sin `#[Query]` (un `deleteBy…` derivado ya es una sentencia y no necesita atributo), y un `#[Query]` cuyo SQL es un `SELECT`, `WITH` o `VALUES`. En tiempo de ejecución la sentencia se niega a ejecutarse fuera de una transacción activa en la conexión del modelo — una `TransactionRequiredException` — salvo que renuncies a ello con `requiresTransaction: false`.

El `clearAutomatically` de Spring se acepta por compatibilidad de código fuente y no hace nada. Eloquent no tiene contexto de persistencia que limpiar, y fingir lo contrario sería la peor clase de paridad.

### `#[Projection]` — selecciona menos, hidrata un DTO

<!-- source: packages/data/tests/Fixtures/Repository/RecordSummary.php -->
```php
/** A class-based projection over three `records` columns; `email` is nullable in the table and here. */
final readonly class RecordSummary
{
    public function __construct(
        public int $id,
        public ?string $email,
        public int $amount,
    ) {}
}
```

<!-- source: packages/data/tests/Fixtures/Repository/RecordRepository.php -->
```php
#[Projection(RecordSummary::class)]
public function findByStatusOrderByAmountAsc(string $status): array
{
    $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
    assert(is_array($rows));

    /** @var list<RecordSummary> $rows */
    return $rows;
}
```

En un método **derivado** la lista del `SELECT` se infiere de los parámetros del constructor del DTO (o se da explícitamente con `columns:`); en un método `#[Query]` el SQL es dueño de su propia lista de selección. Cada fila se hidrata a través del constructor: columna en snake_case a parámetro en camelCase, escalares convertidos desde lo que el driver haya devuelto, un enum respaldado desde su valor de respaldo, un `DateTimeImmutable` desde la cadena, y un parámetro opcional cuya columna está ausente toma su valor por defecto.

La conversión es **sin pérdida o se rechaza**. `'150.75'` hacia un `int`, un valor de respaldo para el que el enum no tiene caso, una cadena que no es una fecha — cada uno es una `ConfigurationException` que nombra el DTO, la columna, el valor *y* el parámetro, nunca un `TypeError` pelado surgido de algún lugar profundo de PHP. Una columna obligatoria que falta y un `NULL` que llega a un parámetro no nulable son la misma clase de error, y las columnas se comprueban contra la tabla antes de que la consulta se ejecute (sqlite leería si no un identificador desconocido entre comillas dobles como un literal de cadena y devolvería un sinsentido).

Las proyecciones por interfaz de Spring (`interface RecordSummary { String getEmail(); }`) no se ofrecen. PHP no tiene un proxy en tiempo de ejecución que pudiera implementar una interfaz por nombre de columna; declara la clase DTO en su lugar.

### `#[Lock]` — toma el bloqueo de fila que la lectura necesita

<!-- source: packages/data/tests/Fixtures/Repository/RecordRepository.php -->
```php
/** @return list<Record> */
#[Lock(LockMode::PESSIMISTIC_WRITE)]
public function findByEmail(string $email): array
{
    $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
    assert(is_array($rows));

    /** @var list<Record> $rows */
    return $rows;
}
```

`PESSIMISTIC_WRITE` compila a `lockForUpdate()`, `PESSIMISTIC_READ` a `sharedLock()`, escritos por la gramática de la propia conexión. Ambos se niegan a ejecutarse fuera de una transacción activa — el comportamiento de Spring, y el correcto: un bloqueo de fila se libera cuando la transacción termina, así que fuera de una no protege nada. `$repo->findByIdForUpdate($id)` es el gemelo programático del atributo, y sqlite, que no tiene bloqueos de fila en absoluto, compila la cláusula a nada y sencillamente tiene éxito.

El escáner rechaza `#[Lock]` sobre un método `#[Query]`: el SQL en bruto lleva su propia cláusula de bloqueo, y dos de ellas se pelearían.

### `#[EntityGraph]` — carga ansiosa, de forma declarativa

<!-- source: packages/data/tests/Fixtures/Repository/RecordRepository.php -->
```php
/** @var array<string, list<string>> */
protected array $entityGraphs = ['Record.full' => ['entries']];
// …
/** @return list<Record> */
#[EntityGraph(attributePaths: ['entries'])]
public function findByStatusOrderByIdDesc(string $status): array
{
    $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
    assert(is_array($rows));

    /** @var list<Record> $rows */
    return $rows;
}
```

Se mapea al `with()` de Eloquent, y es el único atributo que alcanza métodos que no escribiste. Cada lectura heredada que **abre un builder** — `findById`, `findAll`, `findAllById`, `findPaged`, `findSorted`, `findSlice`, `findBySpecification`, `findByExample`, `findOneByExample`, `findByIdForUpdate` y las demás — parte de `reading(__FUNCTION__)`, que pide al manifiesto el grafo registrado contra *esta clase de repositorio y ese nombre de método*. Así que anotar una sobrescritura que no hace más que `return parent::findAll();` es la receta entera, exactamente igual que en Spring. Las cuatro lecturas que responden una pregunta *sobre* las filas en vez de devolverlas — `existsById()`, `count()`, `existsByExample()`, `countByExample()` — van directas a `query()` y deliberadamente no tocan nunca `reading()`: un grafo de entidades no significa nada para un `COUNT(*)`, y cargar relaciones con ansia para tirarlas sería coste puro. Un grafo con nombre que el repositorio nunca declaró en `$entityGraphs` es una `ConfigurationException` en el primer uso.

!!! warning "Dos combinaciones que no funcionan como te gustaría"
    Un `#[Projection]` y un `Pageable` final **no se combinan** — la proyección devuelve el resultado entero, sin paginar. Pagina las entidades, o pon un `LIMIT` en el `#[Query]`. Y `#[Query]` vincula **posicionalmente**: los `:marcadores` con nombre se reescriben a `?` por orden de primera aparición, así que un marcador usado dos veces necesita que el argumento se pase dos veces.

---

## Paginación: `Page`, `Pageable` y `Sort`

Tres pequeños objetos de valor inmutables transportan de principio a fin una petición de página y devuelven un resultado de página. `Pageable` es la petición — un número de página basado en 1, un tamaño, y un `Sort` opcional:

<!-- source: packages/data/src/Repository/Pageable.php -->
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
```

`Sort` y `Order` componen una ordenación a partir de piezas pequeñas e inmutables — cada combinador devuelve un `Sort` *nuevo*, nunca muta aquel con el que empezaste:

<!-- source: packages/data/src/Repository/Sort.php -->
```php
final readonly class Sort
{
    /**
     * @param  list<Order>  $orders
     */
    public function __construct(public array $orders = []) {}
    // …
    public static function by(string ...$properties): self
    {
        return new self(array_map(
            static fn (string $property): Order => Order::asc($property),
            array_values($properties),
        ));
    }
    // …
    public function descending(): self
    {
        return new self(array_map(
            static fn (Order $order): Order => Order::desc($order->property),
            $this->orders,
        ));
    }
```

`Order::asc('created_at')`/`Order::desc('created_at')` empareja un nombre de propiedad con un enum `Direction::Asc`/`Direction::Desc` cuyo *valor* de cadena subyacente **es** la dirección de `orderBy()` de Eloquent — `$order->direction->value` no necesita ninguna tabla de traducción en absoluto.

`Page` es lo que vuelve — el recorte de filas más todo lo que un cliente necesita para renderizar un paginador:

<!-- source: packages/data/src/Repository/Page.php -->
```php
/**
 // …
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
    // …
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

### `Slice`: la página que no cuenta

`total` es caro. Sobre una tabla grande, un `SELECT COUNT(*)` sobre el mismo predicado puede costar más que traer la página misma, y una lista de scroll infinito no muestra el número de todas formas — solo necesita saber si mantener el botón de «cargar más». Eso es un `Slice`: todo lo que tiene `Page` salvo `total`, más el único hecho que ese botón necesita.

<!-- source: packages/data/src/Repository/Slice.php -->
```php
final readonly class Slice
{
    /**
     * @param  list<T>  $items
     */
    public function __construct(
        public array $items,
        public bool $hasNext,
        public int $page = 1,
        public int $size = 20,
    ) {}

    public function hasNext(): bool
    {
        return $this->hasNext;
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function numberOfElements(): int
    {
        return count($this->items);
    }

    public function nextPageable(): Pageable
    {
        return Pageable::of($this->page + 1, $this->size);
    }
```

`hasNext` no se adivina y no se cuenta. El repositorio trae **una fila más** que el tamaño de página, y si esa fila extra volvió se descarta y se recuerda como «hay una página siguiente»:

<!-- source: packages/data/src/Repository/EloquentRepository.php -->
```php
private function sliceOf(Builder $query, Pageable $pageable): Slice
{
    $take = $pageable->isPaged() ? $pageable->size + 1 : PHP_INT_MAX;

    $rows = $this->applySort($query, $pageable->sort)
        ->skip($pageable->offset())
        ->take($take)
        ->get()
        ->all();

    $hasNext = $pageable->isPaged() && count($rows) > $pageable->size;
```

Así que un recorte es una consulta barata donde una página es una consulta barata *más* una cara. `$repo->findSlice(Pageable::of(3, 20, Sort::by('id')))` es la página sin cuenta del propio puerto; `Slice::nextPageable()` te entrega la petición de la siguiente, y `Slice::map()` transforma los elementos exactamente igual que `Page::map()`.

Un método derivado también puede devolver un recorte — y aquí las dos mitades de la regla importan por separado. Un **argumento `Pageable` final** es lo que hace que un método derivado pagine siquiera; el **tipo de retorno declarado** es lo que lo convierte en un `Slice` y no en una `Page`:

<!-- source: packages/data/tests/Fixtures/Repository/RecordRepository.php -->
```php
#[EntityGraph(attributePaths: ['entries'])]
public function findByStatusOrderByIdAsc(string $status, Pageable $pageable): Slice
{
    $slice = $this->dispatchQuery(__FUNCTION__, func_get_args());
    assert($slice instanceof Slice);

    /** @var Slice<Record> $slice */
    return $slice;
}
```

Una consulta derivada `@method` sin declarar no tiene tipo de retorno declarado que leer, así que un `Pageable` sobre una produce siempre una `Page`. Es la misma regla que obedecen los cuatro atributos, llegando desde otra dirección.

---

## Borrado suave, auditoría y bloqueo optimista

Tres bloques constructivos más viven en `EloquentRepository`, apoyados sobre los propios mecanismos de Eloquent en vez de reinventados. Ninguno lo ejercita `Wallet` en este libro, pero cada uno está a una sola declaración `use` de distancia en cualquier modelo que lo necesite.

El **borrado suave** reutiliza tal cual el trait nativo `SoftDeletes` de Eloquent:

<!-- source: packages/data/tests/Fixtures/Repository/SoftRecord.php -->
```php
final class SoftRecord extends Model
{
    use SoftDeletes;
// …
}
```

Una vez que un modelo lo usa, `delete()`/`deleteById()` a través del repositorio borra suavemente la fila, y cada lectura ordinaria excluye de forma transparente las filas eliminadas mediante el propio ámbito global de Eloquent. `findAllIncludingDeleted()` y `restore(mixed $id): ?object` completan el ciclo de vida.

La **auditoría** es un trait `Auditable` opcional que estampa `created_by`/`updated_by` al escribir, impulsado por un puerto `AuditorAware`:

<!-- source: packages/data/src/Repository/Auditing/AuditorAware.php -->
```php
interface AuditorAware
{
    public function currentAuditor(): int|string|null;
}
```

El **bloqueo optimista** protege escrituras concurrentes con una columna entera `version` vía `HasOptimisticLock`, que sobrescribe el `performUpdate()` interno de Eloquent para añadir `WHERE version = <versión cargada>` a cada `UPDATE` — una escritura contra una versión obsoleta no coincide con ninguna fila y lanza `OptimisticLockException` en vez de sobrescribir silenciosamente el cambio de otro. Esa excepción extiende la `OptimisticLockingFailureException` del kernel, así que un `catch` sobre cualquiera de los dos nombres funciona y la regla de la sección siguiente también se le aplica.

---

## Cuando el driver falla: la familia `DataAccessException`

Todo lo anterior ha dado por supuesto que la base de datos dice que sí. Esta sección trata de lo que llega a tu código cuando dice que no — y la respuesta del framework es la de Spring: **un fallo del driver nunca sale de `firefly/data` como un fallo del driver.**

La respuesta propia de Laravel es `QueryException`. Es una sola clase para todo fallo posible, y dos cosas la convierten en algo pobre que entregar a una aplicación. La primera es que no puedes ramificar sobre ella: una dirección de correo duplicada y un servidor de base de datos inalcanzable llegan como el mismo tipo, así que distinguirlas significa comparar códigos de error del driver en el punto de llamada, una vez por cada driver que soportes. La segunda es peor. El mensaje de una `QueryException` es la sentencia que falló **con sus enlaces interpolados** — es decir, la dirección de correo, el token o el identificador de inquilino que se estaba escribiendo. Cualquier cosa que renderice ese mensaje a un cliente, o que lo registre a un nivel que otra persona pueda leer, ha filtrado datos de usuario.

`PersistenceExceptionTranslator` arregla las dos de golpe:

<!-- source: packages/data/src/Exception/PersistenceExceptionTranslator.php -->
```php
private static function build(?string $kind, Throwable $cause, ?string $sqlState): DataAccessException
{
    $translated = match ($kind) {
        DriverErrorTable::DUPLICATE_KEY => new DuplicateKeyException(previous: $cause),
        DriverErrorTable::INTEGRITY => new DataIntegrityViolationException(previous: $cause),
        DriverErrorTable::DEADLOCK => new DeadlockLoserDataAccessException(previous: $cause),
        DriverErrorTable::LOCK => new CannotAcquireLockException(previous: $cause),
        DriverErrorTable::TIMEOUT => new QueryTimeoutException(previous: $cause),
        DriverErrorTable::TRANSIENT => new TransientDataAccessResourceException(previous: $cause),
        DriverErrorTable::RESOURCE => new DataAccessResourceFailureException(previous: $cause),
        DriverErrorTable::GRAMMAR => new BadSqlGrammarException(previous: $cause),
        default => new DataAccessException('The database refused the operation.', previous: $cause),
    };

    return $sqlState === null ? $translated : $translated->withExtensions(['sqlState' => $sqlState]);
}
```

Lee lo que *no* está en ese método. No se copia ningún `$cause->getMessage()` a la excepción nueva. Cada uno de esos constructores lleva su propia **frase fija**, escrita una vez, sin sentencia y sin enlaces dentro. El texto propio del driver se queda exactamente donde corresponde — en `previous`, para el log y la traza de pila — y la única pieza de la respuesta del driver que es a la vez inofensiva y útil, el SQLSTATE, viaja al lado como el miembro de extensión `sqlState` del documento de problema.

El tipo es el diagnóstico, y es sobre lo que ramifica quien llama:

| Se lanza | Extiende | HTTP | Cuándo |
|---|---|---|---|
| `DuplicateKeyException` | `DataIntegrityViolationException` | 409 | una violación de unicidad o de clave primaria |
| `DataIntegrityViolationException` | `DataAccessException` | 409 | cualquier otra restricción: not-null, clave foránea, check |
| `DeadlockLoserDataAccessException` | `CannotAcquireLockException` | 409 | esta transacción fue elegida como víctima del interbloqueo |
| `CannotAcquireLockException` | `DataAccessException` | 409 | la espera de un bloqueo agotó su tiempo |
| `QueryTimeoutException` | `DataAccessException` | 504 | la sentencia sobrepasó su tiempo límite |
| `TransientDataAccessResourceException` | `DataAccessException` | 503 | la conexión se cayó — reintentar puede funcionar |
| `DataAccessResourceFailureException` | `DataAccessException` | 503 | el origen de datos no está ahí en absoluto |
| `BadSqlGrammarException` | `DataAccessException` | 500 | la sentencia es incorrecta; reintentar no puede ayudar |
| `DataAccessException` | `InfrastructureException` | 500 | un fallo que las tablas no reconocen |

La jerarquía es el sentido de todo esto. Un manejador al que solo le importa «la escritura entró en conflicto» captura `DataIntegrityViolationException` y se lleva también las claves duplicadas; uno al que le importa «cualquier cosa que la base de datos rechazó» captura `DataAccessException` y se lleva las nueve. Como todas ellas son una `FireflyException`, el renderizador de detalles de problema del Capítulo 4 ya conoce el código de estado y el código de error sin una sola línea de mapeo — `DUPLICATE_KEY`, `QUERY_TIMEOUT`, `BAD_SQL_GRAMMAR` y los demás viajan al cliente como `type`/`code`, y la sentencia no viaja a ninguna parte.

Dos miembros más de la familia vienen del repositorio y no de un driver: `getById($id)` lanza `EmptyResultDataAccessException` (404) exactamente donde `findById()` habría devuelto `null`, y `findOneByExample()` lanza `IncorrectResultSizeDataAccessException` cuando la sonda coincidió con más de una fila.

### Dónde ocurre la traducción, y dónde deliberadamente no

<!-- source: packages/data/src/Exception/PersistenceExceptionTranslator.php -->
```php
public function translate(Throwable $e, ?string $driver = null): Throwable
{
    if (! $this->enabled || $e instanceof DataAccessException) {
        return $e;
    }
    // …
    if (! $e instanceof PDOException) {
        return $e;
    }
    // …
    $driver ??= $e instanceof QueryException ? self::driverOf($e->getConnectionName()) : null;
    $sqlState = self::sqlState($e);
    $kind = DriverErrorTable::kindFor($driver, self::driverCode($e), $sqlState);
```

Hay exactamente dos costuras por las que un error de base de datos puede salir de `firefly/data`, y las dos traducen: cada método de `EloquentRepository` (eso es lo que hace la envoltura `translating()` que viste alrededor de `save()` y `findBySpecification()`), y `TransactionTemplate::execute()` — lo que significa cada método `#[Transactional]` del capítulo siguiente, ya que el proxy delega en la plantilla. Una llamada `DB::` en bruto que hagas fuera de ambas sigue lanzando la `QueryException` de Laravel exactamente igual que siempre. No se parchea nada globalmente, y no se parchea nada en caliente.

Tres guardas mantienen eso estrecho. Un throwable que **ya** está en la familia se devuelve intacto, así que una llamada a repositorio que traduce dentro de una plantilla que vuelve a traducir es idempotente en vez de quedar doblemente envuelta. Cualquier cosa que no sea una `PDOException` pasa de largo — con una excepción para los cuatro fallos que el propio Laravel ya ha clasificado (`UniqueConstraintViolationException`, `SQLiteDatabaseDoesNotExistException`, `LostConnectionException`, `DeadlockException`), cuya respuesta es al menos tan buena como la de cualquier tabla, y que se buscan **también en la causa**, porque Laravel envuelve lo que sea que lance el callback de la consulta. Y todo el mecanismo está a una clave de apagarse: `firefly.data.exception-translation.enabled`, `true` por defecto.

Qué tabla de códigos se aplica lo decide el nombre del driver. Las tablas en sí — códigos de error de driver para sqlite, mysql, mariadb y sqlsrv, SQLSTATEs exactos, y *clases* de SQLSTATE como recurso final — viven en un único fichero, `DriverErrorTable`, con una fila de test por cada fila de tabla. sqlite necesita un paso extra, y está en el código fuente en vez de escondido: sqlite reporta cada violación de restricción como código 19, así que la familia de unicidad/clave primaria solo es visible en el texto del mensaje, y ese es el único sitio donde el traductor lee un mensaje del driver.

!!! tip "La regla que merece la pena recordar"
    Captura el **tipo**, registra el **`previous`**, renderiza la **frase fija**. Si alguna vez te encuentras leyendo `$e->getMessage()` para decidir qué pasó, el tipo que necesitabas ya existe.

---

## El puente de eventos de dominio

`EloquentRepository::save()` hace algo más, además de persistir la fila, que importa enormemente para el Capítulo 6: si la entidad guardada también implementa `RecordsDomainEvents` — que es el caso de `Wallet` — **y** hay una transacción activa en ese momento sobre la propia conexión de esa entidad, `save()` registra la entidad en `AggregateTracker`, el registro de unidad de trabajo de `firefly/data`:

<!-- source: packages/data/src/Repository/EloquentRepository.php -->
```php
public function save(object $entity): object
{
    return $this->translating(function () use ($entity): object {
        if ($entity instanceof Model) {
            $entity->save();
        }

        if ($entity instanceof RecordsDomainEvents
            && $this->tracker !== null
            && $this->connectionFor($entity)->transactionLevel() > 0) {
            $this->tracker->track($entity);
        }

        return $entity;
    });
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
| `Example` / `ExampleMatcher` | Consulta por ejemplo, portada *como* una `Specification`, así que compone y pagina como cualquier otro predicado |
| `#[Modifying]` / `#[Projection]` / `#[Lock]` / `#[EntityGraph]` | Lo que un método **declarado** puede decir y su nombre no: escrituras, hidratación de DTO, bloqueos de fila, carga ansiosa |
| `Page` / `Pageable` / `Sort` / `Order` | Objetos de valor inmutables que transportan una petición de página y devuelven un resultado de página (con metadatos) |
| `Slice` | La misma página sin la consulta de cuenta: una fila traída de más responde a `hasNext` |
| `SoftDeletes` / `Auditable` / `HasOptimisticLock` | Traits opcionales para borrado suave, estampado `created_by`/`updated_by`, y escrituras protegidas por versión |
| Familia `DataAccessException` | Un fallo del driver se convierte en una excepción tipada con una frase fija; la sentencia nunca sale del log |
| El puente de eventos de dominio | `save()` registra una entidad `RecordsDomainEvents` en `AggregateTracker` mientras hay una transacción activa |

---

## Ponlo en práctica {.exercises}

1. **Añade un contador derivado.** En una copia de trabajo del proyecto, declara `public function countByCurrency(string $currency): int` sin cuerpo en un repositorio que extienda `EloquentRepository`, y confirma que llamarlo se compila a `SELECT COUNT(*) … WHERE currency = ?` sin SQL propio.
2. **Compón dos especificaciones.** Construye una `Specification` para "saldo de al menos N" y otra para "en la moneda C" con `Specifications::where(...)`, combínalas con `Specifications::allOf(...)`, y ejecuta el compuesto a través de `findBySpecificationPaged` contra una pequeña tabla poblada de prueba.
3. **Convierte una página en un recorte.** Toma la lectura paginada del ejercicio anterior y pide `findSlice(Pageable::of(1, 2))` en su lugar. Activa el registro de consultas y confirma lo que la sección afirma: el recorte ejecuta **una** consulta donde la página ejecutaba dos, y `hasNext` sigue siendo correcto.
4. **Haz que la base de datos diga que no.** Añade un índice único a la tabla de monederos, guarda dos monederos con el mismo identificador de propietario, y captura el resultado. Comprueba tres cosas: el tipo es `DuplicateKeyException`, `getMessage()` *no* contiene el identificador de propietario que escribiste, y `getPrevious()` es la `QueryException` de Laravel con la sentencia todavía encima.
5. **Lee una página de monederos.** Llama a la contraparte de paginación de `EloquentWalletRepository::findAll(...)` (añade un llamador de `findPaged` a través de `WalletRepository` en tu proyecto de pruebas) con `Pageable::of(1, 2, Sort::by('created_at')->descending())` contra tres monederos de prueba poblados, y confirma que `total`, `totalPages` y `hasNext` coinciden todos con lo que esperas antes y después de `Page::map()`.
