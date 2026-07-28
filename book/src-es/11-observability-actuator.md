<span class="eyebrow">Parte IV — Observabilidad, Pruebas y Entrega · Capítulo 11</span>

# Observabilidad: Salud, Métricas y el Actuator {.chtitle}

Al terminar este capítulo conocerás el SPI `HealthIndicator` de `firefly/actuator` y los indicadores integrados `Ping`/`DiskSpace`/`Db`, cómo `HealthEndpoint` los agrega en una única respuesta `/actuator/health` — y cómo un **grupo** de sondeo (el mecanismo que hay detrás de "liveness" y "readiness") no es más que un subconjunto de indicadores con nombre y configurado, cómo toda la superficie de gestión está **sin exponer por defecto** de modo que un endpoint olvidado falla cerrado como un 404 en lugar de una fuga de información, y el `MeterRegistry` en PHP puro de `firefly/observability`, su exportador Prometheus a prueba de locale, y el truco exacto de precedencia `#[Order(500)]` — el mismo que el Capítulo 10 te mostró para la seguridad — que permite a `MeterRegistryCqrsMetrics` reemplazar el `NoOpCqrsMetrics` del bus de CQRS sin ningún cambio de código en `firefly/cqrs`.

!!! note "Término nuevo: actuator"
    Un **actuator** es un endpoint de gestión que informa sobre el *proceso en ejecución en sí* — si está sano, con qué arrancó, cuán rápidas son sus peticiones — en lugar de sobre el dominio de negocio al que sirve el proceso. El término y la forma provienen ambos de Spring Boot Actuator; `firefly/actuator` es un análogo PHP de primera parte y con pocas dependencias: endpoints de framework montados directamente sobre el mismo `Router` de Illuminate que usan tus propios controladores, no un proceso de administración separado.

---

## El SPI `HealthIndicator`

Una comprobación de salud en LaraFly es cualquier `#[Component]` que implemente un método:

```php
interface HealthIndicator
{
    public function health(): Health;
}
```

`Health` es una lectura inmutable de estado-más-detalles, construida exclusivamente a través de cuatro fábricas con nombre:

```php
final readonly class Health
{
    public function __construct(
        public Status $status,
        public array $details = [],
    ) {}

    public static function up(array $details = []): self
    {
        return new self(Status::Up, $details);
    }

    public static function down(array $details = []): self
    {
        return new self(Status::Down, $details);
    }

    public static function outOfService(array $details = []): self
    {
        return new self(Status::OutOfService, $details);
    }

    public static function unknown(array $details = []): self
    {
        return new self(Status::Unknown, $details);
    }
}
```

`Status` es un enum respaldado que lleva su propia ordenación por severidad y el estado HTTP al que se mapea — DOWN y OUT_OF_SERVICE se renderizan ambos como `503`, de modo que un balanceador de carga no necesita ningún caso especial para tratar cualquiera de los dos como "saca esta instancia de la rotación":

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

`DbHealthIndicator` es el único indicador que es **opcional** en lugar de estar activo por defecto — `#[ConditionalOnProperty]` sin `matchIfMissing`, de modo que un proyecto esqueleto sin base de datos configurada nunca ve un `DOWN` sorpresa de una comprobación que nunca pidió:

```php
#[Component]
#[ConditionalOnProperty(name: 'firefly.management.endpoint.health.db.enabled', havingValue: 'true')]
final class DbHealthIndicator implements HealthIndicator
{
    public function __construct(private readonly ConnectionResolverInterface $connections) {}

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

Fíjate en que `DbHealthIndicator` depende directamente de la propia `ConnectionResolverInterface` de Illuminate en lugar de nada de `firefly/data` — no hay ninguna arista `Actuator → Data` en `deptrac.yaml` en absoluto, de modo que una comprobación de salud de BD no le cuesta a `firefly/actuator` ninguna dependencia nueva. Y cada indicador aquí sigue la misma forma a prueba de fallos: una consulta, una comparación o una llamada al sistema de archivos que podría lanzar una excepción siempre se captura y se convierte en `Health::down()` con un detalle que explica por qué — nunca una excepción sin manejar, nunca un `500` donde corresponde un `503`.

---

## Agregando salud: `HealthEndpoint` y los grupos de sondeo

`HealthEndpoint` es en sí mismo un `#[Component]` (no un simple interno del framework — tiene que ser descubrible como cualquier otro bean) que lee cada indicador registrado, ejecuta cada uno a prueba de fallos, y pliega los resultados hacia abajo hasta el único estado más severo:

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

