<span class="eyebrow">Parte IV — Observabilidad, Pruebas y Entrega · Capítulo 11</span>

# Observabilidad: Salud, Métricas y el Actuator {.chtitle}

Al terminar este capítulo conocerás el SPI `HealthIndicator` de `firefly/actuator` y los indicadores integrados `Ping`/`DiskSpace`/`Db`, cómo `HealthEndpoint` los agrega en una única respuesta `/actuator/health` — y cómo un **grupo** de sondeo (el mecanismo que hay detrás de "liveness" y "readiness") no es más que un subconjunto de indicadores con nombre y configurado, cómo toda la superficie de gestión está **sin exponer por defecto** de modo que un endpoint olvidado falla cerrado como un 404 en lugar de una fuga de información, y el `MeterRegistry` en PHP puro de `firefly/observability`, su exportador Prometheus a prueba de locale, los buckets de histograma por medidor que convierten un resumen de Prometheus en algo que `histogram_quantile()` puede responder, los formatos de log estructurado (`json`, `ecs`, `logstash`) a los que `firefly.logging.structured.format` conmuta un canal y los cuatro ids que lleva entonces cada línea, y el truco exacto de precedencia `#[Order(500)]` — el mismo que el Capítulo 10 te mostró para la seguridad — que permite a `MeterRegistryCqrsMetrics` reemplazar el `NoOpCqrsMetrics` del bus de CQRS sin ningún cambio de código en `firefly/cqrs`. El capítulo cierra con `firefly/admin`, el panel de administración renderizado en el servidor sobre esos mismos endpoints — un **grafo de beans** dibujado que resuelve cada dependencia de constructor a través de la interfaz por la que está cableada y reporta los ciclos con los que, si no, un arranque moriría sin mensaje. Lee esos endpoints **en proceso**, de modo que renderiza páginas que la superficie JSON deliberadamente mantiene sin exponer, lo que convierte a su propia URL en toda la frontera de seguridad y a su valor por defecto (`app.debug`) en la línea más importante del paquete.

!!! note "Término nuevo: actuator"
    Un **actuator** es un endpoint de gestión que informa sobre el *proceso en ejecución en sí* — si está sano, con qué arrancó, cuán rápidas son sus peticiones — en lugar de sobre el dominio de negocio al que sirve el proceso. El término y la forma provienen ambos de Spring Boot Actuator; `firefly/actuator` es un análogo PHP de primera parte y con pocas dependencias: endpoints de framework montados directamente sobre el mismo `Router` de Illuminate que usan tus propios controladores, no un proceso de administración separado.

---

## El SPI `HealthIndicator`

Una comprobación de salud en LaraFly es cualquier `#[Component]` que implemente un método:

<!-- source: packages/actuator/src/Health/HealthIndicator.php -->
```php
interface HealthIndicator
{
    public function health(): Health;
}
```

`Health` es una lectura inmutable de estado-más-detalles, construida exclusivamente a través de cuatro fábricas con nombre:

<!-- source: packages/actuator/src/Health/Health.php -->
```php
final readonly class Health
{
    // …
    public function __construct(
        public Status $status,
        public array $details = [],
    ) {}
    // …
    public static function up(array $details = []): self
    {
        return new self(Status::Up, $details);
    }
    // …
    public static function down(array $details = []): self
    {
        return new self(Status::Down, $details);
    }
    // …
    public static function outOfService(array $details = []): self
    {
        return new self(Status::OutOfService, $details);
    }
    // …
    public static function unknown(array $details = []): self
    {
        return new self(Status::Unknown, $details);
    }
}
```

`Status` es un enum respaldado que lleva su propia ordenación por severidad y el estado HTTP al que se mapea — DOWN y OUT_OF_SERVICE se renderizan ambos como `503`, de modo que un balanceador de carga no necesita ningún caso especial para tratar cualquiera de los dos como "saca esta instancia de la rotación":

<!-- source: packages/actuator/src/Health/Status.php -->
```php
enum Status: string
{
    case Up = 'UP';
    case Down = 'DOWN';
    case OutOfService = 'OUT_OF_SERVICE';
    case Unknown = 'UNKNOWN';

    public function severity(): int
    {
        return match ($this) {
            self::Down => 3,
            self::OutOfService => 2,
            self::Up => 1,
            self::Unknown => 0,
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::Down, self::OutOfService => 503,
            default => 200,
        };
    }
}
```

---

## Los indicadores integrados: Ping, DiskSpace, Db

Tres `HealthIndicator` vienen con `firefly/actuator`, y vale la pena leer los tres de principio a fin — son cortos, y cada uno enseña una decisión de diseño diferente.

`PingHealthIndicator` es el sondeo trivial siempre-arriba, y su propio docblock nombra exactamente para qué sirve:

<!-- source: packages/actuator/src/Health/PingHealthIndicator.php -->
```php
/** The trivial liveness probe — always UP. Discovered by HealthContributorRegistrar under the name 'ping'. */
#[Component]
final class PingHealthIndicator implements HealthIndicator
{
    public function health(): Health
    {
        return Health::up();
    }
}
```

`DiskSpaceHealthIndicator` lee una ruta y un umbral de bytes desde la configuración, y se degrada con elegancia — una ruta que no se puede leer se reporta como DOWN con un detalle explicativo en lugar de lanzar una excepción:

<!-- source: packages/actuator/src/Health/DiskSpaceHealthIndicator.php -->
```php
#[Component]
final class DiskSpaceHealthIndicator implements HealthIndicator
{
    public function __construct(private readonly Config $config) {}

    public function health(): Health
    {
        $path = $this->config->string('firefly.management.endpoint.health.diskspace.path', getcwd() ?: '.');
        $threshold = $this->config->int('firefly.management.endpoint.health.diskspace.threshold', 10_485_760);

        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false) {
            return Health::down(['path' => $path, 'error' => 'unable to determine disk space']);
        }

        $details = ['total' => (int) $total, 'free' => (int) $free, 'threshold' => $threshold, 'path' => $path];

        return $free >= $threshold ? Health::up($details) : Health::down($details);
    }
}
```

`DbHealthIndicator` está **activo por defecto** — `matchIfMissing: true` en su `#[ConditionalOnProperty]`, igual que la autoconfiguración `DataSourceHealthIndicator` de Spring Boot. Antes era opcional, y el cambio merece entenderse, porque "activo por defecto" no es lo que evita que un proyecto sin base de datos vea un `DOWN` sorpresa. Lo evita una segunda interfaz:

<!-- source: packages/actuator/src/Health/DbHealthIndicator.php -->
```php
#[Component]
#[ConditionalOnProperty(name: 'firefly.management.endpoint.health.db.enabled', havingValue: 'true', matchIfMissing: true)]
final class DbHealthIndicator implements ConditionalHealthIndicator
{
    // …
    public function available(): bool
    {
        $default = $this->config->get('database.default');
        if (! is_string($default) || $default === '') {
            return false;
        }

        $driver = $this->config->get("database.connections.{$default}.driver");

        return is_string($driver) && $driver !== '';
    }

    public function health(): Health
    {
        try {
            $connection = $this->connections->connection();
            $connection->selectOne('select 1 as ok');

            $driverName = method_exists($connection, 'getDriverName') ? $connection->getDriverName() : null;
            $driver = is_string($driverName) ? $driverName : 'unknown';

            return Health::up(['database' => $driver]);
        } catch (Throwable $e) {
            return Health::down(['error' => $e::class.': '.$e->getMessage()]);
        }
    }
}
```

`ConditionalHealthIndicator` añade exactamente un método al SPI, y `available()` es la respuesta de este framework a `@ConditionalOnBean(DataSource)` de Spring. `HealthEndpoint` lo pregunta antes de ejecutar la comprobación, y un indicador que responde `false` no aporta **ningún componente**: ni `UNKNOWN`, ni `DOWN`, simplemente ninguna clave `db` en la respuesta. Aquí la respuesta se lee de la propia configuración de Laravel: `database.default` tiene que nombrar una conexión, y la entrada de esa conexión tiene que declarar un `driver`. Así, una aplicación que realmente no tiene base de datos obtiene un documento de salud que no la menciona, que es la respuesta honesta; una que *sí* la tiene y aun así quiere quitar la comprobación pone `firefly.management.endpoint.health.db.enabled=false` y el bean nunca se registra.

Esa es la distinción que conviene llevarse: la **propiedad** decide si el indicador existe, y `available()` decide si un indicador que existe tiene algo que decir. Cualquier `HealthIndicator` que escribas puede implementar la misma interfaz y recibir el mismo trato.

Fíjate en que `DbHealthIndicator` depende directamente de la propia `ConnectionResolverInterface` de Illuminate en lugar de nada de `firefly/data` — no hay ninguna arista `Actuator → Data` en `deptrac.yaml` en absoluto, de modo que una comprobación de salud de BD no le cuesta a `firefly/actuator` ninguna dependencia nueva. Y cada indicador aquí sigue la misma forma a prueba de fallos: una consulta, una comparación o una llamada al sistema de archivos que podría lanzar una excepción siempre se captura y se convierte en `Health::down()` con un detalle que explica por qué — nunca una excepción sin manejar, nunca un `500` donde corresponde un `503`.

---

## Agregando salud: `HealthEndpoint` y los grupos de sondeo

`HealthEndpoint` es en sí mismo un `#[Component]` (no un simple interno del framework — tiene que ser descubrible como cualquier otro bean) que lee cada indicador registrado, ejecuta cada uno a prueba de fallos, y pliega los resultados hacia abajo hasta el único estado más severo:

<!-- source: packages/actuator/src/Health/HealthEndpoint.php -->
```php
#[Component]
final class HealthEndpoint implements ActuatorEndpoint
{
    public function __construct(
        private readonly HealthContributorRegistry $registry,
        private readonly StatusAggregator $aggregator,
        private readonly Config $config,
    ) {}

    public function endpointId(): string
    {
        return 'health';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function handle(EndpointRequest $request): ?EndpointResponse
    {
        $indicators = $this->registry->all();

        if ($request->subPath !== []) {
            $members = $this->group($request->subPath[0]);
            if ($members === null) {
                return null; // unknown group → 404
            }
            $indicators = array_intersect_key($indicators, array_flip($members));
        }

        $components = [];
        $statuses = [];
        foreach ($indicators as $name => $indicator) {
            $health = $this->readFailSafe($indicator);
            $statuses[] = $health->status;
            $components[$name] = ['status' => $health->status->value, 'details' => $health->details];
        }

        $status = $this->aggregator->aggregate($statuses);
        $body = ['status' => $status->value];

        if ($this->showDetails()) {
            $body['components'] = $components;
        }

        return EndpointResponse::json($body, $status->httpStatus());
    }

    private function readFailSafe(HealthIndicator $indicator): Health
    {
        try {
            return $indicator->health();
        } catch (Throwable $e) {
            return Health::down(['error' => $e::class.': '.$e->getMessage()]);
        }
    }
    // …
    private function group(string $name): ?array
    {
        $key = "firefly.management.endpoint.health.group.{$name}.include";
        if (! $this->config->has($key)) {
            return null;
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $this->config->string($key))),
            static fn (string $segment): bool => $segment !== '',
        ));
    }

    private function showDetails(): bool
    {
        return $this->config->string('firefly.management.endpoint.health.show-details', 'never') === 'always';
    }
}
```

Vale la pena detenerse en dos cosas. Primero, sin ninguna ruta de petición, todo indicador registrado cuenta y `StatusAggregator` pliega un conjunto vacío a `UP` (vacío significa que no hay nada por lo que estar insano) y, en caso contrario, elige el único `Status` más severo por `severity()` — un único indicador `DOWN` basta para tumbar todo el agregado, informen lo que informen los demás. Segundo, **no existe ninguna clase de endpoint "liveness" o "readiness" separada en ninguna parte del código fuente** — `/actuator/health/{group}` es un único mecanismo genérico, y "liveness"/"readiness" son simplemente *nombres convencionales* que configuras, no una característica con forma de Kubernetes cableada a fuego:

<!-- illustrative: the deployment's own config/firefly.php; which probes a cluster wires and which endpoints it exposes is the application's decision -->
```php
<?php

declare(strict_types=1);

return [
    'management' => [
        'endpoint' => [
            'health' => [
                'group' => [
                    'liveness' => ['include' => 'ping'],
                    'readiness' => ['include' => 'ping,db'],
                ],
            ],
        ],
    ],
];
```

