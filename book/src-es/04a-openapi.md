<span class="eyebrow">Parte I — Fundamentos · Capítulo 4A</span>

# Documentar la API: OpenAPI 3.1 desde los Manifiestos {.chtitle}

Al terminar este capítulo sabrás cómo `firefly/openapi` convierte el `RouteManifest` y el `ConstraintManifest` que el Capítulo 4 acaba de construir en un documento OpenAPI 3.1 válido **sin ningún dialecto de anotaciones propio** — cómo `firefly:openapi` convierte ese documento en un artefacto de compilación que un job de CI puede diferenciar, cómo cada `kind` de enlace del plan de ruta compilado se convierte en un Parameter Object o en un Request Body, cómo un `#[NotBlank]` o un `#[Positive]` que ya escribiste se convierte en un `pattern` o en un `exclusiveMinimum`, por qué cada DTO se registra una sola vez y se alcanza por `$ref` en lugar de incrustarse, por qué cada operación lleva el mismo componente de error `problem+json` que produce el renderizador del Capítulo 4, y por qué una ruta HTML `#[Controller]` queda fuera del documento por defecto. Cierra con la consola de navegador que el paquete sirve sobre ese documento — tres estilos, de los cuales el predeterminado es el **Swagger UI oficial servido desde tu propio origen**, sin paso de npm y sin petición a CDN, y de los cuales solo uno habla alguna vez con un tercero.

!!! note "Término nuevo: extensión de especificación"
    OpenAPI 3.1 permite que un documento lleve miembros cuyos nombres empiezan por `x-`, llamados **extensiones de especificación**. Las herramientas conformes deben ignorarlas, de modo que una extensión puede registrar algo que el vocabulario estándar no sabe expresar sin invalidar el documento. `firefly/openapi` usa exactamente una, `x-firefly-constraints`, y este capítulo muestra qué acaba en ella y por qué nunca se descarta nada en silencio.

---

## No hay nada que anotar

Todas las cadenas de herramientas OpenAPI en PHP anteriores a esta te piden escribir el documento dos veces: una como el código que ejecuta el servidor, y otra como anotaciones, atributos o un fichero YAML que *describen* el código que ejecuta el servidor. Las dos divergen la primera vez que alguien añade un campo con prisa, y la divergencia es invisible — el documento sigue validando, simplemente ya no coincide con el servidor.

`firefly/openapi` no tiene ese modo de fallo disponible, porque no tiene una segunda fuente. El Capítulo 4 terminó con el `RouteManifest`: la tabla compilada de cada `RouteDescriptor` desde la que sirve el dispatcher, que lleva el verbo, la ruta, el estado declarado, el nombre de ruta, la clase y el método del controlador, y el *plan de enlace* por parámetro. El Capítulo 4 también presentó el `ConstraintManifest`: la lista de reglas compilada que `BeanValidator` ejecuta sobre un cuerpo `#[Valid]`. Esos dos artefactos, más el `ErrorResponse` de `firefly/kernel`, son toda la entrada:

```bash
composer require firefly/openapi
```

Esa es toda la instalación. Arranca la app y `GET /openapi.json` queda servido; `GET /openapi` renderiza una consola de referencia sobre él. No se anotó nada, y nada puede divergir, porque cada hecho del documento se lee del mismo artefacto compilado que lee el dispatcher.

---

## `firefly:openapi`, y rutas que no son rutas por atributo

El documento también es un fichero que puedes versionar:

```bash
php artisan firefly:openapi --output=docs/openapi.json   # escribe el fichero e imprime una línea de resumen
php artisan firefly:openapi > openapi.json               # escribe el documento en crudo a stdout
```

El comando existe para que el documento pueda ser un **artefacto de compilación** y no solo un endpoint vivo. Versionar el fichero generado es lo que permite a un job de CI diferenciarlo y hacer fallar un pull request que cambió la API pública sin decirlo, y lo que permite a un repositorio de front-end regenerar su cliente tipado desde una especificación versionada sin arrancar la aplicación PHP en absoluto. Es además la única forma de obtener un documento de un despliegue que mantiene `firefly.openapi.enabled` apagado en producción.

El modo stdout está escrito con la bandera `OUTPUT_RAW` de Symfony, y ese detalle importa más de lo que parece: la salida de consola pasa normalmente por el formateador de Symfony, que trata `<...>` como marcado. Una `description` que mencione un tipo genérico — cualquier cosa que lleve un ángulo y haya llegado al documento desde un valor de configuración — sería o bien engullida o bien lanzaría una excepción ante una etiqueta desconocida. El sentido del modo stdout es canalizar directamente hacia un generador de clientes, así que los bytes deben ser exactamente los bytes del documento. Por eso también la línea de confirmación se imprime **solo** en modo `--output`, donde stdout no es el documento.

Las rutas HTTP — la especificación, la consola y los propios assets de la consola — se montan de forma nativa sobre el `Router` de Illuminate desde un `BootPass`, no se declaran con `#[GetMapping]`:

```php
final class OpenApiRouteRegistrar implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 60;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;

        /** @var OpenApiProperties $properties */
        $properties = $container->make(OpenApiProperties::class);

        if (! $properties->enabled) {
            return;
        }

        /** @var Router $router */
        $router = $container->make('router');

        $router->get($properties->specPath, static fn (): mixed => $container->make(OpenApiSpecAction::class)())
            ->name('firefly.openapi.spec');

        if (! $properties->viewerEnabled) {
            return;
        }

        $router->get($properties->viewerPath, static fn (): mixed => $container->make(OpenApiViewerAction::class)())
            ->name('firefly.openapi.viewer');

        // The official Swagger UI files, served from this application's own origin rather than a CDN. Mounted
        // under the viewer path so moving the console moves its assets with it, and constrained to a single
        // path segment so the route cannot express a traversal in the first place — SwaggerAssets whitelists
        // and realpath-checks the name as well.
        $router->get($properties->viewerPath.'/assets/{file}', static fn (string $file): mixed => $container->make(SwaggerAssetAction::class)($file))
            ->where('file', '[A-Za-z0-9._-]+')
            ->name('firefly.openapi.assets');
    }
}
```