```php
final readonly class ExposureModel
{
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

    public function isExposed(string $id): bool
    {
        if (in_array($id, $this->exclude, true)) {
            return false;
        }

        if (in_array('*', $this->include, true)) {
            return true;
        }

        return in_array($id, $this->include, true);
    }
}
```

El `include` por defecto es `"health,info"` — cualquier otro id de endpoint (`env`, `beans`, `conditions`, `mappings`, `loggers`, `scheduledtasks`, y las `metrics`/`prometheus` de observabilidad una vez instalado ese paquete) **no está expuesto** hasta que lo añadas explícitamente, y `ActuatorDispatchAction` renderiza un id no expuesto o desconocido como un `404` simple a través del mismo `ProblemDetailsRenderer` que presentó el Capítulo 4 — nunca un error de framework en bruto, y nunca un `200` silencioso con un cuerpo que un llamante no autenticado no debería ver. `.exclude` siempre gana sobre `.include`, de modo que `include: '*'` más una lista corta de `exclude` es una política legítima de "expón todo excepto…".

`/actuator/env` superpone una segunda red de seguridad, independiente, encima de la exposición: incluso una vez expuesta, cualquier clave cuyo nombre coincida con `password|secret|token|key|credential|passwd` (sin distinguir mayúsculas, recursivamente a través de arrays anidados) se enmascara antes de construir la respuesta — defensa en profundidad para un endpoint que solo es alcanzable en absoluto una vez que has optado por él:

```php
#[Component]
final class EnvEndpoint implements ActuatorEndpoint
{
    private const MASK = '******';

    private const SENSITIVE = '/password|secret|token|key|credential|passwd/i';

    public function __construct(private readonly Repository $config) {}

    public function endpointId(): string
    {
        return 'env';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function handle(EndpointRequest $request): EndpointResponse
    {
        $firefly = (array) $this->config->get('firefly', []);

        return EndpointResponse::json(['firefly' => $this->mask($firefly)]);
    }

    private function mask(array $values): array
    {
        $masked = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $masked[$key] = $this->mask($value);

                continue;
            }
            $masked[$key] = preg_match(self::SENSITIVE, (string) $key) === 1 ? self::MASK : $value;
        }

        return $masked;
    }
}
```

Más allá de la exposición y el enmascaramiento, el `HttpSecurity` de `firefly/security` (Capítulo 10) es lo que realmente asegura la superficie para el tráfico real, y no necesita **ningún** cambio de código para hacerlo — `HttpSecurityFilter` es un middleware global, de modo que se ejecuta para las propias rutas registradas directamente por el actuator exactamente igual que se ejecuta para tus controladores:

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
    `/actuator/info` genuinamente tiene exactamente dos fragmentos — `app` (de `AppInfoContributor`, leído desde `firefly.management.info.app.*`) y `build` (de `BuildInfoContributor`, que lee un archivo JSON en `firefly.management.info.build.path`). `env`, `beans`, `conditions`, `mappings`, `loggers` y `scheduledtasks` son cada uno su **propio** `ActuatorEndpoint`, montado en su propio `/actuator/{id}` — no anidado bajo `/info`. `firefly:about` (Capítulo 13) renderiza varios de estos juntos en el terminal, lo cual es una comodidad de ese único comando, no una prueba de que compartan una ruta.

---

## El índice HAL y el contrato de endpoint