Configurado de esta manera, `GET /actuator/health/liveness` agrega solo `ping` (de modo que un proceso sano-pero-momentáneamente-sin-base-de-datos aún se reporta vivo) y `GET /actuator/health/readiness` incorpora también `db` — pero el mecanismo subyacente es la misma búsqueda `group()` exacta y los mismos tipos `Health`/`Status` exactos que el resto de esta sección ya te mostró. Un nombre de grupo que nadie configuró devuelve `null` desde `group()`, que `handle()` convierte en un `404` simple, no en un `200` con un cuerpo vacío.

`show-details` (`never`/`when-authorized`/`always`, por defecto `never`) rige si la respuesta incluye el mapa `details` por componente en absoluto — con `never`, un llamante no autenticado ve solo el `status` agregado, nunca *qué* indicador falló ni por qué. `when-authorized` es un valor de configuración real y aceptado, pero — como `firefly/actuator` no tiene ninguna dependencia de código de `firefly/security` en absoluto — se degrada al mismo comportamiento que `never`; una política de detalles-solo-cuando-autenticado es algo que construyes tú mismo delante del endpoint, no algo que el valor active.

---

## Seguro por defecto: exposición y el 404

El hecho operativo más importante sobre el actuator es este: **la mayoría de los endpoints son inalcanzables hasta que digas lo contrario.** `ExposureModel` es todo el mecanismo, y es lo bastante corto como para leerlo entero:

<!-- source: packages/actuator/src/Endpoint/ExposureModel.php -->
```php
final readonly class ExposureModel
{
    // …
    public function __construct(
        private array $include,
        private array $exclude,
        public string $basePath,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $include = self::csv($config->string('firefly.management.endpoints.web.exposure.include', 'health,info'));
        $exclude = self::csv($config->string('firefly.management.endpoints.web.exposure.exclude', ''));
        $base = trim($config->string('firefly.management.endpoints.web.base-path', '/actuator'), '/');

        return new self($include, $exclude, $base === '' ? 'actuator' : $base);
    }
    // …
    public function isExposed(string $id): bool
    {
            // …
            return false;
        }
    // …
    }
    // …
    }
}
```

El `include` por defecto es `"health,info"` — cualquier otro id de endpoint (`env`, `beans`, `conditions`, `mappings`, `loggers`, `scheduledtasks`, y las `metrics`/`prometheus` de observabilidad una vez instalado ese paquete) **no está expuesto** hasta que lo añadas explícitamente, y `ActuatorDispatchAction` renderiza un id no expuesto o desconocido como un `404` simple a través del mismo `ProblemDetailsRenderer` que presentó el Capítulo 4 — nunca un error de framework en bruto, y nunca un `200` silencioso con un cuerpo que un llamante no autenticado no debería ver. `.exclude` siempre gana sobre `.include`, de modo que `include: '*'` más una lista corta de `exclude` es una política legítima de "expón todo excepto…".

`/actuator/env` superpone una segunda red de seguridad, independiente, encima de la exposición: incluso una vez expuesta, cualquier clave cuyo nombre parezca una credencial se enmascara antes de construir la respuesta. El endpoint en sí son cuatro líneas, porque la regla vive en una única clase compartida:

<!-- source: packages/actuator/src/Introspection/EnvEndpoint.php -->
```php
public function handle(EndpointRequest $request): EndpointResponse
{
    /** @var array<string, mixed> $firefly */
    $firefly = (array) $this->config->get('firefly', []);

    return EndpointResponse::json(['firefly' => SensitiveValueMasker::mask($firefly)]);
}
```

<!-- source: packages/actuator/src/Introspection/SensitiveValueMasker.php -->
```php
final class SensitiveValueMasker
{
    public const string MASK = '******';

    private const string SENSITIVE = '/password|secret|token|key|credential|passwd|authorization|headers/i';
    // …
    public static function mask(array $values): array
    {
        $masked = [];
        foreach ($values as $key => $value) {
            if (self::isSensitive($key)) {
                $masked[$key] = self::MASK;

                continue;
            }

            $masked[$key] = is_array($value) ? self::mask($value) : $value;
        }

        return $masked;
    }
```

Que la regla viva en una clase propia en lugar de dentro del endpoint no es pulcritud. `/configprops` necesita la *misma* regla, y una segunda copia es como se pudre una regla de enmascarado: las dos listas divergen en la siguiente palabra que a alguien se le ocurra añadir, y el endpoint que se perdió la adición filtra.

El orden dentro de `mask()` es la parte que merece estudiarse, porque es un arreglo de fallo que puedes leer. La regla, tal como se escribió primero, probaba la clave **solo** en la rama donde el valor era un escalar — una clave con valor de array se recorría hacia dentro y cada hoja se juzgaba luego por su propia clave. Así que `firefly.security.jwt.keys => ['active' => 'PRIVATE…', 'previous' => '…']` renderizaba las dos claves privadas enteras: `keys` coincidía con el patrón pero era un array, y `active` y `previous` no coincidían con nada. Toda forma real de un secreto — un llavero, un par de credenciales, un mapa de tokens por inquilino — es exactamente esa forma, así que el bypass cubría justo los casos que más importaban. Aquí la **clave decide primero**, y una clave sensible enmascara todo su subárbol sea cual sea el tipo del valor.

Un array sensible se enmascara al escalar `******` y no a un array de la misma forma lleno de máscaras, y eso también es deliberado: la forma de un secreto es información en sí misma — cuántas claves hay en el llavero, qué inquilinos tienen token — y un llamante que no puede ver los valores tampoco tiene por qué contarlos.

`authorization` y `headers` están en la lista porque el propio framework introdujo una clave que lleva una credencial: `firefly.observability.tracing.otlp.headers` documenta `authorization=Bearer …` como su contenido previsto, y ninguna de las seis palabras originales aparece en `headers`. Una bolsa de cabeceras es donde viaja la credencial de un cliente saliente, se llame como se llame la cabecera concreta — que es exactamente por qué debe decidir la clave de la **bolsa** y no las hojas.

Más allá de la exposición y el enmascaramiento, el `HttpSecurity` de `firefly/security` (Capítulo 10) es lo que realmente asegura la superficie para el tráfico real, y no necesita **ningún** cambio de código para hacerlo — `HttpSecurityFilter` es un middleware global, de modo que se ejecuta para las propias rutas registradas directamente por el actuator exactamente igual que se ejecuta para tus controladores:

<!-- illustrative: the deployment's own config/firefly.php; the URL rules that protect an actuator surface belong to the application -->
```php
<?php

declare(strict_types=1);

return [
    'security' => [
        'enabled' => true,
        'http' => [
            'enabled' => true,
            'rules' => [
                ['pattern' => 'actuator/health', 'access' => 'permitAll'],
                ['pattern' => 'actuator/info', 'access' => 'permitAll'],
                ['pattern' => 'actuator/*', 'access' => 'hasRole:ACTUATOR'],
            ],
        ],
    ],
];
```

El propio `composer.json` de `firefly/actuator` no tiene ninguna dependencia de `firefly/security` en absoluto — asegurar el actuator de esta manera es **pura configuración**, apoyándose en el mismo DSL de URL de denegar-por-defecto que el Capítulo 10 ya te enseñó, sin ningún mecanismo nuevo que aprender.

!!! warning "Cualquier otro endpoint de gestión es un endpoint independiente, no una sub-clave de `/info`"
    `/actuator/info` genuinamente tiene exactamente tres fragmentos — `runtime` (de `RuntimeInfoContributor`, registrado por defecto, que es la razón por la que una aplicación recién generada ya responde algo: versión/SAPI/OPcache de PHP, versión de Laravel, versión de LaraFly, memoria actual y pico; desactívalo con `firefly.management.info.runtime.enabled = false`), `app` (de `AppInfoContributor`, leído desde `firefly.management.info.app.*`) y `build` (de `BuildInfoContributor`, que lee un archivo JSON en `firefly.management.info.build.path`). `env`, `beans`, `conditions`, `mappings`, `loggers`, `scheduledtasks`, `configprops` y `caches` son cada uno su **propio** `ActuatorEndpoint`, montado en su propio `/actuator/{id}` — no anidado bajo `/info`. `firefly:about` (Capítulo 13) renderiza varios de estos juntos en el terminal, lo cual es una comodidad de ese único comando, no una prueba de que compartan una ruta.

---

## El índice HAL y el contrato de endpoint

Todo endpoint de framework — health, info, metrics, o el tuyo propio — implementa el mismo contrato de tres métodos:

<!-- source: packages/actuator/src/Endpoint/ActuatorEndpoint.php -->
```php
interface ActuatorEndpoint
{
    /** The stable id under the base path, e.g. 'health' → /actuator/health. */
    public function endpointId(): string;

    /** Per-endpoint kill switch; ActuatorDispatchAction also honours firefly.management.endpoint.{id}.enabled. */
    public function enabled(): bool;

    public function handle(EndpointRequest $request): ?EndpointResponse;
}
```

`ActuatorRouteRegistrar`, un `BootPass` en la fase `WiringPasses`, descubre cada `#[Component]` que implementa esta interfaz, resuelve cada uno exactamente una vez, y monta exactamente **dos** rutas nativas de Illuminate — un índice `GET {base}` y un despachador comodín `GET|POST {base}/{path}` — bajo la ruta base configurada, de modo que nada del mecanismo colisiona con las rutas de tu propia aplicación. El índice en sí, `ActuatorIndexAction`, renderiza un mapa `_links` de estilo HAL filtrado a todo lo que esté a la vez habilitado **y** expuesto:

<!-- source: packages/actuator/src/Web/ActuatorIndexAction.php -->
```php
final class ActuatorIndexAction
{
    public function __construct(
        private readonly ActuatorRegistry $registry,
        private readonly ExposureModel $exposure,
        private readonly Config $config,
    // …
    ) {}

    public function __invoke(Request $request): Response
    {
        // …
        $links = ['self' => ['href' => $base]];
        foreach ($this->registry->all() as $id => $endpoint) {
            if (! $endpoint->enabled() || ! $this->config->bool("firefly.management.endpoint.{$id}.enabled", true) || ! $this->exposure->isExposed($id)) {
                continue;
            }
            $links[$id] = ['href' => $base.'/'.$id];
        }

        return new Response(
            (string) json_encode(['_links' => $links], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            200,
            ['Content-Type' => 'application/json'],
        );
    }
}
```

`GET /actuator` en una instalación por defecto por tanto lista solo `self`, `health` e `info` — exactamente los dos ids que `include` expone por defecto — y crece solo hasta donde tu propia configuración `.include` lo permita.

---

## Métricas: `MeterRegistry`, `MetricsRecorder`, y el registro idempotente

`firefly/observability` es un segundo paquete opcional, independiente, superpuesto sobre el actuator — trae su propio `MeterRegistry` (la fábrica de cara a la lectura que consulta un consumidor) y un `MetricsRecorder` más estrecho (el puerto de cara a la escritura del que realmente depende la instrumentación, de modo que un filtro o un registrador de métricas nunca necesita la API completa del registro):

<!-- source: packages/observability/src/Metrics/MeterRegistry.php -->
```php
interface MeterRegistry
{
    /** @param array<string, string> $tags */
    public function counter(string $name, array $tags = []): Counter;

    /** @param array<string, string> $tags */
    public function timer(string $name, array $tags = []): Timer;

    /**
     * @param  array<string, string>  $tags
     * @param  callable(): float  $supplier
     */
    public function gauge(string $name, array $tags, callable $supplier): Gauge;

    /** @return list<Meter> */
    public function meters(): array;
}
```

<!-- source: packages/observability/src/Metrics/MetricsRecorder.php -->
```php
interface MetricsRecorder
{
    /** @param array<string, string> $tags */
    public function increment(string $name, array $tags = [], float $amount = 1.0): void;

    /** @param array<string, string> $tags */
    public function record(string $name, array $tags = [], float $seconds = 0.0): void;

    /** @param array<string, string> $tags */
    public function setGauge(string $name, array $tags, float $value): void;
}
```