Esta es la misma forma — y el mismo idiom de `BootPass` — que el Capítulo 11 te mostrará para las rutas propias del actuator, y se elige por dos razones independientes. Primera, **una ruta por atributo no puede ser configurable**: `#[GetMapping('/openapi.json')]` hornea su literal dentro de un `RouteDescriptor` compilado en tiempo de `firefly:cache`, de modo que un operador nunca podría mover la especificación de una ruta que choca con una suya, ni podría quitarla de una superficie pública sin borrar el paquete. Segunda, una ruta por atributo entraría en el `RouteManifest` de la aplicación — y el generador lee ese manifiesto, así que **el paquete se documentaría a sí mismo**. Registrarlas de forma nativa deja ambos problemas fuera de la existencia: las rutas salen de la configuración en el arranque, y nunca aparecen en la especificación que sirven. Fíjate en que la ruta de assets se monta *bajo* la ruta del visor, así que mover la consola mueve consigo su hoja de estilos y sus scripts.

`firefly.openapi.enabled` (por defecto `true`) se aplica *aquí*, sobre las rutas, y no sobre los beans — el generador y sus colaboradores son inertes sin rutas, así que cerrar las rutas es todo el interruptor. Apagarlo deja todas esas rutas genuinamente sin enrutar, de modo que devuelven 404 a través de la propia `NotFoundHttpException` del router, que el `ProblemDetailsRenderer` del Capítulo 4 renderiza entonces como un cuerpo de problem-details `404` en condiciones, no como un `500`.

!!! note "Las acciones se resuelven por petición, dentro de la clausura"
    Construir una `OpenApiSpecAction` en el arranque y capturarla en la ruta congelaría un `OpenApiGenerator` dentro de la ruta durante toda la vida del proceso — exactamente la forma que se rompe bajo Octane, donde el contenedor de una petición posterior es un sandbox distinto. `$container->make(...)` *dentro* de la clausura es la regla, aquí y en cualquier otro paquete del framework que monte una ruta nativa.

---

## Del `RouteManifest` a los Operation Objects

`OpenApiGenerator::generate()` es el punto de entrada — memoiza una única pasada privada `build()` (`$this->document ??= $this->build()`), y `toJson()` la envuelve para un fichero o un cuerpo HTTP. Esa pasada recorre el manifiesto una vez, salta las rutas excluidas, y entrega cada superviviente a `OperationFactory`. Todo lo relativo al resultado es determinista a propósito:

```php
final class OpenApiGenerator
{
    private const array VERB_ORDER = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    private function sortVerbs(array $item): array
    {
        $sorted = [];

        foreach (self::VERB_ORDER as $verb) {
            if (array_key_exists($verb, $item)) {
                $sorted[$verb] = $item[$verb];
                unset($item[$verb]);
            }
        }

        ksort($item);

        return [...$sorted, ...$item];
    }
}
```

Las rutas se ordenan, los verbos dentro de una ruta se ordenan en el orden canónico en que la propia especificación OpenAPI los enumera, y el registro de esquemas ordena los componentes por nombre. Esto no es pulcritud por la pulcritud. El orden de descubrimiento de rutas depende de la iteración del sistema de ficheros, así que un documento *sin ordenar* se rebarajaría entre máquinas y convertiría cada regeneración en un diff irrevisable — que es exactamente lo que hace que los equipos dejen de versionar el fichero generado, que es lo que hace que se quede obsoleto.

Dentro de una operación, el plan de enlace hace el trabajo de verdad. `OperationFactory` despacha sobre el mismo discriminador `kind` que usa `ArgumentResolver` en tiempo de petición:

```php
final class OperationFactory
{
    public function create(RouteDescriptor $route, string $operationId, SchemaRegistry $registry): array
    {
        $parameters = [];
        $body = null;
        $files = [];
        $validated = false;
        $rejectable = false;

        foreach ($route->bindings as $binding) {
            $validated = $validated || $binding['valid'];

            switch ($binding['kind']) {
                case 'path':
                    $parameters[] = $this->parameter($binding, 'path', true);
                    $rejectable = $rejectable || $this->coercible($binding);
                    break;
                case 'query':
                    $parameters[] = $this->parameter($binding, 'query', $binding['required']);
                    $rejectable = $rejectable || $binding['required'] || $this->coercible($binding);
                    break;
                case 'header':
                    $parameters[] = $this->parameter($binding, 'header', $binding['required']);
                    $rejectable = $rejectable || $binding['required'] || $this->coercible($binding);
                    break;
                case 'file':
                    $files[] = $binding;
                    $rejectable = true;
                    break;
                case 'body':
                    $body = $binding;
                    $rejectable = true;
                    break;
            }
        }

        // …the operation's own prose (operationId, summary, description, tags) is assembled here…

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($body !== null) {
            $operation['requestBody'] = $this->requestBody($body, $registry);
        } elseif ($files !== []) {
            $operation['requestBody'] = $this->multipartBody($files);
        }

        $operation['responses'] = $this->responses($route, $rejectable, $validated);

        return $operation;
    }
}
```

El único bloque elidido es donde se compone la prosa de cara al humano de la operación; todo lo que se muestra es lo que decide el *plan de enlace*. Leer el plan en lugar de releer la firma del método es lo que hace inequívoco el mapeo. `#[PathVariable]`, `#[QueryParam]` y `#[RequestHeader]` se convierten en Parameter Objects; `#[UploadedFile]` se convierte en una parte `multipart/form-data` tipada como `format: binary`; `#[RequestBody]` se convierte en el Request Body Object; y el sexto `kind`, `service` — el colaborador inyectado por el contenedor sin atributo que presentó el Capítulo 4 — no forma parte del contrato HTTP en absoluto y nunca aparece en el documento. Derivar esa lista de forma independiente tendría que volver a decidir cada uno de esos casos y podría discrepar del dispatcher; leer el plan no puede.

Cuatro decisiones menores rematan una operación:

- **Plantilla de ruta.** La grafía de parámetro opcional de Laravel, `{id?}`, no tiene equivalente en OpenAPI — allí un parámetro de ruta es obligatorio, punto — así que el marcador se elimina y el parámetro queda obligatorio. Emitir dos Path Items en su lugar describiría una superficie de router que no existe y duplicaría cada operación así en un cliente generado.
- **`operationId`.** El `name` de la ruta cuando lo tiene, y si no, derivado como el nombre corto del controlador menos un `Controller` final, en minúscula inicial, más el nombre del método: `Lumen\Web\WalletController::balance()` se convierte en `walletBalance`. Como `operationId` debe ser único en todo el documento — y un duplicado es el único fallo que hace que la mayoría de generadores de clientes aborten en lugar de degradar — una reclamación repetida se sufija (`walletBalance_2`) en lugar de permitirse que sobrescriba.
- **`tags`.** El mismo nombre corto, de modo que cada operación de `WalletController` se agrupa bajo "Wallet" en un visor.
- **`summary`.** Separado del nombre del método: `getBalance` se lee como "Get balance". El nombre de un método es la única etiqueta escrita por un humano que lleva una ruta — el `name` de un `#[Mapping]` es un nombre de ruta de Laravel, no prosa — así que es la fuente honesta.