Todo endpoint de framework — health, info, metrics, o el tuyo propio — implementa el mismo contrato de tres métodos:

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

```php
final class ActuatorIndexAction
{
    public function __construct(
        private readonly ActuatorRegistry $registry,
        private readonly ExposureModel $exposure,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request): Response
    {
        $base = rtrim($request->getSchemeAndHttpHost().'/'.$this->exposure->basePath, '/');

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

```php
final class SimpleMeterRegistry implements MeterRegistry, MetricsRecorder
{
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
}
```

Esto no es pedantería: el propio formato de texto de Prometheus limita exactamente una declaración `# TYPE` por nombre de métrica, de modo que un nombre registrado como counter y como gauge en algún lugar de tu código haría que `PrometheusTextFormat` emitiera dos líneas `# TYPE` en conflicto para el mismo nombre — una exposición inválida que un recolector rechazaría. `SimpleMeterRegistry` convierte ese error en una `InvalidArgumentException` inmediata en el sitio de llamada responsable, en lugar de en un fallo de recolección descubierto más tarde en producción.

---

## Exposición: el exportador Prometheus a prueba de locale

`PrometheusTextFormat` renderiza cada medidor registrado como texto de formato 0.0.4 en `/actuator/prometheus`. La parte interesante es un método privado de seis líneas que la mayoría de los equipos hacen mal la primera vez que escriben uno:

```php
final class PrometheusTextFormat
{
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
}
```

`sprintf('%f', $value)` es la forma instintiva de formatear un float en PHP, y es *dependiente del locale*: en un host cuyo `LC_NUMERIC` esté configurado a un locale de coma-decimal como `de_DE`, `sprintf('%f', 1.5)` renderiza `"1,500000"` — una coma que la propia gramática del formato de texto de Prometheus no permite dentro de un valor de muestra, produciendo silenciosamente una exposición que un recolector no puede parsear. `number_format($value, 10, '.', '')` toma el punto decimal y el separador de miles como **argumentos explícitos**, de modo que el locale ambiente del proceso nunca puede filtrarse en la salida — la misma clase de pregunta "qué capa controla el formateo" a la que este libro sigue volviendo, aquí respondida una sola vez, en una función, en lugar de en cada sitio de llamada que casualmente imprima un float.

`/actuator/metrics` (`MetricsEndpoint`) expone el mismo registro como JSON con sabor a Micrometer en su lugar — sin subruta lista cada nombre registrado; un nombre concreto se resuelve a sus medidas y tags disponibles, o a un `404` simple si el registro nunca lo ha visto.

---

## Auto-instrumentación: `MetricsFilter` y la cardinalidad acotada

Una vez que `firefly/observability` está instalado y habilitado, cada petición HTTP se cronometra automáticamente mediante `MetricsFilter`, un `WebFilter` `#[Component]` descubierto por la cadena de filtros de `firefly/web` sin ningún cableado tuyo:

```php
#[Component]
#[Order(-100)]
#[ConditionalOnProperty(name: 'firefly.observability.metrics.enabled', havingValue: 'true', matchIfMissing: true)]
#[Lazy]
final class MetricsFilter extends OncePerRequestFilter
{
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
            'exception' => $exception,
        ], microtime(true) - $start);
    }
}
```

Dos detalles hacen esto seguro para producción en lugar de meramente cómodo. `#[Order(-100)]` lo pone en la posición más externa entre los filtros descubiertos, de modo que cronometra la cadena interna *entera* — seguridad, validación, tu controlador — no solo una porción de ella. Y el tag `uri` es siempre la **plantilla** de la ruta coincidente (`/orders/{id}`), nunca la ruta de petición en bruto — una petición sin coincidencia (cualquier `404`) se etiqueta con el centinela fijo `'UNKNOWN'` en lugar de con la ruta controlada por el atacante o el rastreador. Etiquetar por la ruta en bruto permitiría a cualquiera generar un número ilimitado de combinaciones distintas de etiquetas de métrica simplemente golpeando URLs inventadas; bajo Octane, donde el registro es un singleton de por vida del proceso en lugar de uno nuevo por petición, eso no es una verruga cosmética — es un vector de crecimiento de memoria ilimitado. Ante una petición que lanza una excepción, el filtro registra el resultado y **relanza** en lugar de tragársela, de modo que `ProblemDetailsRenderer` todavía puede renderizar el error exactamente como lo haría sin el filtro instalado.