`SimpleMeterRegistry` implementa ambos puertos como un almacén en memoria, de primera parte y en PHP puro — sin `ext-prometheus`, sin SDK de OpenTelemetry. El registro es idempotente, indexado por `type|name|sorted-tags`, de modo que llamar a `counter('cqrs_commands_seconds', [...])` dos veces con el mismo nombre y conjunto de tags devuelve la mismísima instancia de `Counter` ambas veces. También falla ruidosamente, a propósito, si intentas registrar un nombre de métrica bajo dos tipos diferentes:

<!-- source: packages/observability/src/Metrics/SimpleMeterRegistry.php -->
```php
final class SimpleMeterRegistry implements MeterRegistry, MetricsRecorder
{
    // …
    private function guardType(string $name, MeterType $type): void
    {
        $existing = $this->namesToTypes[$name] ?? null;
        if ($existing !== null && $existing !== $type) {
            throw new InvalidArgumentException(
                "Metric '{$name}' already registered as {$existing->value}; cannot re-register as {$type->value}."
            );
        }
        $this->namesToTypes[$name] = $type;
    }
// …
}
```

Esto no es pedantería: el propio formato de texto de Prometheus limita exactamente una declaración `# TYPE` por nombre de métrica, de modo que un nombre registrado como counter y como gauge en algún lugar de tu código haría que `PrometheusTextFormat` emitiera dos líneas `# TYPE` en conflicto para el mismo nombre — una exposición inválida que un recolector rechazaría. `SimpleMeterRegistry` convierte ese error en una `InvalidArgumentException` inmediata en el sitio de llamada responsable, en lugar de en un fallo de recolección descubierto más tarde en producción.

---

## Exposición: el exportador Prometheus a prueba de locale

`PrometheusTextFormat` renderiza cada medidor registrado como texto de formato 0.0.4 en `/actuator/prometheus`. La parte interesante es un método privado de seis líneas que la mayoría de los equipos hacen mal la primera vez que escriben uno:

<!-- source: packages/observability/src/Prometheus/PrometheusTextFormat.php -->
```php
final class PrometheusTextFormat
{
    // …
    private function value(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }
        if ($value === floor($value) && abs($value) < 1.0e15) {
            return (string) (int) $value;
        }

        // number_format() (unlike sprintf('%f')) is locale-INDEPENDENT here: the decimal point and thousands
        // separator are passed explicitly as arguments, so LC_NUMERIC (e.g. a comma-decimal locale such as
        // de_DE) cannot leak a ',' into the exposition and produce unscrapeable Prometheus output.
        return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
    }
// …
}
```

`sprintf('%f', $value)` es la forma instintiva de formatear un float en PHP, y es *dependiente del locale*: en un host cuyo `LC_NUMERIC` esté configurado a un locale de coma-decimal como `de_DE`, `sprintf('%f', 1.5)` renderiza `"1,500000"` — una coma que la propia gramática del formato de texto de Prometheus no permite dentro de un valor de muestra, produciendo silenciosamente una exposición que un recolector no puede parsear. `number_format($value, 10, '.', '')` toma el punto decimal y el separador de miles como **argumentos explícitos**, de modo que el locale ambiente del proceso nunca puede filtrarse en la salida — la misma clase de pregunta "qué capa controla el formateo" a la que este libro sigue volviendo, aquí respondida una sola vez, en una función, en lugar de en cada sitio de llamada que casualmente imprima un float.

`/actuator/metrics` (`MetricsEndpoint`) expone el mismo registro como JSON con sabor a Micrometer en su lugar — sin subruta lista cada nombre registrado; un nombre concreto se resuelve a sus medidas y tags disponibles, o a un `404` simple si el registro nunca lo ha visto.

### Buckets de histograma: convertir un resumen en algo de lo que puedas sacar un cuantil

Un `Timer` se expone por defecto como un **summary** de Prometheus — `_count` y `_sum`, y nada más. Eso basta para una media y es inútil para un p99, porque una latencia media es el único número que esconde todas las malas experiencias que el servicio entregó. `histogram_quantile()` necesita buckets, y los buckets son una decisión por medidor:

<!-- source: packages/observability/src/Metrics/DistributionStatisticConfig.php -->
```php
public const string BUCKETS_KEY = 'firefly.observability.metrics.distribution.buckets';

public const string PER_METER_KEY = 'firefly.observability.metrics.distribution.per-meter';
// …
/** @return list<float> ascending upper bounds in seconds; [] when the meter is a summary */
public function bucketsFor(string $meterName): array
{
    return $this->perMeter[$meterName] ?? $this->buckets;
}
```

`firefly.observability.metrics.distribution.buckets` es la lista global de cotas superiores en segundos; `distribution.per-meter.<nombre>` la sobrescribe para un medidor, y una lista por medidor **vacía** devuelve ese medidor a ser un summary. Un temporizador que tiene buckets se expone como un `histogram` de Prometheus — `_bucket{le=…}`, `_count`, `_sum` — y uno que no los tiene sigue siendo un summary.

En ese método de seis líneas se ven dos decisiones de diseño. Las cotas se indexan por **nombre** de medidor, nunca por conjunto de etiquetas, porque una familia de Prometheus tiene exactamente un tipo y una disposición de buckets; una disposición por etiqueta produciría una familia a la que ningún recolector podría encontrar sentido. Y los registros piden los buckets cuando un temporizador se **crea**, no en cada `record()` — la disposición queda fijada para la vida del medidor.

El valor por defecto es **sin buckets**, y no porque los buckets sean peligrosos. Convertir un summary en un histogram cambia la línea `# TYPE` de un scrape y añade una serie por cota, y una actualización no tiene por qué hacerle eso a un panel en marcha sin que se lo pidan. La lista por la que empezar es la que distribuyen los clientes de Prometheus:

```
[0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10]
```

Cada cota se valida en tiempo de configuración: una entrada no numérica, un cero, un negativo, un infinito o un NaN es una `ConfigurationException` que nombra la clave. `+Inf` está implícito y no debe listarse.

---

## Auto-instrumentación: `MetricsFilter` y la cardinalidad acotada

Una vez que `firefly/observability` está instalado y habilitado, cada petición HTTP se cronometra automáticamente mediante `MetricsFilter`, un `WebFilter` `#[Component]` descubierto por la cadena de filtros de `firefly/web` sin ningún cableado tuyo:

<!-- source: packages/observability/src/Web/MetricsFilter.php -->
```php
#[Component]
#[Order(-100)]
#[ConditionalOnProperty(name: 'firefly.observability.metrics.enabled', havingValue: 'true', matchIfMissing: true)]
#[Lazy]
final class MetricsFilter extends OncePerRequestFilter
{
    // …
    private const string UNMATCHED_ROUTE_URI = 'UNKNOWN';

    public function __construct(private readonly MetricsRecorder $recorder) {}

    protected function doFilter(Request $request, Closure $next): mixed
    {
        $start = microtime(true);

        try {
            $response = $next($request);
            $status = $response instanceof Response ? $response->getStatusCode() : 200;
            $this->record($request, $start, $status, $this->outcome($status), 'none');

            return $response;
        } catch (Throwable $e) {
            $this->record($request, $start, 500, 'SERVER_ERROR', $e::class);

            throw $e;
        }
    }

    private function record(Request $request, float $start, int $status, string $outcome, string $exception): void
    {
        $this->recorder->record('http_server_requests_seconds', [
            'method' => $request->getMethod(),
            'uri' => $request->route() !== null ? '/'.ltrim((string) $request->route()->uri(), '/') : self::UNMATCHED_ROUTE_URI,
            'status' => (string) $status,
            'outcome' => $outcome,
        // …
        ], microtime(true) - $start);
    }
// …
}
```

Dos detalles hacen esto seguro para producción en lugar de meramente cómodo. `#[Order(-100)]` lo pone en la posición más externa entre los filtros descubiertos, de modo que cronometra la cadena interna *entera* — seguridad, validación, tu controlador — no solo una porción de ella. Y el tag `uri` es siempre la **plantilla** de la ruta coincidente (`/orders/{id}`), nunca la ruta de petición en bruto — una petición sin coincidencia (cualquier `404`) se etiqueta con el centinela fijo `'UNKNOWN'` en lugar de con la ruta controlada por el atacante o el rastreador. Etiquetar por la ruta en bruto permitiría a cualquiera generar un número ilimitado de combinaciones distintas de etiquetas de métrica simplemente golpeando URLs inventadas; bajo Octane, donde el registro es un singleton de por vida del proceso en lugar de uno nuevo por petición, eso no es una verruga cosmética — es un vector de crecimiento de memoria ilimitado. Ante una petición que lanza una excepción, el filtro registra el resultado y **relanza** en lugar de tragársela, de modo que `ProblemDetailsRenderer` todavía puede renderizar el error exactamente como lo haría sin el filtro instalado.

`MeterBindingsPass`, un `BootPass` compañero, registra un puñado de gauges basados en pull de la misma manera: `process_resident_memory_bytes`/`php_memory_peak_bytes` para el proceso en ejecución, y un gauge `resilience_circuit_breaker_state{name}` por cada circuit breaker configurado (`closed=0`, `open=1`, `half_open=2`) — cada uno muestreado *en vivo* en el momento de la recolección mediante una clausura, de modo que una petición `/actuator/prometheus` fresca siempre refleja el estado actual del breaker en lugar de una instantánea del arranque.

---

## La costura de métricas de CQRS: `#[Order(500)]`, una vez más

El Capítulo 7 te dejó con `NoOpCqrsMetrics` enlazado detrás de `#[ConditionalOnMissingBean(CqrsMetrics::class)]`, y un docblock prometiendo que un registrador real caería en su lugar más tarde. `firefly/observability` es ese reemplazo, y el mecanismo ganador es *exactamente* el truco de precedencia que el Capítulo 10 usó para `SecurityCommandAuthorizer`:

<!-- source: packages/observability/src/ObservabilityAutoConfiguration.php -->
```php
#[Configuration]
#[Order(500)]
final class ObservabilityAutoConfiguration
{
    // …
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.observability.metrics.enabled', havingValue: 'true', matchIfMissing: true)]
    #[ConditionalOnMissingBean(MeterRegistry::class)]
    public function meterRegistry(Container $container, Config $config): MeterRegistry
    {
        $distribution = DistributionStatisticConfig::fromConfig($config);

        $store = $config->string('firefly.observability.metrics.store', '');
        if ($store === '' || ! $container->bound('cache')) {
            return new SimpleMeterRegistry($distribution);
        }

        /** @var Factory $factory */
        $factory = $container->make('cache');
        $ttl = $config->int('firefly.observability.metrics.ttl', 0);

        return new CacheMeterRegistry($factory->store($store), 'firefly:metrics:', $ttl > 0 ? $ttl : null, $distribution);
    }
    // …
    #[Bean]
    #[ConditionalOnMissingBean(CqrsMetrics::class)]
    #[ConditionalOnProperty(name: 'firefly.observability.metrics.enabled', havingValue: 'true', matchIfMissing: true)]
    public function cqrsMetrics(MetricsRecorder $recorder): CqrsMetrics
    {
        return new MeterRegistryCqrsMetrics($recorder);
    }
// …
}
```

`meterRegistry()` se muestra a su lado porque los dos beans responden preguntas distintas con la misma maquinaria. Ese elige el registro: `SimpleMeterRegistry` por defecto, en memoria de proceso; `CacheMeterRegistry` sobre un almacén de caché con nombre cuando `firefly.observability.metrics.store` nombra uno, de modo que los contadores se acumulan entre trabajadores de PHP-FPM en vez de que cada scrape vea solo su propia petición. A ambos se les entrega el mismo `DistributionStatisticConfig`, así que los buckets de histograma de un temporizador no cambian cuando cambia el almacén de respaldo.

`cqrsMetrics()` es el de esta sección, y `ObservabilityAutoConfiguration` es `#[Order(500)]`, estrictamente por debajo del `#[Order(1000)]` de `CqrsAutoConfiguration`. La pasada incremental de condiciones evalúa primero el `#[Order]` más bajo y registra los supervivientes inmediatamente, de modo que el bean `cqrsMetrics()` de esta clase se registra **primero**; para cuando `CqrsAutoConfiguration` evalúa su propio `#[ConditionalOnMissingBean(CqrsMetrics::class)]`, ya hay un bean enlazado, y el `NoOp` por defecto se retira. Ninguna línea dentro de `firefly/cqrs` cambia — la victoria es puro ordenamiento de auto-configuración, la misma forma que ya has visto dos veces.