---

## Cuerpos de petición: un componente por DTO, alcanzado por `$ref`

El `OpenWalletRequest` del Capítulo 4 vuelve a ser el ejemplo conductor:

```php
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

`POST /api/v1/wallets` lo enlaza con `#[Valid] #[RequestBody]`, y la operación generada lo referencia en lugar de repetirlo:

```json
{
  "requestBody": {
    "required": true,
    "content": {
      "application/json": {
        "schema": { "$ref": "#/components/schemas/OpenWalletRequest" }
      }
    }
  }
}
```

`SchemaRegistry` es lo que hace posible ese `$ref`, y resuelve dos problemas que tiene un generador ingenuo del tipo "incrusta el esquema en cada sitio de uso". El primero es la **duplicación**: un DTO usado por seis operaciones se emitiría seis veces, y cada cliente generado acuñaría seis tipos anónimos estructuralmente idénticos con seis nombres distintos. Registrarlo una vez y referirlo por puntero es lo que hace que `openapi-generator`, `orval` y `kiota` produzcan *un* tipo con nombre por DTO — que es todo el sentido de generar el documento en primer lugar. El segundo es la **recursión**: un DTO con un miembro `#[Valid] ?self $parent` no puede incrustarse en absoluto, porque la expansión no termina. Por eso el registro reserva el nombre del componente *antes* de invocar al constructor del esquema, de modo que una llamada anidada para la misma clase encuentra el nombre ya tomado y devuelve el puntero de inmediato, cerrando el ciclo. Un ciclo de `$ref` es legal y útil en un documento donde una lista de reglas aplanada sería infinita.

Los nombres de componente son el nombre corto de la clase, porque es lo que lee un humano en un visor y lo que un generador convierte en un nombre de tipo. Dos DTOs que compartan nombre corto entre espacios de nombres — `Order\Dto\Address` y `Billing\Dto\Address` — chocarían y uno sobrescribiría al otro en silencio, así que el **segundo** reclamante de un nombre cae a su nombre completamente cualificado con puntos: feo, inequívoco y raro. Gana el primer reclamante, de modo que añadir un segundo `Address` en otro punto de la app nunca renombra el que ya estaba publicado.

Dos propiedades del esquema emitido merecen decirse explícitamente porque ambas son decisiones y no omisiones. La lista de miembros es la **lista de parámetros del constructor en orden de declaración**, porque es exactamente de donde hidrata `ArgumentResolver` — pero una restricción asociada a un miembro sin parámetro de constructor se documenta igualmente, porque `BeanValidator` valida el array decodificado en bruto y por tanto la exige en la entrada de todos modos. Y **nunca se emite `additionalProperties: false`**: el servidor genuinamente ignora las claves fuera de la lista del constructor, así que una especificación que afirmara lo contrario haría que clientes conformes rechazaran peticiones que el servidor habría servido.

---

## Las restricciones se convierten en palabras clave de esquema

Ninguna de las dos mitades de un DTO basta por sí sola. El `ConstraintManifest` conoce el contrato de *validación* pero nada sobre tipos, porque una lista de reglas es, por construcción, sin tipos. El constructor conoce el contrato de *tipos* — `?int`, un enum respaldado, un DTO anidado, un valor por defecto — pero nada sobre las restricciones. Solo con los tipos, `#[NotBlank] string $name` se documentaría como una cadena sin límites; solo con las restricciones, `int $quantity` se documentaría como una cadena. `DtoSchemaFactory` las fusiona, y `ConstraintSchemaMapper` mapea la segunda mitad.

Mapea el **manifiesto compilado**, nunca los atributos `#[Constraint]`. Leer los atributos de vuelta desde el DTO sería la ruta obvia hacia "`#[Email]` → `format: email`", y documentaría un validador que no existe: el manifiesto ya ha aplicado el contrato de nulos Jakarta de `ConstraintScanner`, ya ha expandido `#[Size]` en un *objeto* regla de primera parte en vez de las cadenas polimórficas `min:`/`max:` de Laravel, y ya ha aplanado un nivel de `#[Valid]` en claves con puntos. Generar desde los atributos volvería a derivar todo eso a mano y divergiría la primera vez que cambiara un cuerpo de `toRules()`. Generar desde el manifiesto no puede divergir, porque el manifiesto *es* el contrato.

Este es el mapeo real, restricción a restricción, con las reglas compiladas en la columna central para que veas que el mapeador lee reglas y no atributos:

| Restricción | Compila a | JSON Schema |
|---|---|---|
| `#[NotBlank]` | `required`, `string`, `regex:/\S/` | miembro añadido a `required`; `type: string`; `pattern: \S` |
| `#[NotEmpty]` | `required` | miembro añadido a `required` |
| `#[NotNull]` | `present` + un objeto regla `NotNull` | miembro añadido a `required` **y** `null` retirado de la unión de tipos |
| `#[Min(n)]` / `#[Max(n)]` | `numeric`, `gte:n` / `lte:n` | `minimum` / `maximum` |
| `#[Positive]` / `#[Negative]` | `numeric`, `gt:0` / `lt:0` | `exclusiveMinimum: 0` / `exclusiveMaximum: 0` |
| `#[PositiveOrZero]` / `#[NegativeOrZero]` | `numeric`, `gte:0` / `lte:0` | `minimum: 0` / `maximum: 0` |
| `#[Email]` | `email` | `type: string`, `format: email` |
| `#[Pattern(re)]` | `regex:re` | `pattern`, con los delimitadores PCRE y las banderas inocuas `D`/`u` retiradas |
| `#[Digits(i, f)]` | `numeric`, un `regex:` anclado | `type: number` + `pattern` |
| `#[AssertTrue]` / `#[AssertFalse]` | `accepted` / `declined` | `type: boolean` + `const: true` / `const: false` |
| `#[Future]` / `#[Past]` | `date`, `after:now` / `before:now` | `type: string`, `format: date-time` (la mitad `after:now` se registra, no se mapea) |
| `#[Size(min, max)]` | un objeto regla `Size` | `minLength`/`maxLength`, o `minItems`/`maxItems` cuando el tipo es un array |
| `#[UuidValue]` | un objeto regla `Uuid` | `format: uuid` + el `pattern` de UUID |
| `#[Phone]` | un objeto regla `E164` | `format: phone` + `pattern: ^\+[1-9]\d{1,14}$` |
| `#[CurrencyCode]` / `#[CountryCode]` | objetos regla `Currency` / `CountryCode` | `format: currency` + `^[A-Z]{3}$` / `format: country-code` + `^[A-Z]{2}$` |
| `#[LanguageTag]` / `#[PostalCode]` | objetos regla | `format: bcp47` / `format: postal-code`, cada uno con su patrón |
| `#[Iban]` / `#[Swift]` / `#[Bic]` | objetos regla | `format: iban` / `swift` / `bic`, **patrón retenido** |
| `#[Cusip]` / `#[Isin]` / `#[Luhn]` / `#[RoutingNumber]` | objetos regla | solo `format`; el dígito de control se registra, no se mapea |
| `#[Percentage]` | un objeto regla `Percentage` | `type: number`, `minimum: 0`, `maximum: 100` |
| `#[Money]` | un objeto regla `PositiveMoney` | `type: number`, `exclusiveMinimum: 0`, `multipleOf: 0.01` |
| `#[DecimalScale(n)]` | un objeto regla `DecimalScale` | `multipleOf` — la escala 2 se convierte en `0.01` |