`MeterBindingsPass`, un `BootPass` compañero, registra un puñado de gauges basados en pull de la misma manera: `process_resident_memory_bytes`/`php_memory_peak_bytes` para el proceso en ejecución, y un gauge `resilience_circuit_breaker_state{name}` por cada circuit breaker configurado (`closed=0`, `open=1`, `half_open=2`) — cada uno muestreado *en vivo* en el momento de la recolección mediante una clausura, de modo que una petición `/actuator/prometheus` fresca siempre refleja el estado actual del breaker en lugar de una instantánea del arranque.

---

## La costura de métricas de CQRS: `#[Order(500)]`, una vez más

El Capítulo 7 te dejó con `NoOpCqrsMetrics` enlazado detrás de `#[ConditionalOnMissingBean(CqrsMetrics::class)]`, y un docblock prometiendo que un registrador real caería en su lugar más tarde. `firefly/observability` es ese reemplazo, y el mecanismo ganador es *exactamente* el truco de precedencia que el Capítulo 10 usó para `SecurityCommandAuthorizer`:

```php
#[Configuration]
#[Order(500)]
final class ObservabilityAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.observability.metrics.enabled', havingValue: 'true', matchIfMissing: true)]
    #[ConditionalOnMissingBean(MeterRegistry::class)]
    public function meterRegistry(): MeterRegistry
    {
        return new SimpleMeterRegistry;
    }

    #[Bean]
    #[ConditionalOnMissingBean(CqrsMetrics::class)]
    #[ConditionalOnProperty(name: 'firefly.observability.metrics.enabled', havingValue: 'true', matchIfMissing: true)]
    public function cqrsMetrics(MetricsRecorder $recorder): CqrsMetrics
    {
        return new MeterRegistryCqrsMetrics($recorder);
    }
}
```

`ObservabilityAutoConfiguration` es `#[Order(500)]`, estrictamente por debajo del `#[Order(1000)]` de `CqrsAutoConfiguration`. La pasada incremental de condiciones evalúa primero el `#[Order]` más bajo y registra los supervivientes inmediatamente, de modo que el bean `cqrsMetrics()` de esta clase se registra **primero**; para cuando `CqrsAutoConfiguration` evalúa su propio `#[ConditionalOnMissingBean(CqrsMetrics::class)]`, ya hay un bean enlazado, y el `NoOp` por defecto se retira. Ninguna línea dentro de `firefly/cqrs` cambia — la victoria es puro ordenamiento de auto-configuración, la misma forma que ya has visto dos veces.

`MeterRegistryCqrsMetrics` en sí es el registrador concreto que ese ordenamiento instala — una implementación pequeña y directa del puerto `CqrsMetrics`, que registra cada comando o consulta como un timer etiquetado por tipo de mensaje y resultado:

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

Por último, un puerto `Tracer` remata el paquete — una abstracción mínima de span `trace(string $name, callable $callback): mixed`, entregada hoy solo como `NoOpTracer` (simplemente ejecuta la clausura), escrita contra la interfaz de modo que un adaptador respaldado por OpenTelemetry pueda caer en su lugar más tarde con cero cambios en los sitios de llamada — la idéntica forma "puerto ahora, adaptador después" que ya has visto para el propio `CqrsMetrics`.