`MeterRegistryCqrsMetrics` en sí es el registrador concreto que ese ordenamiento instala — una implementación pequeña y directa del puerto `CqrsMetrics`, que registra cada comando o consulta como un timer etiquetado por tipo de mensaje y resultado:

<!-- source: packages/observability/src/Cqrs/MeterRegistryCqrsMetrics.php -->
```php
final class MeterRegistryCqrsMetrics implements CqrsMetrics
{
    public function __construct(private readonly MetricsRecorder $recorder) {}

    public function recordCommandSuccess(object $command, float $seconds): void
    {
        $this->recorder->record('cqrs_commands_seconds', ['type' => $this->type($command), 'outcome' => 'success'], $seconds);
    }

    public function recordCommandFailure(object $command, float $seconds): void
    {
        $this->recorder->record('cqrs_commands_seconds', ['type' => $this->type($command), 'outcome' => 'failure'], $seconds);
    }

    public function recordQuerySuccess(object $query, float $seconds): void
    {
        $this->recorder->record('cqrs_queries_seconds', ['type' => $this->type($query), 'outcome' => 'success'], $seconds);
    }

    public function recordQueryFailure(object $query, float $seconds): void
    {
        $this->recorder->record('cqrs_queries_seconds', ['type' => $this->type($query), 'outcome' => 'failure'], $seconds);
    }

    private function type(object $message): string
    {
        $class = $message::class;

        return ($pos = strrpos($class, '\\')) !== false ? substr($class, $pos + 1) : $class;
    }
}
```

Cada bean de observabilidad se apoya en la **misma** propiedad, `firefly.observability.metrics.enabled` — no en la presencia de `MeterRegistry`. Es una elección deliberada, no un descuido: un `#[ConditionalOnBean(MeterRegistry::class)]` en `cqrsMetrics()` o en `MetricsFilter` se evaluaría *antes* de que el propio `#[Order(500)]` de `ObservabilityAutoConfiguration` haya registrado `MeterRegistry` en absoluto, descartando erróneamente a todo consumidor aguas abajo incluso con las métricas genuinamente habilitadas — una trampa de ordenamiento, no un error de lógica. Apoyar todo en una sola bandera en su lugar hace que la supervivencia sea independiente del orden: cambia `firefly.observability.metrics.enabled` y el registro, el registrador de CQRS, el filtro y ambos endpoints sobreviven todos — o se retiran todos — juntos.

Por último, un puerto `Tracer` remata el paquete — y ya no es el marcador de posición con el que empezó. La interfaz son tres métodos: `startSpan(string $name, SpanKind $kind = SpanKind::Internal, array $attributes = [], ?SpanContext $parent = null): Span`, `currentSpan(): ?Span`, y la comodidad `trace(string $name, callable $callback, SpanKind $kind = SpanKind::Internal, array $attributes = []): mixed`, que abre un span, ejecuta la clausura, registra un lanzable como estado `ERROR` más un evento `exception`, relanza y cierra. `NoOpTracer` sigue siendo el valor por defecto entregado — spans que no graban, un `SpanContext` inválido, `currentSpan()` de `null` — y un adaptador de OpenTelemetry se enlaza ahora por encima de él en `#[Order(400)]`, por delante del `#[Order(500)]` de `ObservabilityAutoConfiguration`, siempre que el SDK esté instalado y `firefly.observability.tracing.enabled` esté activo. La idéntica forma "puerto ahora, adaptador después" que acabas de ver para `CqrsMetrics`; lo que cambió es que el adaptador llegó, y con él la instrumentación a ambos lados.

---

## Trazado: un solo `traceparent`, cinco fronteras