Tres filas de esa tabla compensan una mirada más detenida.

**El patrón se retiene allí donde la regla normaliza primero.** `Iban` quita espacios y pasa a mayúsculas antes de comparar; `Bic`, `Swift`, `Cusip` e `Isin` pasan a mayúsculas; `Luhn` y `RoutingNumber` quitan separadores. Publicar el patrón posterior a la normalización rechazaría cargas que el servidor acepta encantado, lo cual es peor que subespecificar — así que se emite el `format` y el patrón no.

**`format` es un vocabulario abierto.** En JSON Schema 2020-12 un `format` desconocido es una anotación, no un error. IBAN, BIC, ISIN, CUSIP y E.164 no tienen nombre de formato registrado, así que se emiten unos autodescriptivos (`iban`, `bic`, …) en lugar de nada.

**La nulabilidad se escribe a la manera de 3.1.** OpenAPI 3.1 *es* JSON Schema 2020-12, que eliminó la palabra clave `nullable: true` de 3.0 en favor de una unión de tipos. Un miembro nulable es por tanto `"type": ["string", "null"]`, y un `enum` gana además un miembro `null` — ensanchar solo el tipo dejaría a `null` fallando la enumeración, y la propiedad quedaría, en la práctica, indocumentable como nula.

La única regla que decide toda la fusión es **gana quien escribe primero**, aplicada primero al tipo declarado y luego a las reglas en orden de declaración — el mismo orden en que las aplica el validador:

```php
final class MapperState
{
    public function keyword(string $keyword, mixed $value): void
    {
        if (! array_key_exists($keyword, $this->schema)) {
            $this->schema[$keyword] = $value;
        }
    }
}
```

Sembrar el tipo PHP declarado *antes* de ver ninguna regla es la razón de que `#[Min(1)] int $quantity` se quede en `type: integer` en vez de ensancharse a `number` por la cadena de regla `numeric` que emite `#[Min]` — un ensanchamiento que documentaría erróneamente `1.5` como aceptable.

Aquí está toda esa tubería sobre un DTO real. Es el propio fixture de pruebas del generador, elegido porque abarca deliberadamente cada ruta de mapeo que tiene el generador — una cadena con límite de longitud, un nulable del contrato de nulos Jakarta, un entero con límites numéricos, un decimal con escala, un enum respaldado, un DTO anidado con `#[Valid]`, un patrón PCRE y un objeto regla:

```php
final class CreateOrderRequest
{
    public function __construct(
        #[NotBlank] #[Size(max: 64)] public readonly string $reference,
        #[NotNull] #[Email] public readonly string $email,
        #[Min(1)] #[Max(999)] public readonly int $quantity,
        #[Positive] #[DecimalScale(2)] public readonly float $amount,
        public readonly Currency $currency,
        #[Valid] public readonly AddressPayload $shipTo,
        #[Pattern('/^[A-Z]{3}-\d{4}$/D')] public readonly ?string $coupon = null,
        #[UuidValue] public readonly ?string $idempotencyKey = null,
    ) {}
}
```

…y este es el componente que genera, literalmente:

```json
{
  "type": "object",
  "title": "CreateOrderRequest",
  "properties": {
    "reference": { "type": "string", "maxLength": 64, "pattern": "\\S" },
    "email": { "type": "string", "format": "email" },
    "quantity": { "type": "integer", "minimum": 1, "maximum": 999 },
    "amount": { "type": "number", "exclusiveMinimum": 0, "multipleOf": 0.01 },
    "currency": { "type": "string", "enum": ["EUR", "USD"] },
    "shipTo": { "$ref": "#/components/schemas/AddressPayload" },
    "coupon": { "type": ["string", "null"], "pattern": "^[A-Z]{3}-\\d{4}$" },
    "idempotencyKey": {
      "type": ["string", "null"],
      "format": "uuid",
      "pattern": "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$"
    }
  },
  "required": ["reference", "email", "quantity", "amount", "currency", "shipTo"]
}
```

Cada miembro de ese objeto es rastreable hasta algo de la clase de arriba: el enum vino de los propios casos del enum respaldado, el `$ref` de `#[Valid]`, las dos uniones nulables de `?string`, el `multipleOf` de `#[DecimalScale(2)]`, y `required` de las reglas de presencia — `coupon` e `idempotencyKey` están ausentes de él porque nada afirma su presencia.

Los DTOs de Lumen muestran la misma maquinaria a menor escala, y uno de ellos muestra un caso que la tabla anterior no puede: una propiedad que lleva **dos** patrones.

```json
{
  "owner_id": { "type": "string", "pattern": "\\S" },
  "currency": {
    "type": "string",
    "format": "currency",
    "allOf": [{ "pattern": "\\S" }, { "pattern": "^[A-Z]{3}$" }]
  }
}
```

`#[NotBlank] #[CurrencyCode] string $currency` produce genuinamente dos patrones — `\S` de la regla de no-blanco y `^[A-Z]{3}$` de la regla de divisa — y JSON Schema tiene exactamente una ranura `pattern` por objeto de esquema. Colapsarlos quedándose con el último descartaría en silencio la garantía de no-blanco, así que varios patrones se convierten en un `allOf` de subesquemas de un patrón cada uno. Un solo patrón sigue siendo un `pattern` liso; el `allOf` aparece únicamente cuando hace falta.

---

## Nada se descarta en silencio