!!! laravel "Paridad con Laravel"
    Laravel puro no trae ningún endpoint de comprobación de salud ni de métricas en absoluto — la mayoría de los equipos o bien improvisan una ruta `/health` a mano o recurren a un paquete de terceros, normalmente emparejado con la extensión `ext-prometheus`. `firefly/actuator` y `firefly/observability` son análogos de primera parte y con pocas dependencias de Spring Boot Actuator y Micrometer respectivamente: endpoints de framework montados sobre el mismo `Router` que tu app ya usa, comprobaciones de salud que reutilizan por debajo los propios `DB`/`Log`/config de Laravel, y un exportador Prometheus en PHP puro sin requisito de extensión. Ambos paquetes son dependencias Composer opcionales y ambos son seguros por defecto — una app que añade `firefly/actuator` obtiene `health`/`info` y nada más hasta que configure más.

---

## Lo que aprendiste {.recap}

| Concepto | Qué hace |
|---|---|
| `HealthIndicator` | SPI de un método; un bean `#[Component]` descubierto y agregado automáticamente |
| `Health` / `Status` | Lectura inmutable + un enum ordenado por severidad; DOWN/OUT_OF_SERVICE se mapean ambos a HTTP 503 |
| `PingHealthIndicator` / `DiskSpaceHealthIndicator` / `DbHealthIndicator` | Sondeo de liveness siempre-arriba; comprobación de disco basada en umbral; comprobación de BD `SELECT 1` opcional |
| `HealthEndpoint` | Agrega al estado más severo; un **grupo** de sondeo es solo un subconjunto de indicadores con nombre y configurado — no hay una clase de endpoint liveness/readiness separada |
| `ExposureModel` | Puerta CSV `include`/`exclude`; por defecto `"health,info"`; todo lo demás es un 404 simple hasta que se exponga |
| `EnvEndpoint` | Enmascara las claves `password\|secret\|token\|key\|credential\|passwd` con `******`, independientemente de la exposición |
| `MeterRegistry` / `MetricsRecorder` | Puertos de métricas de lectura/escritura; `SimpleMeterRegistry` es idempotente por `type\|name\|tags` y falla ruidosamente ante un conflicto de tipo |
| `PrometheusTextFormat` | Exposición a prueba de locale — `number_format()`, nunca `sprintf('%f')` |
| `MetricsFilter` | Filtro de cronometraje más externo `#[Order(-100)]`; etiqueta por la **plantilla** de la ruta, nunca la ruta en bruto — cardinalidad acotada |
| `ObservabilityAutoConfiguration` `#[Order(500)]` | El mismo truco de precedencia que la costura de seguridad del Capítulo 10: registra `cqrsMetrics()` antes de que `CqrsAutoConfiguration` evalúe su `#[ConditionalOnMissingBean]` |

---

## Ponlo en práctica {.exercises}

1. **Añade un `HealthIndicator` personalizado.** Escribe un `#[Component]` que implemente `HealthIndicator` que compruebe algo específico de tu propia app (un feature flag, la profundidad de una cola, una conexión de caché) y confirma que aparece en el mapa `components` de `GET /actuator/health` una vez que `show-details` esté configurado a `always`.
2. **Configura una división liveness/readiness real.** Añade `firefly.management.endpoint.health.group.liveness.include = 'ping'` y `...readiness.include = 'ping,db'` (con el indicador de BD habilitado) a la configuración de un proyecto de pruebas, y confirma que `GET /actuator/health/liveness` y `GET /actuator/health/readiness` divergen en el momento en que dejas la base de datos inalcanzable.
3. **Observa a la costura de métricas de CQRS ganar la carrera.** Instala `firefly/observability` en un proyecto de pruebas que ya use `firefly/cqrs`, envía un puñado de comandos, e inspecciona `GET /actuator/prometheus` en busca de muestras de `cqrs_commands_seconds` — luego comenta temporalmente el atributo `#[Order(500)]` de `ObservabilityAutoConfiguration` (revirtiendo al valor por defecto de la clase) y confirma si la métrica todavía aparece, para ver el truco de ordenamiento importar de verdad en lugar de solo leer sobre él.