Lo que compra el puerto es un identificador que sobrevive a todas las fronteras que cruza una petición. `W3CTraceContextPropagator` habla [W3C Trace Context](https://www.w3.org/TR/trace-context/) sobre un mapa de cabeceras llano, sin SDK de por medio — `extract(array $carrier): ?SpanContext` a la entrada, `inject(SpanContext): array<string,string>` a la salida —, así que la propagación funciona igual bajo un tracer respaldado por el SDK que bajo uno de prueba.

Hay cinco fronteras instrumentadas, y la figura las muestra las cinco a la vez. `TracingFilter` (`#[Order(-110)]`, el más externo de los filtros descubiertos) continúa un `traceparent` entrante como padre remoto y abre un span `SERVER`, y después publica sus identificadores en el `Context` de Laravel y en `Request::$attributes` como `firefly.trace_id` y `firefly.span_id`. `TracerCqrsTracing` abre un span `INTERNAL` alrededor de cada comando y cada consulta — no hay nada que transportar, porque el bus es en proceso, así que el span simplemente se anida bajo el que esté vigente. `TracerEdaTracing` abre un span `PRODUCER` llamado `publish <destination>` a la salida, escribiendo el `traceparent` en las cabeceras del sobre, y un span `CONSUMER` llamado `process <destination>` en la entrega, leyendo el mismo valor de vuelta — que es lo que une el span de un worker de colas con la petición que publicó. Hoy tres llamadores alcanzan esa costura: `InMemoryEventBus`, `QueueEventBus` y el `SubscriberRegistrySink` compartido a través del cual entrega todo consumidor de broker. Los publicadores de `eda-rabbitmq`, `eda-kafka` y `eda-postgres` siguen construyendo sus sobres ellos mismos, así que un *publish* de broker no abre ningún span `PRODUCER` ni estampa ningún `traceparent` — su lado de consumo sí queda trazado, a través de ese mismo sink. Y `HttpClientTracingMiddleware`, que `HttpClientTracingPass` instala en la factoría `Http` de Laravel en el arranque, abre un span `CLIENT` en cada llamada saliente e inyecta la cabecera en la petición PSR-7.

Ambas costuras son la forma de `CqrsMetrics` que acabas de leer: `CqrsTracing` vive en `firefly/cqrs` y `EdaTracing` en `firefly/eda`, cada una con un valor por defecto no-op detrás de `#[ConditionalOnMissingBean]`, y la auto-configuración `#[Order(500)]` de observabilidad registra primero la implementación real. Ni `firefly/cqrs` ni `firefly/eda` dependen de `firefly/observability` — mira sus `composer.json` si quieres comprobarlo.

::: figure art/figures/tracing-propagation.svg | Figura 11.1 — Un solo traceparent entra en TracingFilter, se publica en el Context de Laravel como firefly.trace_id y firefly.span_id, y vuelve a salir a través del bus de CQRS, del sobre de EDA y del cliente Http saliente — aterrizando en cada línea de log, en /actuator/httpexchanges y en el panel de administración.

Los tres sitios donde aterriza son el objetivo de todo el ejercicio. `TraceContextLogProcessor` estampa `trace_id` y `span_id` en cada registro de log, junto al identificador de correlación y al de petición. `HttpExchangeFilter` — `#[Order(-100)]`, así que se ejecuta *dentro* del filtro de trazado y el span ya existe cuando graba — lee el identificador de traza de vuelta desde `Request::$attributes`, que es la razón por la que cada fila de `/actuator/httpexchanges` lleva la traza a la que perteneció. Y el panel renderiza esa misma página en el servidor desde esos mismos registros, sin una segunda vía de datos.

---

## Logs que una máquina puede leer

Los tres identificadores que produjo la sección anterior solo sirven si algo puede encontrarlos. Una línea de log de `LineFormatter` con un identificador de traza en algún sitio dentro del texto de su mensaje no es buscable; es un pajar con una aguja dentro.

Así que `firefly.logging.structured.format` — el `logging.structured.format` de Spring Boot 3.4, sobre los canales de Laravel — nombra un formateador, y hay tres:

<!-- source: packages/observability/src/Logging/StructuredLogging.php -->
```php
public const string FORMAT_KEY = 'firefly.logging.structured.format';

public const string CHANNELS_KEY = 'firefly.logging.structured.channels';
```

| Valor | En qué se convierte cada línea |
|---|---|
| `''` (el de por defecto) | apagado — el formato propio de Laravel, intacto |
| `json` | el `JsonFormatter` de Monolog |
| `ecs` | Elastic Common Schema 8, a través del `EcsFormatter` propio del framework |
| `logstash` | el `LogstashFormatter` de Monolog, con los campos del framework bajo `fields` |

Cualquier otra cosa es una `ConfigurationException` **en el arranque** — `LogChannelWiringPass` pide el formato desde `Application::boot()`, antes de tocar el servicio de log, que es lo que convierte una errata en un fallo de arranque en vez de en un repliegue silencioso a texto plano.

La forma de ECS merece verse, porque es la que con más probabilidad aterriza directa en una pila ya existente:

<!-- source: packages/observability/src/Logging/Formatter/EcsFormatter.php -->
```php
/**
 * Elastic Common Schema 8 — the JSON shape Elastic's own ecs-logging libraries emit and Spring Boot's
 * `logging.structured.format=ecs` produces, first-party so the framework adds no logging dependency:
 *
 *   {"@timestamp":"2026-09-20T10:11:12.345678+00:00","log.level":"info","message":"Order 42 shipped",
 *    "ecs.version":"8.11.0","log":{"logger":"stack"},"service":{"name":"ledger","environment":"production"},
 *    "trace":{"id":"4bf9…"},"span":{"id":"00f0…"},"labels":{"correlation_id":"…","request_id":"…"},
 *    "error":{"type":"RuntimeException","message":"boom","stack_trace":"#0 …"},
 *    "context":{"order":42},"extra":{"memory":12}}
 // …
 */
final class EcsFormatter extends NormalizerFormatter
{
    public const string ECS_VERSION = '8.11.0';
```

Los cuatro identificadores del framework y los dos campos de servicio se **elevan** a sus casas de ECS; todo lo que añadiera un procesador o quien llama se queda anidado bajo `extra`/`context` en vez de fundirse en el nivel superior, así que un `message` o una clave de contexto `error` de la propia aplicación jamás puede colisionar con un campo de ECS.

### De dónde salen los identificadores

<!-- source: packages/observability/src/Logging/TraceContextLogProcessor.php -->
```php
/**
 // …
 *   trace_id / span_id       the CURRENT span's ids when a tracer has one (a log line written inside a
 *                            command handler or an event listener names THAT span), else the request's ids
 *                            TracingFilter published in Context; absent when tracing is off.
 // …
 *   request_id               Context firefly.request_id (RequestContextFilter).
 // …
 */
final class TraceContextLogProcessor
{
    public const string TRACE_ID = 'trace_id';
    // …
    public function __invoke(LogRecord $record): LogRecord
```

`correlation_id` es el tercero: el identificador que `problem+json` y la cabecera `X-Correlation-Id` ya llevan, leído desde la clave `Context` de Laravel que siembra `CorrelationIdFilter`. Así que una línea de log nombra el span que la escribió, la petición a la que perteneció, y el identificador de correlación que un cliente puede citarte de vuelta.

Lee dos veces la primera fila. Una línea de log escrita dentro de un manejador de comando lleva **el identificador de span de ese manejador**, no el de la petición — que es exactamente lo que hace útil una búsqueda en los logs, porque te dice cuál de los varios spans de una petición escribió la línea. El procesador recurre a los identificadores de la petición solo cuando no hay ningún span vigente.

Hay un detalle de implementación con una razón detrás: el tracer se resuelve a través de un cierre en **cada** registro en vez de capturarse en la construcción. El procesador se empuja cuando el servicio `log` se resuelve por primera vez, y eso puede ocurrir antes de que el contenedor haya enlazado siquiera un `Tracer` — capturar el nulo significaría no tener identificadores de traza durante el resto del proceso.

### Qué canales, y qué es lo que el formateador no toca

`firefly.logging.structured.channels` lista nombres de canal; vacío — el valor por defecto — significa el canal por defecto. El logger de Monolog de un canal `stack` sostiene las instancias de manejador de sus *miembros*, así que formatear la pila formatea también a los miembros, y listar ambos es inofensivo porque aplicar es idempotente.

Lo que nunca cambia son los manejadores mismos. Un `daily` sigue siendo un fichero diario, un `stack` conserva sus miembros, un canal `slack` sigue publicando en Slack; **solo cambia el aspecto de una línea.** Nada se reemplaza, se añade ni se quita.

Un nombre de canal que no existe bajo `logging.channels` se rechaza, y la razón ilustra bien por qué este framework comprueba las cosas: `LogManager::channel()` nunca lanza para un nombre desconocido — captura su propio *"Log [x] is not defined."* y devuelve un logger de emergencia desechable. Sin la comprobación, una errata pondría los procesadores y el formateador sobre un objeto en el que nadie escribe jamás, mientras el canal real seguiría calladamente escribiendo texto plano sin ningún identificador dentro.

!!! warning "Un canal construido después del arranque no recibe ninguno de los dos"
    Los procesadores y el formateador se adjuntan **por canal, en tiempo de resolución**. Un canal creado más tarde a través de `Log::build()` no tiene ni los identificadores ni el formateador estructurado. Si construyes canales dinámicamente, aplica el formateador tú mismo — o declara el canal en `logging.channels` y nómbralo en `firefly.logging.structured.channels`, que es la vía que toma todo lo demás.

    `gelf` y `logfmt` no se ofrecen. Monolog tiene un `GelfMessageFormatter`, y un canal todavía puede elegirlo a través de la propia clave `formatter` de Laravel.

---

## El panel de administración: `firefly/admin`

Todo lo visto hasta aquí en este capítulo es JSON, y JSON es la forma correcta para un balanceador de carga, una sonda de Kubernetes y un scraper de Prometheus. No es la forma correcta para una persona a las 3 de la madrugada que quiere saber si este proceso compiló sus manifiestos, qué auto-configuración se echó atrás, y a qué resolvió realmente `firefly.data.*`. `firefly/admin` es el paquete para esa persona: un panel de administración renderizado en el servidor sobre esos mismos endpoints del actuator, en el espíritu de Spring Boot Admin. Llega con `firefly/firefly` como el resto de la familia, así que un proyecto del esqueleto ya lo tiene — y, igual que con `firefly/actuator`, tenerlo instalado no es lo mismo que tenerlo activado. Añádelo directamente solo si cogiste los paquetes por separado:

```bash
composer require firefly/admin
```

Después abre `/firefly`. No hay paso de npm en la instalación ni CDN en tiempo de petición — las vistas son Blade puro con CSS en línea y tipografías del sistema, porque un paquete de Composer no puede dar por hecho que npm se ha ejecutado, y un panel que necesita la red es inútil precisamente en los entornos aislados donde más quieres mirar uno.

La mayoría de las páginas son una vista sobre la carga útil de un endpoint; cuatro leen el contenedor en su lugar. El menú las agrupa como piensa un operador y no como están dispuestos los paquetes — qué está haciendo ahora mismo, qué cableó en el arranque, cuáles son sus datos, y cómo está configurado — porque una lista plana de diecisiete enlaces es peor menú que cuatro cortas:

| Grupo | Página | Lee | Responde |
|---|---|---|---|
| Runtime | Resumen | varios | ¿Está sano, qué está haciendo, y qué cableó? |
| Runtime | Salud | `health` | Cada indicador que registró este proceso, con su propio estado y detalles |
| Runtime | Métricas | `metrics` | Contadores, cronómetros y medidores, con sus mediciones actuales |
| Runtime | Tráfico HTTP | `httpexchanges` | Las peticiones más recientes que sirvió esta aplicación |
| Cableado | Beans | `beans` | Cada bean que registró el contenedor, con el estereotipo que lo declaró |
| Cableado | Grafo de beans | `beans` | Cómo dependen tus beans unos de otros, resueltos a través de las interfaces por las que están cableados |
| Cableado | Condiciones | `conditions` | Qué auto-configuraciones se aplicaron, y cuáles se echaron atrás porque aportaste la tuya |
| Cableado | Rutas | `mappings` | La tabla de rutas compilada desde la que sirve el dispatcher |
| Cableado | Programadas | `scheduledtasks` | Métodos registrados por `#[Scheduled]`, con el cron o intervalo que los dispara |
| Configuración | Entorno | `env` | La configuración `firefly.*` resuelta, con los secretos enmascarados |
| Configuración | Propiedades de configuración | `configprops` | Cada DTO `#[ConfigProperties]` que enlazó la aplicación, con los valores que resolvió |
| Configuración | Cachés | `caches` | Los almacenes de caché que tiene configurados esta aplicación |
| Configuración | Loggers | `loggers` | Canales de log y sus niveles, con un control para cambiar uno |

Una página cuyo endpoint no está registrado en *este* proceso — o está apagado — queda **oculta del menú** en lugar de ofrecerse como un enlace que aterriza en una disculpa. Eso importa porque los endpoints del actuator son condicionales: `metrics` desaparece cuando `firefly.observability.metrics.enabled` es falso, y varios otros existen solo si está instalado el paquete que los aporta. El menú tiene que construirse a partir de lo que este proceso registró de verdad, y así se hace.

---

### Lee los endpoints en proceso, no por HTTP

El panel sostiene el `ActuatorRegistry` e invoca cada bean `ActuatorEndpoint` directamente:

<!-- source: packages/admin/src/AdminEndpointReader.php -->
```php
final readonly class AdminEndpointReader
{
    public function __construct(
        private ActuatorRegistry $registry,
        private Config $config,
        private ?Container $container = null,
    ) {}
    // …
    public function available(): array
    {
        $ids = [];
        foreach ($this->registry->all() as $id => $endpoint) {
            if ($endpoint->enabled() && $this->config->bool("firefly.management.endpoint.{$id}.enabled", true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
    // …
    public function read(string $id, array $subPath = [], array $query = []): ?array
    {
        $endpoint = $this->registry->get($id);
        if ($endpoint === null || ! $this->has($id)) {
            return null;
        }

        try {
            $response = $endpoint->handle(new EndpointRequest('GET', $subPath, $query));
        } catch (Throwable) {
            return null;
        }

        return $response === null || is_string($response->body) ? null : $response->body;
    }
// …
}
```

Fíjate en lo que **no** hay en ese método: ninguna mención a `ExposureModel`. Eso es lo más importante que hay que entender de este paquete, y es deliberado. `firefly.management.endpoints.web.exposure.include` vale `health,info` por defecto, así que traer `/actuator/beans` o `/actuator/env` por HTTP daría un 404 — como toda la primera mitad de este capítulo insistió en que debía ser. El panel no necesita nada de eso. Renderiza lo que el proceso ya sabe, en proceso, de modo que **te muestra páginas que la superficie HTTP deliberadamente no expone**, y la superficie JSON sigue siendo segura por defecto. Exponer `beans`, `conditions` y `env` a cualquier llamante anónimo solo para que un navegador pudiera leerlos sería exactamente el intercambio equivocado.

El interruptor de apagado por endpoint *sí* se honra, y la asimetría es el quid: `firefly.management.endpoint.{id}.enabled` significa "este endpoint está apagado", que es una afirmación sobre el endpoint en sí; `exposure.include` significa "este endpoint no está publicado", que es una afirmación sobre la superficie HTTP. El panel no es la superficie HTTP.

Un endpoint que lanza se captura y se reporta como `null` en vez de dejar que se lleve la página por delante — la misma disciplina a prueba de fallos que `HealthEndpoint::readFailSafe()` aplica a los indicadores, y por la misma razón: un contribuyente roto debe degradar su propio panel, no el panel entero.

!!! note "Los detalles de salud se leen del registro de contribuyentes, no a través del endpoint"
    `show-details` vale `never` por defecto, y ese valor es el correcto — impide que un llamante HTTP anónimo aprenda el host de tu base de datos a partir de una conexión fallida. Aplicar esa política de divulgación HTTP al panel, sin embargo, producía un panel de Salud cuyo contenido entero era una disculpa que le decía al operador que fuera a cambiar una clave de configuración. El panel lee el `HealthContributorRegistry` directamente en su lugar, llamando a cada indicador de forma aislada, de modo que uno que lance se reporta DOWN con su motivo y nada más se ve afectado.

---

### El grafo de beans

La mayoría de las páginas son tablas. Dos dibujan una imagen — esta, y el [mapa de entidades](#recorrer-el-modelo-relaciones-filtros-y-un-mapa) más adelante en el capítulo — y esta es la que amortiza el paquete el día en que algo está mal cableado.

`/actuator/beans` te dice *qué* beans existen. No puede decirte a qué está **cableado** cada uno, que es lo que realmente quieres cuando un `#[ConditionalOnMissingBean]` no se disparó como esperabas, cuando un ciclo entre singletons ansiosos ha colgado un arranque sin mensaje alguno, o cuando intentas averiguar a qué se enganchó el paquete que acabas de instalar. `/firefly/graph` responde a eso, como un diagrama SVG por capas más una tabla de relaciones filtrable.

No se refleja nada para construirlo. `ComponentScanner` ya registra, en tiempo de **escaneo**, los tipos de clase e interfaz que pide el constructor de cada componente, y esa lista viaja en el manifiesto compilado igual que cualquier otro hecho escaneado (abreviado):

<!-- source: packages/container/src/Descriptor/ComponentDescriptor.php -->
```php
final readonly class ComponentDescriptor
{
    // …
    public function __construct(
        public string $class,
        public string $stereotype,
        // …
        public array $interfaces,
        // …
        /**
         * The class types this component's constructor asks for — the edges of the bean graph.
         *
         * Recorded at scan time, where reflection is already sanctioned, because the alternative is
         * reflecting at request time to answer "what depends on what", which the reflection-free boot
         // …
         */
        public array $dependencies = [],
    ) {}
// …
}
```

Esa última frase es una decisión de diseño en la que merece la pena detenerse. Un parámetro de constructor tipado `string $name` es configuración; dibujarlo como una arista enterraría las relaciones que importan bajo ruido de `string`/`int`. Un parámetro de **clase anulable o con valor por defecto** *sí* se conserva, porque un colaborador opcional sigue siendo una relación.

#### Tres clases de nodo, y por qué la primera versión estaba casi vacía

Antes que las aristas, los nodos — porque la primera versión de esta página los entendió mal de una forma de la que merece la pena aprender. Una aplicación LaraFly tiene **tres clases de bean**, y las tres tienen que ser nodos:

| Clase | Qué es | De dónde sale |
|---|---|---|
| `component` | Una clase escaneada `#[Component]`/`#[Service]`/`#[Repository]`/`#[RestController]`/`#[Configuration]` | El catálogo de beans |
| `bean` | Un valor **producido por un método fábrica `#[Bean]`** de una `#[Configuration]` | Las filas `produces` del catálogo |
| `config` | Un DTO `#[ConfigProperties]` enlazado desde la configuración | El endpoint `configprops` |

Al principio solo la primera clase era un nodo, y la consecuencia no fue cosmética. El cableado de un framework vive casi por completo en la segunda clase: una autoconfiguración es una `#[Configuration]` cuyos métodos `#[Bean]` producen `MeterRegistry`, `TransactionTemplate`, `AggregateTracker` y demás. Con solo las clases declarantes como nodos, cada arista que apuntaba a uno de esos productos apuntaba a un nodo que no existía. Medido sobre un esqueleto de serie: **42 nodos, 41 productos `#[Bean]` ausentes, 21 dependencias colgando y exactamente una arista dibujada.** La página no mostraba un grafo disperso — era estructuralmente incapaz de mostrar el cableado del framework.

La tercera clase es el mismo error en miniatura. Un DTO `#[ConfigProperties]` está enlazado y es inyectable, pero no se escanea como componente ni lo produce una fábrica, así que nada en el catálogo de beans puede verlo: `App\GreetingProperties` aparecía como *dependencia no resuelta* de `GreetingService` en lugar de como el bean que es. Por eso la página lee el endpoint `configprops` junto al catálogo.

De modo que también hay dos clases de arista, y dicen cosas distintas:

| Arista | De → a | Significado |
|---|---|---|
| `injects` | Un bean → algo de lo que declaró depender | El consumidor lo pidió; el contenedor lo satisface |
| `produces` | Una `#[Configuration]` → el valor que devuelve uno de sus métodos `#[Bean]` | Esta clase es de donde sale ese bean |

Las aristas `injects` se recogen de los parámetros del **constructor** de un componente *y* de los parámetros de cada **método fábrica `#[Bean]`** — el producto depende de lo que su fábrica pidiera. Esa unión es el cableado; los constructores por sí solos son una fracción de él.

Una sutileza sobre la identidad. Un producto `#[Bean]` se identifica normalmente por el **tipo que produce**, porque esa es la clave que enlaza el contenedor y la clave que pide todo consumidor. Pero cuando dos métodos fábrica producen el mismo tipo — la forma que las reglas `#[Primary]`/`#[Qualifier]` del Capítulo 2 existen para desambiguar — el tipo por sí solo los colapsaría en un único nodo y ocultaría justo la ambigüedad por la que abriste la página. Así que cada competidor recibe `Declarante::metodo()` como identidad propia y el tipo desnudo resuelve al primero de ellos, reflejando al contenedor, donde la clave de tipo es un alias del ganador mientras todo candidato sigue alcanzable por nombre.

#### Lo difícil no es dibujar, es resolver

Un constructor pide un **tipo**, y ese tipo es muy a menudo una interfaz — `EventPublisher`, `HealthIndicator`, `Cache` — mientras que el bean que lo satisface es una clase concreta que meramente la implementa. Una lista de aristas construida ingenuamente a partir de los tipos del constructor apunta entonces a nodos que no existen, y el grafo sale como un campo de puntos desconectados. Pregúntate a qué debería dibujar una flecha la dependencia de `WalletService` sobre `WalletRepository`: no al puerto, que es una interfaz sin bean propio, sino a `EloquentWalletRepository`, que es lo que de verdad se va a construir.

Así que cada dependencia se resuelve a través de un índice de interfaces antes de convertirse en arista:

<!-- source: packages/admin/src/BeanGraphIndex.php -->
```php
foreach ($entry['dependencies'] as $dependency) {
    $target = $this->resolve($dependency);

    if ($target === null) {
        $unresolved[] = $dependency;

        continue;
    }
    // …
    $edges[] = [
        'from' => $entry['from'],
        'to' => $target,
        'via' => $target === $dependency ? null : $dependency,
        'type' => $entry['type'],
    ];
}
```

<!-- source: packages/admin/src/BeanGraphIndex.php -->
```php
private function resolve(string $type): ?string
{
    return isset($this->nodes[$type]) ? $type : ($this->satisfiedBy[$type] ?? null);
}
```

El miembro `via` es la honestidad de ese bucle. Cuando la arista pasó por una interfaz, el diagrama la marca y la columna **Wired by** de la tabla de relaciones nombra la interfaz, de modo que quien lee ve la indirección en lugar de que se le muestre calladamente una relación que nunca escribió. Cuando el constructor nombró la clase concreta, la columna simplemente dice `class`.

El índice se construye en orden de catálogo y **gana el primer implementador**, de forma determinista — el catálogo se emite en orden de escaneo, así que la misma aplicación dibuja siempre el mismo grafo en lugar de rebarajarse entre máquinas. Una interfaz con varios implementadores es una ambigüedad real que el contenedor resuelve con `#[Primary]`/`#[Qualifier]`, y el grafo lo dice listando la arista como `via` en lugar de fingir que la elección era obvia.

#### Capas, ciclos y el techo de nodos

Los niveles salen de un recorrido de **camino más largo** sobre las aristas resueltas: la profundidad de un nodo es uno más que la de lo más profundo de lo que depende, y después los niveles se invierten para que el nivel 0 contenga aquello de lo que nada depende. El efecto es que un nodo siempre queda por debajo de todo lo que depende de él, las flechas se leen consistentemente hacia abajo, y la vista puede seguir una cadena desde un controlador hasta el repositorio que hay al final. La vista solo posiciona; los niveles vienen del modelo.

La profundidad se memoiza y el recorrido lleva su propio conjunto de visitados, así que un ciclo termina en lugar de recursar para siempre — y la arista que lo cerró se *reporta*:

<!-- source: packages/admin/src/BeanGraph.php -->
```php
foreach ($out[$node] ?? [] as $next) {
    if (isset($path[$next])) {
        $cycles[] = ['from' => $node, 'to' => $next];

        continue;
    }
    $deepest = max($deepest, $walk($next, $path) + 1);
}
```

Ese reporte vale más de lo que parece. El contenedor no tiene detección de ciclos propia, así que un ciclo entre singletons ansiosos no produce un error útil — agota la memoria en el arranque. Una página que nombra las dos clases implicadas convierte "la app murió sin mensaje" en un diagnóstico de cinco segundos, y el consejo del propio panel es el correcto: rompe una de estas aristas, normalmente inyectando una interfaz y dejando que el otro lado dependa de ella.

Dos límites se declaran en la página en lugar de ocultarse:

* **Pasados los `firefly.admin.graph.max-nodes` — 220 por defecto — el diagrama se suprime** y la tabla de Relaciones de abajo lleva la misma información como una lista filtrable. Un diagrama de más de un par de centenares de nodos es una maraña, no algo que una persona pueda leer, y renderizarlo de todos modos sería peor respuesta que negarse. Es una clave de configuración y no una constante porque "ilegible" depende de la pantalla y de la aplicación.
* **"Provided outside the container" no es una advertencia.** Esas etiquetas son tipos de constructor satisfechos por un binding del contenedor de Laravel y no por un bean escaneado — la `Request`, el repositorio de configuración, una conexión. Se listan en lugar de descartarse en silencio precisamente porque *"¿por qué no está mi bean en el grafo?"* es la pregunta que la página tiene que responder. Un tipo que aparezca ahí y que esperabas que fuera un bean *tuyo* significa que tu escaneo no lo vio, y `firefly.scan.paths` es lo primero que hay que revisar.

!!! tip "Léelo junto a la página de Condiciones"
    Las dos responden mitades complementarias de toda sorpresa de auto-configuración. **Condiciones** dice *si* un bean del framework se registró o se echó atrás, y sobre qué condición. **El grafo** dice a qué está cableado el bean que sí ganó, y a través de qué interfaz. Una arista `EventPublisher` apuntando a `InMemoryEventPublisher` cuando configuraste `firefly.eda.provider=rabbitmq` se ve de un vistazo en el grafo; Condiciones nombra entonces el `#[ConditionalOnProperty]` que no casó.

!!! note "Lo que el grafo sigue sin decidir por ti"
    Dos límites merecen conocerse, y ninguno es una carencia de datos. **`#[Primary]`/`#[Qualifier]` no dirigen el índice** — gana quien escriba primero en orden de escaneo, tanto para una interfaz con varios implementadores como para la clave de tipo desnuda de un `#[Bean]` disputado. Cada competidor sigue teniendo su propio nodo y la arista se marca `via`, así que la ambigüedad es visible en la página, pero el destino dibujado puede no ser el que resuelve el contenedor. Y **un tipo no resuelto se reporta, nunca se explica**: la página puede decirte que un tipo lo provee algo fuera del contenedor, pero no *qué* enlace lo provee, porque un enlace del contenedor de Laravel no lleva descriptor que leer.

---

### El modelo de acceso es toda la frontera de seguridad

Como el panel sortea la exposición, su propia URL es lo único que se interpone delante de `beans`, `env` y `conditions`. Por eso no debe estar encendido por defecto en producción, y por eso la bandera de activación está escrita como está:

<!-- source: packages/admin/src/AdminSettings.php -->
```php
final readonly class AdminSettings
{
    // …
    public function __construct(
        public bool $enabled,
        public string $basePath,
        public string $title,
    // …
    ) {}

    public static function fromConfig(Config $config): self
    {
        $base = trim($config->string('firefly.admin.base-path', '/firefly'), '/');

        return new self(
            enabled: $config->bool('firefly.admin.enabled', $config->bool('app.debug', false)),
            basePath: $base === '' ? 'firefly' : $base,
            title: $config->string('firefly.admin.title', $config->string('app.name', 'LaraFly')),
        // …
        );
    }
// …
}
```

`firefly.admin.enabled` **toma por defecto el valor de `app.debug`**. El razonamiento es que una aplicación que ya corre con debug encendido ya está sirviendo trazas de pila a quien las pida y es un entorno de desarrollo por definición, así que un panel ahí no divulga nada que no estuviera ya divulgado. Una aplicación con debug apagado ha hecho la afirmación contraria sobre sí misma, y debe optar por él explícitamente. Fijar la clave siempre gana sobre el valor por defecto de debug, en ambas direcciones — puedes apagar el panel en un entorno con debug, y encenderlo en uno de producción.

!!! warning "Encenderlo fuera de debug es solo la mitad del trabajo"
    `firefly.admin.enabled = true` con `app.debug = false` monta un panel que renderiza tu grafo de beans, tu configuración resuelta y tu tabla de rutas en una URL conocida, para cualquiera que pueda alcanzarla. El panel no trae **ninguna autenticación propia** — no tiene dependencia de código con `firefly/security` en absoluto, exactamente igual que `firefly/actuator`. Una aplicación que lo encienda fuera de debug **debe poner la ruta detrás de su propio middleware de autenticación**.

Las reglas de `HttpSecurity` del Capítulo 10 lo hacen como pura configuración, del mismo modo que este capítulo ya aseguró el actuator — aquí tienes un despliegue que opta por el panel y le echa el candado en un solo fichero:

<!-- illustrative: the deployment's own config/firefly.php; turning the dashboard on with app.debug off is the deployment's decision -->
```php
<?php

declare(strict_types=1);

return [
    'admin' => [
        'enabled' => true,          // explicit: this deployment wants the dashboard with app.debug off
        'base-path' => '/firefly',
    ],
    'security' => [
        'enabled' => true,
        'http' => [
            'enabled' => true,
            'rules' => [
                ['pattern' => 'firefly', 'access' => 'hasRole:ADMIN'],
                ['pattern' => 'firefly/*', 'access' => 'hasRole:ADMIN'],
            ],
        ],
    ],
];
```

`AdminRouteRegistrar` monta las dos rutas — un índice y un comodín `GET|POST {base}/{page}` — como un `BootPass` en `WiringPasses`, orden **60**, un paso por detrás del 50 de `ActuatorRouteRegistrar`, porque lee el registro que puebla ese pase. Se registran nativamente sobre el `Router` de Illuminate por la misma razón que las rutas del actuator y las del paquete OpenAPI: `firefly.admin.base-path` tiene que ser fijable por aplicación, y una ruta por atributo hornea su ruta literal dentro de un `RouteDescriptor` compilado. Cuando el panel está deshabilitado el pase no registra *absolutamente nada* — no hay ruta que adivinar ni manejador al que llegar.

También se echa atrás en silencio en un caso más, fácil de pasar por alto. Blade es necesario para renderizar el panel y no es una dependencia del paquete, así que un despliegue solo-JSON sin factoría de vistas enlazada no obtiene rutas en vez de rutas que fallarían fatalmente en la primera petición; allí la superficie de gestión sigue siendo el actuator JSON.

!!! warning "Tres cosas que el panel solo puede mostrarte de *este* proceso"
    Bajo PHP-FPM cada petición es un proceso distinto, y tres páginas heredan eso. **Cambiar un nivel de log** llama al mismo endpoint que `POST /actuator/loggers/{name}`, que muta los manejadores de Monolog del proceso actual — la siguiente petición es otro proceso, así que cambia `logging.channels` para cualquier cosa que deba persistir. Las **métricas** son solo tan duraderas como el registro: el `SimpleMeterRegistry` por defecto guarda los medidores en memoria de proceso, así que el panel ve solo su propia petición salvo que `firefly.observability.metrics.store` apunte a un almacén de caché. Y los **detalles de salud** siguen ocultos en la respuesta JSON `/actuator/health` hasta que `firefly.management.endpoint.health.show-details` sea `always`, aunque la propia página de Salud del panel lea los indicadores directamente.

### El navegador de datos, y por qué no hereda ese valor por defecto

`firefly/admin` incluye una superficie más, y es la única de este capítulo cuya puerta está escrita de forma distinta a todas las que has visto. Es un **navegador de base de datos** al estilo del admin de Django sobre la capa de datos del Capítulo 5 — listado, detalle, búsqueda, ordenación y paginación sobre tus propios repositorios — al que se llega a través de un único `DataBrowser` que `DataBrowser::forContainer($container)` ensambla desde el contenedor de la aplicación.

Descubre qué navegar igual que el resto del panel descubre todo lo demás — desde el catálogo compilado. **Todo bean cuya lista de interfaces de tiempo de escaneo contenga `CrudRepository` es un recurso navegable.** No se registra nada ni se declara nada: un repositorio que escribas es navegable en cuanto el contenedor lo tiene, y uno que borres deja de serlo sin que nadie edite una lista. Cada fila de `BeansCatalog` ya lleva el cierre completo de interfaces que `ComponentScanner` registró con `class_implements()`, así que «¿es este bean un repositorio, y además pagina?» son dos llamadas a `in_array()` sobre datos que el proceso ya tiene — sin reflexión y, de forma decisiva, sin posibilidad de ofrecer un recurso que el contenedor nunca registró.

Ahora la puerta:

<!-- source: packages/admin/src/Data/DataBrowserSettings.php -->
```php
final readonly class DataBrowserSettings
{
    // …
    public static function fromConfig(Config $config): self
    {
        $max = min(self::PAGE_SIZE_CEILING, max(1, $config->int('firefly.admin.data.max-page-size', 200)));

        return new self(
            enabled: $config->bool('firefly.admin.data.enabled', false),
            writable: $config->bool('firefly.admin.data.writable', false),
            pageSize: min($max, max(1, $config->int('firefly.admin.data.page-size', 25))),
            maxPageSize: $max,
            excluded: self::csv($config->string('firefly.admin.data.exclude', '')),
        // …
        );
    }
    // …
    public function canWrite(): bool
    {
        return $this->enabled && $this->writable;
    }
// …
}
```

Fíjate en los dos valores por defecto, y compáralos con el `$config->bool('app.debug', false)` de `AdminSettings` unas páginas más arriba. El panel sigue a `app.debug`, y el argumento para eso era sólido **para lo que el panel muestra**: beans, condiciones, mapeos y configuración resuelta son hechos sobre la *aplicación*, y una aplicación que ya sirve trazas de pila ya ha publicado hechos de esa clase.

Esta página muestra hechos sobre los **usuarios** de la aplicación. Esa es una divulgación categóricamente mayor, y los errores que la exponen son los ordinarios, los que hoy no cuestan nada: una bandera de depuración olvidada en un entorno de staging que comparte base de datos con producción, un `.env` copiado a una máquina que debía ser interna, un portátil tunelizado para una demo. Cada uno se convierte en una divulgación de registros de clientes en cuanto hay un navegador de datos atado a `app.debug`. Así que la puerta es aparte, explícita y está cerrada — **`app.debug` no puede abrirla, y `firefly.admin.enabled` tampoco.** Las tres deben ser ciertas.

Las escrituras necesitan entonces una *segunda* clave, y por sí sola no sirve de nada. Leer la fila equivocada es una divulgación; borrarla es pérdida de datos sin deshacer, desde un formulario, sobre una sesión que puede no ser más que «debug estaba encendido». Encender el navegador es una decisión sobre **visibilidad**; encender las escrituras es una decisión sobre **custodia**. Si las colapsas en una sola clave, quien quería mirar una tabla ha armado también el botón de borrar.

!!! warning "Create existe para Eloquent, y se rechaza para todo lo demás"
    El navegador no tuvo `create()` durante un tiempo, y el argumento era medio cierto. Un formulario de creación genérico sobre una entidad arbitraria es una promesa que no puede cumplir, y el Capítulo 6 explica por qué: **el constructor de un agregado es donde viven sus invariantes.** Un `Order` que debe tener al menos una línea, un `Wallet` cuyo saldo empieza a cero en la moneda con la que se abrió, un objeto valor que rechaza un IBAN mal formado — un formulario construido a partir de una lista de columnas no conoce ninguno. Solo hay dos formas de construir esa fila: llamar al constructor, que necesita argumentos que el formulario no puede suministrar con los tipos ni en el orden correctos; o escribir las columnas directamente en la tabla, lo que produce una fila que el modelo de dominio considera imposible. Lo segundo es lo que hace una implementación de «pues inserta las columnas», y es *peor que no tener botón*, porque parece que funcionó. **Ese caso se sigue rechazando, por su nombre.**

    Nunca fue cierto para un modelo **Eloquent**, que se construye vacío y se rellena por atributo — exactamente lo que `update()` lleva haciendo siempre sobre una fila que ya existe. Create rechazaba por un riesgo que update ya estaba asumiendo, y la inconsistencia le costaba a toda aplicación una superficie CRUD que se quedaba en RUD. Así que se ofrece para un recurso respaldado por Eloquent bajo los dos mismos interruptores, con el identificador y cualquier columna enmascarada *omitidos del formulario* en lugar de deshabilitados en él: un campo que el navegador se negaría a escribir no debería aparentar que lo acepta.

Merece la pena llevarse otras dos decisiones de esta sección, porque ambas parecen un detalle y no lo son.

**El identificador y cualquier secreto enmascarado se rechazan como destino de una actualización** — y se rechazan dos veces, una para que la vista pueda dibujar el campo como solo lectura y otra en la ruta de escritura, de modo que un POST fabricado a mano no alcance lo que el formulario no ofrecía. Recodificar la clave de una fila desde un formulario genérico no es una edición, es otra fila, y las claves foráneas que apuntaban al valor antiguo no la siguen. El valor *mostrado* de un secreto es `******`, así que devolver un formulario renderizado escribiría la máscara sobre la credencial real — un fallo de pérdida de datos creado por el propio enmascarado. Los secretos se excluyen de la **búsqueda** por una razón emparentada: una caja que responde «sí, el `api_token` de alguna fila empieza por `sk_live_9`» es un oráculo que un operador puede recorrer carácter a carácter.

**Ningún texto de error que la página muestre es jamás un mensaje de excepción.** La `QueryException` de Laravel convierte a cadena el SQL fallido *y sus bindings* dentro de `getMessage()`, así que reproducirlo publicaría el esquema y los valores enlazados — que en una búsqueda sobre una tabla de usuarios es la propia consulta del operador, y en una consulta de detalle es una clave primaria. Toda razón es una frase fija compuesta en la capa del navegador, más como mucho el nombre de clase de la excepción; el mensaje se queda en la excepción, donde el log puede tenerlo. Por eso mismo nada en la capa lanza hacia su llamador: las lecturas responden con un listado que lleva una razón, las escrituras con uno de cuatro resultados (`Done`, `Refused`, `NotFound`, `Failed`), y una vista que dibuja una página de administración nunca tiene que ser a prueba de excepciones para mantenerse en pie.

!!! note "La ruta de listado que obtienes depende de la interfaz que implementaste"
    Un `PagingAndSortingRepository` se pagina **en la base de datos**: el repositorio hace el desplazamiento, el límite, el `ORDER BY` y el `COUNT`, y el coste es independiente del tamaño de la tabla. Un `CrudRepository` simple no puede expresar nada de eso, así que el navegador llama a `findAll()`, ordena y corta **en PHP**, y descarta todas las filas menos 25 — lo que con diez mil filas es una página lenta y con diez millones es un agotamiento de memoria que mata al worker, al *primer* clic. La interfaz no tiene límite, ni desplazamiento, ni conteo con predicado, así que las opciones honestas eran «negarse a navegar repositorios que no pueden paginar» o «navegarlos y decir lo que cuesta». LaraFly hace lo segundo, y esto es el decirlo. Implementa `PagingAndSortingRepository` en todo lo que pretendas navegar contra una tabla real.

### Recorrer el modelo: relaciones, filtros y un mapa

Un listado que solo puedes desplazar es un volcado de tabla. Tres cosas lo convierten en algo que exploras.

**Las relaciones se descubren LLAMANDO a los métodos que declaran una**, porque es la única forma de saber por qué columnas unen — el nombre de un método no dice nada y su tipo de retorno solo dice de qué clase es. Lo que convierte «qué es seguro llamar» en la pregunta que sostiene todo, y la respuesta es el **tipo de retorno declarado**: solo se llama a un método público, no estático y sin argumentos cuyo tipo de retorno sea una subclase de `Relation` de Eloquent. Un método que anuncia `: HasMany` es una definición de relación por construcción — la misma señal en la que se apoyan la validación de `with()` y las herramientas de IDE de Laravel — y un accesor no puede reclamarla sin mentir sobre su propia firma. La llamada no ejecuta consulta alguna; Eloquent la difiere hasta `get()`.

Un `belongsTo` abre el único registro padre; un `hasMany` abre el listado hijo **filtrado por la clave de esta fila**, que es para lo que la página de registro necesita un filtro. Una relación cuyo otro extremo no es un recurso navegable se sigue mostrando — te dice la forma del modelo — pero no se enlaza, y esa distinción vive en el modelo y no en la plantilla para que una vista no pueda acuñar una URL que da 404.

**Filtrar son ocho comparaciones sobre las columnas que el recurso ya publica** — `is`, `is not`, `contains`, `starts with`, `greater than`, `less than`, `is empty`, `is not empty`, que el panel rotula así, en inglés, y no traduce (*es*, *no es*, *contiene*, *empieza por*, *mayor que*, *menor que*, *está vacío* y *no está vacío*) — expresadas como una URL GET que puedes guardar en marcadores o pegar en un ticket. Toda comparación enlaza su valor como parámetro, incluidas las de `LIKE`, donde los comodines van alrededor de un valor *escapado* en vez de meter el valor dentro de un patrón. Una columna que el esquema no publica y un operador fuera de ese conjunto se **descartan** en lugar de pasarse al driver: ambos llegan en una URL que un operador puede editar a mano, y una consulta que alcanza el driver con un identificador arbitrario dentro es, como poco, un oráculo de nombres de columna. Las condiciones se combinan con AND entre sí y con la caja de búsqueda, así que estrechar el listado de una relación no puede escaparse de ella.

Las mismas ocho están implementadas para el camino de reserva en PHP, porque un repositorio que no puede paginar debe filtrarse por las mismas reglas que uno que sí. Dos implementaciones de un predicado divergen, y la divergencia se manifiesta como un filtro que significa cosas distintas en recursos distintos.

**`/firefly/data-map` lo dibuja.** Cada entidad navegable como una caja con sus columnas, cada clave foránea como una arista etiquetada, y cada caja enlazando a sus propios registros. Un `hasMany` y el `belongsTo` que lo mira de frente son *una* clave vista desde dos extremos, así que cada una se dibuja una vez — apuntando desde la tabla que *tiene* la clave hacia la tabla a la que referencia, que es además lo que la flecha significa.

### Dos páginas más, y la que sí cambia cosas

**`/firefly/datasource`** responde a lo que un volcado de configuración no puede. ¿Con qué base de datos estoy hablando (driver, host, base, con `password` enmascarada por el mismo enmascarador que usa el endpoint `env`)? ¿Está *arriba* — se prueba una conexión por carga de página, porque abrir un socket puede quedarse colgado contra un host tras un cortafuegos y una página que abriera todas las conexiones configuradas tardaría en renderizar el timeout de la más lenta, justo en la página que abriste *porque* algo va mal. ¿Qué significa el pooling aquí — PHP no tiene pool de conexiones, y en vez de inventar un indicador la página informa del `ATTR_PERSISTENT` de PDO por lo que es, y dice que bajo php-fpm el tamaño efectivo del pool es tu número de workers. Y a qué compiló `#[Transactional]`, que hasta ahora solo existía como un artefacto bajo `bootstrap/cache`.

**`/firefly/settings`** es la única página del panel que *cambia* la aplicación en lugar de describirla, y está protegida en consecuencia: `firefly.admin.settings.enabled` (desactivada por defecto, a diferencia de todo lo demás aquí), `.writable` encima, y una tercera puerta que **no es una clave de configuración** — en producción toda escritura se rechaza diga lo que diga el resto. Eso último es deliberado. Es la diferencia entre «lo hemos hecho seguro» y «lo hemos hecho configurable para que sea seguro», y solo lo primero sobrevive a que alguien copie un `.env`.

Es un *interruptor de funcionalidad*, no un endpoint de configuración remota: la lista es fija y propiedad del framework, así que un POST fabricado que nombre `app.key` o el host de una base de datos no encuentra nada que escribir. Un cambio va a un único fichero JSON bajo `bootstrap/cache`, filtrado tanto a la entrada como a la salida, y se mezcla sobre la configuración en el `register()` del provider — no en un boot pass, y esa distinción costó una sesión de depuración. Todo objeto de settings de este framework se construye una vez desde la configuración y se retiene, así que aplicar los overrides desde el propio pase del panel escribía el fichero y mostraba el nuevo estado en la página mientras `/openapi.json` seguía respondiendo 200. `register()` corre antes de que se resuelva ningún bean, que es el único punto en el que la mezcla es cierta.

!!! laravel "Paridad con Laravel"
    Laravel puro no trae ningún endpoint de comprobación de salud ni de métricas en absoluto — la mayoría de los equipos o bien improvisan una ruta `/health` a mano o recurren a un paquete de terceros, normalmente emparejado con la extensión `ext-prometheus`. `firefly/actuator` y `firefly/observability` son análogos de primera parte y con pocas dependencias de Spring Boot Actuator y Micrometer respectivamente: endpoints de framework montados sobre el mismo `Router` que tu app ya usa, comprobaciones de salud que reutilizan por debajo los propios `DB`/`Log`/config de Laravel, y un exportador Prometheus en PHP puro sin requisito de extensión. Ambos paquetes son dependencias Composer opcionales y ambos son seguros por defecto — una app que añade `firefly/actuator` obtiene `health`/`info` y nada más hasta que configure más. `firefly/admin` completa el conjunto como análogo de Spring Boot Admin, con la diferencia de que no es una aplicación de monitorización aparte que despliegas y en la que registras instancias: son vistas Blade dentro de la propia aplicación sobre la que informan, que es por lo que puede leer el registro directamente y por lo que su modelo de acceso importa tanto como importa.

---

## Lo que aprendiste {.recap}

| Concepto | Qué hace |
|---|---|
| `HealthIndicator` | SPI de un método; un bean `#[Component]` descubierto y agregado automáticamente |
| `Health` / `Status` | Lectura inmutable + un enum ordenado por severidad; DOWN/OUT_OF_SERVICE se mapean ambos a HTTP 503 |
| `PingHealthIndicator` / `DiskSpaceHealthIndicator` / `DbHealthIndicator` | Sondeo de liveness siempre-arriba; comprobación de disco basada en umbral; comprobación de BD `SELECT 1` activa por defecto, que declina su registro mediante `ConditionalHealthIndicator::available()` cuando no hay conexión por defecto configurada |
| `HealthEndpoint` | Agrega al estado más severo; un **grupo** de sondeo es solo un subconjunto de indicadores con nombre y configurado — no hay una clase de endpoint liveness/readiness separada |
| `ExposureModel` | Puerta CSV `include`/`exclude`; por defecto `"health,info"`; todo lo demás es un 404 simple hasta que se exponga |
| `EnvEndpoint` / `SensitiveValueMasker` | Una única regla compartida enmascara las claves `password\|secret\|token\|key\|credential\|passwd\|authorization\|headers` con `******`, independientemente de la exposición — la **clave** decide primero, así que una clave sensible enmascara todo su subárbol |
| `MeterRegistry` / `MetricsRecorder` | Puertos de métricas de lectura/escritura; `SimpleMeterRegistry` es idempotente por `type\|name\|tags` y falla ruidosamente ante un conflicto de tipo |
| `PrometheusTextFormat` | Exposición a prueba de locale — `number_format()`, nunca `sprintf('%f')` |
| `DistributionStatisticConfig` | Buckets de histograma por **nombre** de medidor, fijados cuando se crea el temporizador; sin buckets por defecto, y una lista por medidor vacía devuelve ese medidor a ser un summary |
| `firefly.logging.structured.format` | `json`, `ecs` o `logstash` sobre los canales que nombres — solo cambia el formato de una línea, nunca los manejadores, y un formato o un canal desconocido es un fallo de arranque |
| `TraceContextLogProcessor` | `trace_id`, `span_id`, `correlation_id` y `request_id` en cada registro; los identificadores del span vigente cuando hay uno, si no los de la petición |
| `MetricsFilter` | Filtro de cronometraje más externo `#[Order(-100)]`; etiqueta por la **plantilla** de la ruta, nunca la ruta en bruto — cardinalidad acotada |
| `ObservabilityAutoConfiguration` `#[Order(500)]` | El mismo truco de precedencia que la costura de seguridad del Capítulo 10: registra `cqrsMetrics()` antes de que `CqrsAutoConfiguration` evalúe su `#[ConditionalOnMissingBean]` |
| `firefly/admin` | Un panel Blade renderizado en el servidor en `/firefly`; una cuyo endpoint no está registrado o está apagado se oculta del menú en lugar de enlazarse |
| `AdminEndpointReader` | Invoca cada `ActuatorEndpoint` **en proceso** desde el `ActuatorRegistry`, sorteando `ExposureModel` — así el panel muestra lo que la superficie HTTP no expone, y un endpoint que lanza degrada un solo panel |
| `BeanGraph` | Convierte el catálogo de beans en un grafo de dependencias dibujado sobre **tres clases de nodo** — componentes, productos `#[Bean]` y DTOs `#[ConfigProperties]` — con aristas `injects`/`produces` resueltas a través de un índice de interfaces (marcadas `via`), estratificación por camino más largo, ciclos reportados en lugar de colgarse, y el diagrama suprimido pasados `firefly.admin.graph.max-nodes` (220) |
| Los productos `#[Bean]` como nodos | El cableado de un framework vive en métodos fábrica, no en constructores; con solo las clases declarantes como nodos, un esqueleto de serie dibujaba **una** arista de 42 beans |
| `ComponentDescriptor::$dependencies` | Las aristas del grafo, registradas por `ComponentScanner` en tiempo de **escaneo** — solo tipos de clase e interfaz, porque un parámetro escalar es configuración, no cableado |
| `firefly.admin.enabled` | Toma por defecto `app.debug`; un valor explícito gana en ambas direcciones, y encenderlo con debug apagado te obliga a poner tu propio middleware de autenticación delante de la ruta |
| `firefly.admin.data.enabled` | La puerta propia del navegador de datos, con valor por defecto **`false`** — *no* sigue a `app.debug` ni a `firefly.admin.enabled`, porque esta página muestra hechos sobre los usuarios de la aplicación y no sobre la aplicación |
| `RelationIntrospector` | Encuentra relaciones LLAMANDO solo a los métodos cuyo tipo de retorno declarado es una `Relation` de Eloquent, para que un registro enlace con lo que referencia y el mapa de entidades tenga aristas que dibujar |
| `DataFilter` | Ocho comparaciones sobre las columnas que el recurso publica, siempre enlazadas como parámetro, y con una columna u operador desconocido descartado en lugar de pasado al driver |
| `/firefly/datasource` | Conexiones con los secretos enmascarados, una probada por carga, la persistencia de PDO contada por lo que es, y el contrato `#[Transactional]` compilado |
| `/firefly/settings` | La única página que cambia la aplicación: desactivada por defecto, escribible con una segunda clave, y rechazada en producción por una puerta que ninguna clave levanta |
| `firefly.admin.data.writable` | Una **segunda** puerta, también `false` e inútil por sí sola: visibilidad y custodia son decisiones distintas, y una sola clave armaría el botón de borrar para quien solo quería mirar una tabla |
| Sin `create()` | Permanente, no pendiente: las invariantes de un agregado viven en su constructor, y un formulario construido a partir de una lista de columnas no puede satisfacerlas — escribir las columnas de todos modos produce una fila que el dominio considera imposible |

---

## Ponlo en práctica {.exercises}

1. **Añade un `HealthIndicator` personalizado.** Escribe un `#[Component]` que implemente `HealthIndicator` que compruebe algo específico de tu propia app (un feature flag, la profundidad de una cola, una conexión de caché) y confirma que aparece en el mapa `components` de `GET /actuator/health` una vez que `show-details` esté configurado a `always`.
2. **Configura una división liveness/readiness real.** Añade `firefly.management.endpoint.health.group.liveness.include = 'ping'` y `...readiness.include = 'ping,db'` (con el indicador de BD habilitado) a la configuración de un proyecto de pruebas, y confirma que `GET /actuator/health/liveness` y `GET /actuator/health/readiness` divergen en el momento en que dejas la base de datos inalcanzable.
3. **Observa a la costura de métricas de CQRS ganar la carrera.** Instala `firefly/observability` en un proyecto de pruebas que ya use `firefly/cqrs`, envía un puñado de comandos, e inspecciona `GET /actuator/prometheus` en busca de muestras de `cqrs_commands_seconds` — luego comenta temporalmente el atributo `#[Order(500)]` de `ObservabilityAutoConfiguration` (revirtiendo al valor por defecto de la clase) y confirma si la métrica todavía aparece, para ver el truco de ordenamiento importar de verdad en lugar de solo leer sobre él.
4. **Demuéstrate a ti mismo el sorteo de la exposición.** Instala `firefly/admin` en el sample, deja `firefly.management.endpoints.web.exposure.include` en su valor por defecto, y confirma que `GET /actuator/beans` devuelve un `404` mientras `/firefly/beans` renderiza la lista completa de beans en el mismo proceso. Luego pon `firefly.management.endpoint.beans.enabled` a `false` y confirma que la entrada Beans desaparece del menú del panel — el interruptor de apagado se honra allí donde la exposición no, y la diferencia entre ambas claves es todo el diseño.
5. **Dibuja tu propio cableado y luego rómpelo.** Abre `/firefly/graph` en el sample y encuentra la flecha de `WalletService` a `EloquentWalletRepository` — fíjate en que la columna *Wired by* dice `WalletRepository`, el puerto, y no `class`. Después introduce un ciclo deliberado (haz que un `#[Service]` tome un parámetro de constructor tipado como otro `#[Service]` que ya depende de él), recarga la página, y confirma que la estadística **Cycles** se pone en rojo y nombra ambas clases. Ahora arranca la app de cero sin abrir el panel, y compara lo que PHP te cuenta sobre ese mismo ciclo.
6. **Lee el valor por defecto de acceso como una decisión de seguridad.** Pon `app.debug` a `false` en un proyecto de pruebas con `firefly/admin` instalado y confirma que `/firefly` está genuinamente sin enrutar y no simplemente sin enlazar (`php artisan route:list` no debería listarla). Luego pon `firefly.admin.enabled` a `true` sin añadir ninguna regla de `HttpSecurity`, y mira qué divulga ahora un `GET /firefly/env` sin autenticar — esa es exactamente la brecha que este capítulo te dijo que cerraras con tu propio middleware de autenticación.
7. **Convierte un summary en algo de lo que puedas sacar un p99.** Haz un scrape de `GET /actuator/prometheus` en un proyecto con `firefly/observability` instalado y busca la familia `http_server_requests_seconds`: fíjate en la línea `# TYPE … summary` y en que todo lo que tienes es `_count` y `_sum`. Después pon `firefly.observability.metrics.distribution.buckets` con la lista por defecto de los clientes que este capítulo imprime, vuelve a hacer el scrape, y confirma que la línea `# TYPE` ahora dice `histogram` y que apareció una serie `_bucket{le=…}` por cada cota. Ahora silencia solo ese medidor otra vez con una lista `distribution.per-meter.http_server_requests_seconds` **vacía**, y confirma que todos los demás temporizadores conservan sus buckets. Por último mete un `0` — o un `-1`, o una cadena — en la lista global y confirma que el arranque lo rechaza por su nombre en vez de descartar la cota calladamente.
8. **Haz tus logs legibles por una máquina, y luego lee una línea.** Pon `firefly.logging.structured.format` a `ecs`, haz una petición que escriba una línea de log desde dentro de un manejador de comando, y lee la línea resultante: busca `trace.id`, `span.id` y `labels.correlation_id`, y confirma que el identificador de span es el **del manejador**, no el de la petición, comparándolo con una línea escrita desde un controlador corriente en la misma petición. Después comprueba que tus propias claves de contexto aterrizaron bajo `context`/`extra` en vez de en el nivel superior — prueba a registrar una clave llamada literalmente `message` y confirma que no sobrescribió la de ECS. Por último, pon la clave a `jsonn` y confirma que la aplicación se niega a arrancar, nombrando la clave, en vez de recurrir en silencio a texto plano.