Algunas restricciones no tienen equivalente alguno en JSON Schema, y unas pocas se mapean solo de forma aproximada. Descartarlas calladamente produciría un documento que promete *menos* validación de la que realiza el servidor — un cliente enviaría una carga que la especificación llama válida y recibiría un `422` de vuelta. El caso por defecto del mapeador dice qué ocurre en su lugar:

```php
final class ConstraintSchemaMapper
{
    private function applyObject(MapperState $state, ValidationRule $rule): void
    {
        switch (true) {
            // ... every recognised first-party rule object is matched above.
            default:
                // A third-party ValidationRule. Its class name is the only thing about it that is knowable
                // without executing it, so that is what the extension records.
                $state->unmapped($rule::class);
        }
    }
}
```

Todo lo no reconocido — `after:now` y `before:now`, `exists:` y `unique:`, un dígito de control Luhn o CUSIP sin más, un límite que nombra a otro campo (`gte:other_field`) en vez de a un número, y cualquier `ValidationRule` de terceros — se registra bajo `x-firefly-constraints`. También un patrón que el mapeador solo pudo aproximar: `D` y `u` se descartan por ser inocuas de verdad, pero cualquier otra bandera — `i` sobre todo, para la que ECMA-262 no tiene sintaxis en línea dentro de una cadena de patrón — no puede trasladarse, así que el patrón se emite igualmente (es la afirmación verdadera más próxima disponible) *y* la regla original se registra, de modo que un lector puede ver que el patrón publicado es más estricto que el del servidor.

Tres propiedades, tres desenlaces:

```json
{
  "deliverAfter": { "type": "string", "format": "date-time", "x-firefly-constraints": ["after:now"] },
  "slug":         { "type": "string", "pattern": "^[a-z]+$", "x-firefly-constraints": ["regex:/^[a-z]+$/i"] },
  "account":      { "type": "string", "format": "iban", "x-firefly-constraints": ["iban:checksum"] }
}
```

Las herramientas conformes ignoran las tres extensiones y ven un documento válido. Un humano, o un generador que escribas tú, puede leer la verdad completa.

---

## Respuestas: un componente de problema, y un conjunto de errores derivado

Cada operación termina en el mismo componente de error, y ese componente describe lo que LaraFly *realmente* devuelve, no lo que la RFC 9457 describe en abstracto:

```php
final class ProblemSchema
{
    public const string MEDIA_TYPE = 'application/problem+json';

    public static function response(): array
    {
        return [
            'description' => 'Error response in RFC 9457 problem+json form.',
            'content' => [self::MEDIA_TYPE => ['schema' => ['$ref' => self::REF]]],
        ];
    }
}
```

La distinción importa porque las dos formas difieren. El `ErrorResponse::toArray()` del Capítulo 4 emite `status`, `title`, `code`, `category` y `severity` incondicionalmente, luego `detail`/`type`/`instance`/`traceId`/`timestamp` solo cuando no son nulos, y luego `errors` solo cuando no está vacío. Así que `code`, `category`, `severity` y `errors` son miembros de Firefly encima de los cinco de la RFC; `type` es opcional aquí, donde la RFC le da un valor por defecto; e `instance` lleva una *ruta* de petición y no una referencia URI. Documentar la forma de la RFC en vez de esta le entregaría a cada cliente generado un decodificador que descarta en silencio los tres miembros sobre los que un llamante realmente ramifica.

Las dos enumeraciones se leen directamente de los propios enums del kernel, de modo que un caso añadido en `firefly/kernel` aparece en la especificación en la siguiente generación sin ninguna edición en `firefly/openapi`:

```json
{
  "category": {
    "type": "string",
    "enum": ["business", "validation", "security", "infrastructure",
             "external", "framework", "plugin", "internal"]
  },
  "severity": { "type": "string", "enum": ["info", "warning", "error", "critical"] }
}
```

Qué estados enumera una operación es algo **derivado, no adivinado**. Compara dos operaciones reales de Lumen. `GET /api/v1/wallets/{id}/balance` toma una variable de ruta `string` y ningún cuerpo:

```json
{
  "200": { "description": "Successful response.",
           "content": { "application/json": { "schema": { "type": "object" } } } },
  "default": { "$ref": "#/components/responses/Problem" }
}
```

`POST /api/v1/wallets/{id}/deposit` toma la misma variable de ruta más un `#[Valid] #[RequestBody] AmountRequest`:

```json
{
  "200": { "description": "Successful response.",
           "content": { "application/json": { "schema": { "type": "object" } } } },
  "400": { "$ref": "#/components/responses/Problem" },
  "422": { "$ref": "#/components/responses/Problem" },
  "default": { "$ref": "#/components/responses/Problem" }
}
```

El `400` aparece exactamente cuando la operación tiene algo que `ArgumentResolver` pueda rechazar *antes* de que corra el controlador — un cuerpo que decodificar y enlazar, una subida que validar, una query o cabecera obligatoria que el cliente puede omitir, o un parámetro no-`string` que hay que coercer desde la cadena del cable. Está deliberadamente ausente de `balance`: nada de esa petición puede fallar el enlace, porque un segmento de ruta ausente no casa con la ruta en absoluto, y un `400` documentado que el endpoint no puede producir es ruido que un cliente generado convierte en una rama de error muerta. El `422` aparece exactamente cuando algún enlace lleva `#[Valid]`, porque esa es la única forma de que `BeanValidator` corra y por tanto la única forma de que se lance la `ValidationException` del Capítulo 4. Y `default` cubre todo lo que el propio manejador pueda levantar — un `404` de una `ResourceNotFoundException`, un `409` de una `ConflictException`, un `403` de un `#[PreAuthorize]` denegado — que no puede enumerarse desde el manifiesto de rutas sin leer el cuerpo del controlador, y que de todos modos se renderiza todo a través del mismo `ProblemDetailsRenderer`.

El cuerpo de éxito sale del tipo de **retorno** declarado del método del controlador, el único sitio del framework donde se enuncia la forma de una respuesta correcta. Un `204`, o un retorno `void`/`never`, no obtiene contenido alguno, porque emitir un mapa de contenido para un estado que no lleva cuerpo es exactamente lo que un generador de clientes estricto convierte en un tipo de retorno fantasma. El habitual `array` de LaraFly degrada a `type: object` en lugar de expandirse desde un docblock `@return array{...}`: analizar PHPDoc aquí haría que el documento generado dependiera de un texto de comentario que ninguna otra parte del framework trata como vinculante.

---

## Las rutas HTML `#[Controller]` no son operaciones

El Capítulo 4 presentó `#[RestController]` junto a su hermano HTML `#[Controller]`, y solo el primero es una API JSON. El generador honra esa distinción por defecto:

```php
final class OpenApiGenerator
{
    private function excluded(RouteDescriptor $route): bool
    {
        if ($route->html && ! $this->properties->includeHtml) {
            return true;
        }

        foreach ($this->properties->excludePathPrefixes as $prefix) {
            if (str_starts_with($route->path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
```

Una ruta `#[Controller]` renderiza una página. Forma parte de la superficie HTTP de la aplicación, pero no es una operación JSON, y describirla como `application/json` haría que un generador emitiera un cliente tipado para una respuesta que es una página web — la propia página de bienvenida del framework estaba en la especificación exactamente así antes de que existiera esta regla. Pon `firefly.openapi.include-html` a `true` y la ruta se documenta igualmente, pero con honestidad: la operación se produce entonces con contenido `text/html` y un esquema `type: string`, no con un esquema JSON que sería una mentira sobre la que un generador de clientes actuaría fielmente.

La segunda mitad de ese método es el instrumento romo para todo lo demás: `firefly.openapi.exclude` es un CSV de prefijos de ruta — `'/internal,/admin'` — para rutas que son JSON pero no son la API pública de nadie.

---

## El visor: tres estilos, y solo uno de ellos llama fuera

`GET /openapi` renderiza una consola de navegador sobre el documento. Cuál de ellas la decide `firefly.openapi.viewer.style`, y la elección es una decisión de cadena de suministro disfrazada de preferencia:

| `style` | Se sirve desde | ¿Petición a un tercero en cada visita? |
|---|---|---|
| `swagger` **(por defecto)** | tu propio origen, desde el paquete de composer `swagger-api/swagger-ui` | **no** |
| `builtin` | en línea en la respuesta | **no** |
| `cdn` | `cdn.jsdelivr.net` | **sí, en cada visita** |

Un valor no reconocido cae de vuelta a `swagger` en lugar de renderizar una página en blanco — una errata en un fichero de configuración no debería costarte nada.

### Por qué el valor por defecto es el Swagger UI oficial, desde tu propio origen

Todos los visores de estantería — Swagger UI, Redoc, Elements — son aplicaciones JavaScript empaquetadas, y durante años eso dejaba a un paquete PHP exactamente dos opciones. Incrustar un bundle minificado de varios megabytes en el historial git del propio paquete, de modo que cada clon de cada proyecto dependiente lo pague para siempre y el framework quede atado a un tren de releases que no puede parchear sin publicar una versión propia. O traerlo de una CDN en cada visita, lo que es una dependencia de cadena de suministro y una cuestión de protección de datos, y lo que *no renderiza en absoluto* en los entornos aislados y de CSP estricta donde más se quiere una consola de API interna.

Hay una tercera opción, y este paquete la toma. `swagger-api/swagger-ui` publica su `dist` en Packagist bajo Apache-2.0, así que **composer** puede traerlo y fijarlo — es un `require` duro de `firefly/openapi`, de modo que los ficheros ya están en disco en `vendor/` cuando llegas por primera vez a la ruta — y `SwaggerAssetAction` sirve esos ficheros desde el propio origen de la aplicación:

```php
final class SwaggerAssetAction
{
    public function __construct(private readonly SwaggerAssets $assets) {}

    public function __invoke(string $file): SymfonyResponse
    {
        $path = $this->assets->path($file);
        $type = $this->assets->contentType($file);

        if ($path === null || $type === null) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $response = new BinaryFileResponse($path, 200, ['Content-Type' => $type]);
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setImmutable();
        $response->setAutoEtag();

        return $response;
    }
}
```

Obtienes la consola byte a byte tal y como la publica Swagger — el conjunto completo de funciones, deep linking, try-it-out, el popup de redirección OAuth2 — sin petición a CDN, sin paso de npm, y sin nada en el historial de este repositorio que un `composer update` no pueda sustituir. La cabecera de caché larga es segura precisamente porque los bytes son inmutables para una versión fijada: composer solo los cambia cuando cambia la versión fijada, y el ETag cambia con ellos.

!!! note "El path traversal se defiende con una lista blanca, no con un saneador"
    Solo siete nombres de fichero son servibles siquiera — `swagger-ui.css`, `swagger-ui-bundle.js`, `swagger-ui-standalone-preset.js`, `oauth2-redirect.html`, dos favicons e `index.css` — y cada ruta resuelta se comprueba con `realpath()` para estar dentro del directorio dist. La propia ruta restringe `{file}` a `[A-Za-z0-9._-]+`, así que ni siquiera puede *expresar* un traversal. Cotejar contra una lista fija en lugar de limpiar la entrada es la elección deliberada: una lista blanca no puede vencerse con un truco de codificación que se le escapó a un saneador. Cualquier otra cosa es un 404 `text/plain` simple — no problem+json, porque quien llama aquí es un navegador pidiendo una hoja de estilos, no un cliente de API.

El directorio dist se localiza preguntando a **los metadatos de versiones instaladas del propio Composer** por la raíz del paquete, en lugar de subir directorios desde `__DIR__`. La profundidad de `src/Web/` a `vendor/` difiere entre un paquete instalado (`vendor/firefly/openapi/src/Web`) y este monorepo (`packages/openapi/src/Web`), así que un recorrido relativo funcionaría exactamente en uno de los dos; ese recorrido se conserva solo como respaldo para un runtime cuyo autoloader no pueda responder.

Y si la distribución falta de verdad — un `vendor/` recortado, un phar, un runtime sin composer — `render()` cae de vuelta en lugar de servir una página cuyos assets dan 404:

```php
final class ViewerPage
{
    public function render(string $specUrl, string $style, string $assetBase = ''): string
    {
        return match (true) {
            $style === 'cdn' => $this->swaggerUiFromCdn($specUrl),
            // Falling back rather than rendering a broken page: `swagger` is the DEFAULT, so an
            // application that has not installed swagger-api/swagger-ui would otherwise get a console
            // referencing assets that 404. The built-in reference needs nothing and is always available.
            $style === 'swagger' && $this->assets->available() => $this->swaggerUi($specUrl, $assetBase),
            default => $this->builtIn($specUrl),
        };
    }
}
```

### Para qué sirve `builtin`

Una referencia escrita a mano y sin dependencias: un script en línea, unos cientos de bytes de CSS, un solo `fetch` a la ruta de la especificación, y una paleta que sigue a `prefers-color-scheme`. Hace las dos cosas que quien lee realmente necesita de una especificación generada y que el JSON en crudo no le da — agrupa las operaciones por etiqueta con verbos y rutas visibles de un vistazo, y **resuelve los punteros `$ref` en el cliente**, de modo que quien lee ve los miembros de un DTO y no un puntero a `#/components/schemas`. Try-it-out, flujos OAuth y ejemplos de código están deliberadamente ausentes; para eso está `swagger`.

Elígelo cuando la regla del despliegue sea *nada de JavaScript de terceros en la respuesta*, y no meramente *nada de hosts de terceros*.

!!! note "Por qué esa página es un nowdoc"
    El visor integrado incrusta una aplicación JavaScript, y un **heredoc** de PHP interpola variables. Cada `$ref`, `$schema` y `$1` de ese script se leía por tanto como una variable PHP — `$ref` se convertía calladamente en la cadena vacía, y la resolución de `$ref`, que es todo el sentido de la página, dejaba de funcionar. Un nowdoc toma el script literalmente y las dos sustituciones reales se hacen explícitamente después. Es la clase de bug que no produce ningún error en ninguna parte: la página renderiza, y sencillamente muestra punteros en lugar de esquemas.

### Lo que cuesta `cdn`

```php
// config/firefly.php
return [
    'openapi' => ['viewer' => ['style' => 'cdn']],
];
```

Cada visita carga entonces Swagger UI desde `cdn.jsdelivr.net`. La versión está fijada exactamente, y **no se declara ningún hash de Subresource Integrity** — deliberadamente: un hash que el framework no puede verificar en el momento de publicar es teatro de seguridad, y uno equivocado simplemente rompería la página.

Sopesa el intercambio con honestidad. A cambio de una petición a un tercero en cada visita, de una Content-Security-Policy que tiene que permitir ese host, y de una consola que no renderiza nada en un despliegue aislado, obtienes… el mismo Swagger UI que `swagger` ya te servía desde tu propio origen. El estilo se mantiene porque es lo que muestran la mayoría de los tutoriales, y porque algunas organizaciones prefieren genuinamente que sus bytes vengan de una caché en la que ya confían — no porque sea el mejor valor por defecto.

!!! warning "`viewer.cdn` sigue ganando sobre `viewer.style`"
    `firefly.openapi.viewer.cdn` (por defecto `false`) es la grafía booleana antigua de esta opción, de antes de que `style` existiera. Sigue **forzando** la página de CDN y anula a `style`, de modo que una aplicación que la fijó conserva el comportamiento que configuró en lugar de que una actualización del framework la mueva calladamente a otra consola. Prefiere `style` en configuración nueva; borra `cdn` cuando lo adoptes.

El visor trae la especificación desde la ruta hermana en lugar de tener el documento incrustado en la página, de modo que una especificación regenerada aparece con un simple refresco del navegador, y de modo que las dos rutas puedan exponerse de forma independiente — un despliegue puede muy bien querer el documento legible por máquina público y la consola apagada, o al revés. La URL de la especificación se resuelve a través del `UrlGenerator` en lugar de concatenarse, porque una app montada bajo un subdirectorio o detrás de `APP_URL` obtendría si no un enlace que da 404 desde cualquier página que no sea la raíz, y un visor cuya única llamada de red es incorrecta es un visor que no muestra nada en absoluto.

---

## Configuración, y asegurar la superficie

Todo lo que el documento y sus rutas necesitan vive bajo una sola clave de configuración:

```php
<?php

declare(strict_types=1);

return [
    'openapi' => [
        'enabled' => true,              // master gate: off means every route is genuinely unrouted
        'path' => '/openapi.json',      // spec route
        'viewer' => [
            'enabled' => true,
            'path' => '/openapi',       // assets are mounted under {path}/assets/{file}
            'style' => 'swagger',       // swagger (default) | builtin | cdn — see above
        ],
        'title' => 'Lumen Wallet API',
        'version' => '1.0.0',
        'description' => '',
        'servers' => ['https://api.example.test'],  // bare URLs or OpenAPI Server Objects
        'exclude' => '/internal,/admin',            // CSV of path prefixes to leave out
        'include-html' => false,                    // document #[Controller] routes as text/html
    ],
];
```

`servers` acepta las dos grafías que usa un fichero de configuración real — una lista de URLs sueltas, y la propia forma de objeto de OpenAPI con una `description` — y una entrada que no sea ninguna de las dos se descarta en lugar de emitirse, porque un Server Object sin `url` es inválido bajo el esquema 3.1 y envenenaría un documento por lo demás correcto.

Un despliegue público se asegura como cualquier otra ruta. Las reglas de `HttpSecurity` del Capítulo 10 — más adelante, en la Parte III — cubren las rutas de especificación y visor sin ningún borde de código, porque `HttpSecurityFilter` es un middleware global y corre para las rutas registradas nativamente exactamente igual que corre para tus controladores:

```php
<?php

declare(strict_types=1);

return [
    'security' => [
        'enabled' => true,
        'http' => [
            'enabled' => true,
            'rules' => [
                ['pattern' => 'openapi', 'access' => 'hasRole:DEVELOPER'],
                ['pattern' => 'openapi/*', 'access' => 'hasRole:DEVELOPER'],
                ['pattern' => 'openapi.json', 'access' => 'hasRole:DEVELOPER'],
            ],
        ],
    ],
];
```

Fíjate en los tres patrones. `openapi` a secas no casa con `openapi/assets/swagger-ui.css`, y `openapi.json` es un literal aparte — un conjunto de reglas que cubre la consola pero no sus assets produce una página autenticada cuya hoja de estilos responde 401, que es peor resultado que cualquiera de los dos extremos.

La alternativa, para un despliegue que no quiere ninguna superficie de documentación en producción, es `enabled => false` más un paso `firefly:openapi --output=` en CI.

---

## Reemplazar una pieza de la tubería

Cada colaborador del paquete — `OpenApiProperties`, `ConstraintSchemaMapper`, `DtoSchemaFactory`, `OperationFactory`, `OpenApiGenerator` y `ViewerPage` — es un `#[Bean]` tras un `#[ConditionalOnMissingBean]`, el mecanismo del Capítulo 2. Enseñarle al generador tu propia `ValidationRule` es por tanto una `#[Configuration]` corta en tu aplicación y nunca un fork:

```php
#[Configuration]
final class ApiDocsConfiguration
{
    #[Bean]
    public function constraintSchemaMapper(): ConstraintSchemaMapper
    {
        return new HouseConstraintSchemaMapper; // teaches the generator your own ValidationRules
    }
}
```

!!! note "La reflexión de aquí es real, y no está en el camino de petición"
    `DtoSchemaFactory` refleja el constructor de un DTO para conocer los tipos de sus propiedades, lo que parece una violación de la regla que el Capítulo 13 enunciará por completo: nada en el camino de petición cacheado refleja. No lo es. Esa reflexión corre cuando `firefly:openapi` genera un fichero, o ante un impacto en la ruta de la especificación — cuyo resultado el generador memoiza durante toda la vida del proceso — y nunca mientras se despacha una petición de la aplicación. Es la misma categoría de trabajo que `RouteScanner` y `ConstraintScanner`, que ambos reflejan solo en tiempo de compilación. La alternativa, enseñar a `RouteScanner` a emitir tipos por propiedad dentro de cada `RouteDescriptor`, se rechazó porque haría crecer el manifiesto de rutas compilado de *todas* las apps en beneficio de un único paquete opcional.

!!! laravel "Paridad con Laravel"
    Laravel de serie no trae soporte OpenAPI. Las respuestas habituales son un paquete de terceros gobernado por su propio dialecto de anotaciones (los bloques `@OA\` de `zircote/swagger-php`, o las clases de atributos de `vyuldashev/laravel-openapi`) o un fichero YAML mantenido a mano — ambos son una *segunda* descripción de la API, situada junto a las rutas y los FormRequests que realmente la imponen, y ambos divergen. `firefly/openapi` no tiene dialecto que aprender porque no tiene segunda descripción: lee el mismo `RouteManifest` desde el que despacha el dispatcher y el mismo `ConstraintManifest` con el que valida el validador. El análogo más cercano fuera de PHP es springdoc-openapi, y lo más parecido dentro del propio Laravel es `php artisan route:list` — exacto por la misma razón, e incapaz por la misma razón de decirte nada sobre un cuerpo de petición.

---

## Lo que aprendiste {.recap}

| Concepto | Qué hace |
|---|---|
| `OpenApiGenerator` | Ensambla un documento OpenAPI 3.1 desde `RouteManifest` + `ConstraintManifest`; memoizado por instancia, ordenado de forma determinista para que las regeneraciones diferencien limpiamente |
| `firefly:openapi` | Escribe el documento en `--output=` o en crudo a stdout, convirtiendo la especificación en un artefacto versionable que un job de CI puede diferenciar |
| `OpenApiRouteRegistrar` | Monta `/openapi.json`, `/openapi` y `/openapi/assets/{file}` nativamente desde un `BootPass` — rutas configurables que una ruta por atributo nunca habría podido tener, y sin autodocumentación |
| `OperationFactory` | Mapea cada `kind` de enlace — `path`/`query`/`header`/`file`/`body` — a su forma OpenAPI; los enlaces `service` nunca aparecen |
| `SchemaRegistry` | Un componente por DTO, alcanzado por `$ref`: sin tipos generados duplicados, y un nombre reservado cierra un ciclo recursivo de `$ref` |
| `DtoSchemaFactory` | Fusiona los tipos declarados del constructor con las restricciones compiladas; no emite `additionalProperties: false`, porque el servidor ignora las claves extra |
| `ConstraintSchemaMapper` | Mapea la lista de reglas compilada — no los atributos — a palabras clave; gana quien escribe primero, así que `#[Min(1)] int` se queda en `integer` |
| `x-firefly-constraints` | Registra lo que JSON Schema no sabe enunciar (`after:now`, un dígito de control, un PCRE con banderas, una regla de terceros) en lugar de descartarlo |
| `ProblemSchema` | La única respuesta compartida `application/problem+json`; documenta `code`/`category`/`severity`/`errors` de Firefly, con los enums leídos de los propios casos del kernel |
| Conjunto de errores derivado | `400` solo cuando algo es rechazable antes de que corra el controlador, `422` solo bajo `#[Valid]`, `default` siempre |
| `$route->html` | Las rutas HTML `#[Controller]` quedan excluidas por defecto; `firefly.openapi.include-html` las documenta como `text/html`, nunca como JSON |
| `firefly.openapi.viewer.style` | `swagger` (por defecto) \| `builtin` \| `cdn`. Solo `cdn` hace una petición a un tercero en cada visita; un valor no reconocido cae de vuelta a `swagger` |
| `SwaggerAssets` | Sirve el Swagger UI OFICIAL desde tu propio origen, desde el paquete de composer `swagger-api/swagger-ui` — siete nombres de fichero en lista blanca, cada uno comprobado con `realpath()` dentro del directorio dist |
| `ViewerPage::render()` | Cae de vuelta a `builtin` cuando falta la dist de Swagger, en lugar de renderizar una consola cuyos assets dan 404 |

---

## Ponlo en práctica {.exercises}

1. **Genera el documento de Lumen y léelo.** Ejecuta `php artisan firefly:openapi --output=openapi.json` en el sample y abre `/openapi` en un navegador. Busca `walletBalance` y confirma que no tiene respuesta `400`; luego busca `walletDeposit` y confirma que tiene tanto un `400` como un `422` — y convéncete, con las reglas de este capítulo, de por qué difieren.
2. **Convierte la especificación en una puerta de CI.** Versiona el fichero generado y añade un job que lo regenere y ejecute `git diff --exit-code` sobre él. Cambia un DTO — añade un `#[Size(max: 32)]` a `OpenWalletRequest::$owner_id` — y observa al job fallar con un diff que nombra la palabra clave de esquema exacta que cambió.
3. **Demuestra que la consola por defecto no hace ninguna petición saliente.** Abre `/openapi` en el sample con el panel de red del navegador grabando, y confirma que todas las peticiones son del mismo origen: la página, `openapi/assets/swagger-ui.css`, los dos bundles y `openapi.json`. Luego pon `firefly.openapi.viewer.style` a `cdn`, recarga, y observa aparecer `cdn.jsdelivr.net` en ese mismo panel — esa petición es toda la diferencia, y es lo que una CSP estricta o un host aislado bloquearía.
4. **Observa a una restricción caer hasta la extensión.** Añade `#[Future]` a una propiedad `string` de un DTO de petición, regenera, y encuentra el array `x-firefly-constraints` de la propiedad llevando `after:now` junto a un `format: date-time` perfectamente corriente. Luego añade `#[Pattern('/^[a-z]+$/i')]` a otra propiedad y compara: el patrón *sí* se publica, y la regla original se registra a su lado porque la bandera `i` no pudo sobrevivir a la traducción.
