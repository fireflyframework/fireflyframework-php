<span class="eyebrow">Apéndice</span>

# Glosario {.chtitle}

**Actuator** — La superficie de gestión de producción de LaraFly: endpoints de framework (`/actuator/health`, `/actuator/info`, `/actuator/env`, y otros) montados directamente sobre el mismo `Router` de Illuminate que usan tus propios controladores, seguros por defecto e inalcanzables hasta que se exponen explícitamente. El análogo de Spring Boot Actuator, entregado por `firefly/actuator` (Capítulo 11).

**Adapter** — Una clase concreta que implementa un puerto delegando en una tecnología específica — un repositorio respaldado por Eloquent, un broker de mensajes respaldado por Kafka. Los adaptadores viven en el borde de la arquitectura hexagonal y pueden intercambiarse sin tocar el código de dominio ni de aplicación (Capítulos 5, 6).

**Aggregate root** — El único punto de entrada a un grupo de objetos de dominio que deben permanecer consistentes juntos. Todos los cambios de estado se enrutan a través de sus métodos, que hacen cumplir las invariantes y registran eventos de dominio; el código externo carga y guarda solo la raíz, nunca sus objetos internos. `Wallet` es la raíz de agregado de Lumen — su única invariante es que el saldo nunca se vuelve negativo (Capítulo 6).

**ApplicationContext** — La fachada del motor de arranque: el objeto que cada prueba y cada consumidor en tiempo de petición resuelve para alcanzar un bean arrancado, un manifiesto compilado, o el propio estado del kernel. `fireflyContext()` es cómo lo alcanza una subclase de `FireflyTestCase` (Capítulos 2, 12).

**Attribute (PHP 8)** — Sintaxis nativa de PHP 8 (`#[Component]`, `#[CommandHandler]`, `#[Transactional]`, …) que LaraFly lee en tiempo de escaneo para descubrir el rol arquitectónico de una clase. Los atributos reemplazan la configuración dirigida por anotaciones que Spring Boot expresa en Java; un manifiesto compilado es lo que hace que leerlos sea un coste único, no por petición (Capítulo 2).

**Authentication** — Un token inmutable que responde "¿quién está haciendo esta petición?" — un principal, sus autoridades concedidas, y si realmente ha sido autenticado. `Authentication::authenticated()`/`unauthenticated()` son sus dos únicos constructores (Capítulo 10).

**Authorization** — La pregunta "¿le está permitido a este principal hacer *esto en concreto*?", respondida independientemente de la autenticación por las reglas de URL de denegar-por-defecto de `HttpSecurity` y por la seguridad de método (`#[PreAuthorize]`, `#[Secured]`, `#[RolesAllowed]`) (Capítulo 10).

**Autoconfiguration** — Una clase `#[Configuration]`, ordenada por `#[Order(N)]`, cuyos métodos de fábrica `#[Bean]` registran los beans por defecto de una capacidad — siempre protegidos por `#[ConditionalOnMissingBean]` de modo que una sobrescritura de la aplicación gana. El `#[Order(500)]` de `ObservabilityAutoConfiguration` ganando la carrera de `CqrsMetrics` antes de que `CqrsAutoConfiguration` evalúe en `#[Order(1000)]` es el ejemplo canónico de que el ordenamiento decide qué valor por defecto sobrevive (Capítulos 2, 7, 10, 11).

**Autowiring** — Resolver los parámetros del constructor de un bean solo a partir de sus tipos declarados, sin código de fábrica escrito a mano. El escaneo de componentes compilado de LaraFly realiza esto una vez, en tiempo de caché, en lugar de por reflexión en cada petición (Capítulo 2).

**Bean** — Cualquier objeto que el contenedor crea, cablea y gestiona. Una clase se convierte en bean llevando un atributo de estereotipo (`#[Component]`, `#[Service]`, `#[Repository]`, `#[Configuration]`), o mediante un método de fábrica con atributo `#[Bean]` dentro de una clase `#[Configuration]` (Capítulo 2).

**BootPass** — Una única unidad ordenada de trabajo en tiempo de arranque — escaneo, evaluación de condiciones, registro de beans, montaje de rutas — ejecutada por el `FireflyKernel` en una `BootPhase` específica. `ActuatorRouteRegistrar` y `MeterBindingsPass` son ambos `BootPass` (Capítulos 2, 11).

**Circuit breaker** — Un patrón de resiliencia que salta a un estado abierto tras un umbral de fallos consecutivos contra una dependencia, cortocircuitando llamadas posteriores hasta que la dependencia haya tenido tiempo de recuperarse. El `MeterBindingsPass` de `firefly/observability` expone el estado en vivo de cada breaker configurado como un gauge `resilience_circuit_breaker_state{name}` (Capítulo 11).

**Command (CQRS)** — Un objeto que expresa una única intención de escritura — "retirar fondos", "abrir una wallet" — despachado a través del `CommandBus` a exactamente un `#[CommandHandler]`. Los comandos pueden ser denegados antes de llegar siquiera a su handler por un `CommandAuthorizer` (Capítulos 7, 10).

**CommandBus** — El pipeline que recibe un `Command`, lo pasa por una secuencia acotada de etapas (correlacionar → validar → autorizar → invocar → métricas), y lo enruta a su único `#[CommandHandler]` registrado (Capítulo 7).

**Component** — El atributo de estereotipo genérico para un bean gestionado que no encaja en los roles más específicos `#[Service]`/`#[Repository]`/`#[Configuration]`. Todos los estereotipos son equivalentes para el contenedor; la distinción es para los lectores humanos y las herramientas (Capítulo 2).

**Condition (`#[ConditionalOn*]`)** — Un atributo (`#[ConditionalOnProperty]`, `#[ConditionalOnBean]`, `#[ConditionalOnMissingBean]`) evaluado durante la pasada de condiciones del pipeline de arranque para decidir si una definición de bean sobrevive. El `#[ConditionalOnProperty]` sin `matchIfMissing` de `DbHealthIndicator` es lo que lo mantiene opcional en lugar de activo por defecto (Capítulos 2, 11).

**Deptrac** — El linter estático de fronteras de arquitectura (`deptrac/deptrac`) que hace cumplir qué paquete puede depender de cuál, como capas declaradas en `deptrac.yaml`. Un recuento de `0` violaciones es parte de la propia definición de terminado de este libro para el código de cada capítulo (Capítulos 2, 11, 13).

**Domain event** — Un registro inmutable de un hecho de negocio que ya ha ocurrido — "wallet abierta", "fondos retirados" — registrado por una raíz de agregado y publicado, tras un commit exitoso, a través de un `ApplicationEventPublisher` (Capítulos 6, 8, 9).

**DTO (Data Transfer Object)** — Un objeto sencillo que transporta datos a través de una frontera de capa — un cuerpo de petición, un subárbol de configuración enlazado con `#[ConfigProperties]` — sin exponer tipos de dominio internos (Capítulos 3, 4).

**Entity** — Un objeto de dominio con una identidad estable que persiste a través de los cambios de estado. El `make:firefly-entity` de LaraFly andamia una clase que extiende `Firefly\Domain\Entity` — no hay ningún atributo `#[Entity]` (Capítulos 6, 13).

**EventListener** — Cualquiera de dos superficies distintas y deliberadamente no relacionadas: `#[AsEventListener]` para el propio despacho de eventos en-proceso de Laravel, y `#[EventListener]` para el bus respaldado por broker de `firefly/eda`, que se suscribe a un *patrón* de tipo de evento en lugar de a una clase de PHP (Capítulo 8).

**Granted authority** — Una única cadena de permiso o rol (`'ROLE_ADMIN'`, `'orders:read'`) llevada por un `Authentication`. `hasRole('X')` se normaliza a una comprobación contra la autoridad concedida `'ROLE_X'` (Capítulo 10).

**Health indicator** — Un SPI de un método (`HealthIndicator::health(): Health`) que un `#[Component]` implementa para reportar si alguna dependencia o recurso está arriba. `HealthEndpoint` agrega cada indicador registrado al único `Status` más severo (Capítulo 11).

**Hexagonal architecture** — Un estilo arquitectónico que coloca la lógica de dominio y de aplicación en el centro, rodeada por puertos (interfaces) que los adaptadores implementan en los bordes. El código de negocio depende solo de un puerto; qué adaptador lo satisface en tiempo de ejecución es una decisión de cableado, nunca de lógica de negocio (Capítulos 1, 5, 6).

**Integration event** — Un evento de dominio que ha cruzado la frontera desde el modelo de dominio en-proceso hacia el bus de EDA respaldado por broker, mediante el puente protegido de después-del-commit presentado junto a CQRS. No es el mismo objeto, ni la misma superficie, que el evento de dominio que lo produjo (Capítulos 7, 8, 9).

**JWT (JSON Web Token)** — Un token portador firmado y caducable que `JwtService` emite y valida. El `JwtService` de LaraFly se niega a arrancar en absoluto con un secreto de firma débil o de marcador de posición, y se niega a aceptar cualquier token que carezca de su claim `exp`, por muy válida que sea su firma (Capítulo 10).

**Manifest** — Una instantánea compilada y cacheable de todo lo que un escáner encontró — rutas, handlers de CQRS, listeners de eventos, reglas de seguridad, métodos transaccionales — escrita en `bootstrap/cache/firefly/` por `firefly:cache` y vuelta a cargar en el arranque sin ninguna reflexión (Capítulos 2, 13).

**Meter** — Una única medida con nombre y etiquetas — un `Counter`, un `Gauge` o un `Timer` — mantenida por un `MeterRegistry`. El registro es idempotente por `type|name|sorted-tags`; volver a registrar un nombre bajo un tipo diferente es un error de programación que el registro rechaza ruidosamente (Capítulo 11).

**Method security** — Autorización declarada por atributos (`#[PreAuthorize]`, `#[Secured]`, `#[RolesAllowed]`) hecha cumplir en el bus de CQRS y en el despachador de controladores por un evaluador de expresiones de lista blanca cerrada que nunca llama a `eval()` (Capítulo 10).

**Outbox pattern** — Una técnica para publicar un evento de dominio de forma fiable junto a la escritura de base de datos que lo produjo: ambos se escriben en la misma transacción local, eliminando la necesidad de un commit de dos fases entre la base de datos y el broker de mensajes (Capítulos 8, 9).

**Pest expectation** — Un matcher personalizado `expect()->extend(...)` que `firefly/testing` instala — `toHavePublished`, `toHaveHandledCommand`, `toBeUp`, `toHaveRecordedMetric`, `toBeProblemDetails` — registrado una vez desde el `tests/Pest.php` raíz del monorepo (Capítulo 12).

**Port** — Una interfaz de la que depende una pieza de lógica de negocio, sin ningún detalle de implementación adjunto. `EventPublisher`, `CommandBus`, `HealthIndicator` y `MeterRegistry` son todos puertos; el adaptador concreto que satisface a cada uno es una decisión de cableado (Capítulos 1, 5, 11).

**Principal** — El "quién" de una petición — el `mixed $principal` que lleva un `Authentication` una vez autenticado, junto con sus autoridades concedidas (Capítulo 10).

**Problem Details (RFC 7807)** — La forma de error estándar `application/problem+json` (`type`, `title`, `status`, más claves de extensión específicas del framework como `code`/`category`) como la que renderiza cada excepción sin manejar. `ProblemDetailsRenderer` es lo que la produce (Capítulos 4, 12).

**Projection** — Un modelo de lectura construido consumiendo un flujo de eventos de dominio, mantenido separado del modelo de escritura que los produjo — el libro mayor de Lumen es una proyección sobre los propios eventos de dominio de `Wallet` (Capítulo 6).

**Proxy (`#[Transactional]` proxy)** — Una subclase generada que envuelve un método con atributo `#[Transactional]` con fronteras de transacción declarativas (`beginTransaction()`/`commit()`/`rollBack()`), producida por el mismo `ProxyClassGenerator` sin reflexión ya sea en tiempo de arranque (dev) o de forma anticipada por `firefly:cache` (producción) (Capítulos 9, 13).

**Query (CQRS)** — Un objeto que expresa una intención de lectura — "obtener el saldo de la wallet" — despachado a través del `QueryBus` a exactamente un `#[QueryHandler]`. Las consultas nunca mutan el estado (Capítulo 7).

**QueryBus** — La contraparte del lado de lectura de `CommandBus`: recibe una `Query`, la enruta a su `#[QueryHandler]`, y (el pipeline del Capítulo 7) puede servir un resultado cacheado de forma transparente.

**Recording double** — Una implementación sencilla de un puerto real de Firefly que graba lo que vio, de modo que una prueba puede afirmar sobre el comportamiento — `RecordingEventPublisher`, `RecordingCommandBus`, y ocho hermanos vienen en `firefly/testing`, uno por cada puerto que los propios `Event::fake()`/`Bus::fake()` de Laravel no pueden ver (Capítulo 12).

**Repository** — Una abstracción similar a una colección sobre la persistencia, que permite al código de aplicación cargar y guardar agregados o entidades sin ningún SQL propio. `CrudRepository` es la base tipada que extiende cada repositorio de dominio (Capítulo 5).

**Secure by default** — La postura operativa de LaraFly allí donde aplica: un endpoint de actuator es inalcanzable (404) hasta que se expone explícitamente, una regla de URL de `HttpSecurity` deniega cualquier cosa sin coincidencia, y un secreto JWT que parece débil se niega a arrancar en absoluto — el sistema falla cerrado, no abierto (Capítulos 10, 11).

**SecurityContext** / **SecurityContextHolder** — Una instantánea inmutable del `Authentication` actual, mantenida por petición por un accesor estático respaldado por la propia fachada `Context` de Laravel. Cada filtro de autenticación la limpia en un bloque `finally` de modo que el principal de una petición nunca pueda filtrarse a la siguiente (Capítulo 10).

**Service** — Un bean gestionado, con atributo `#[Service]`, que alberga lógica de negocio y orquesta llamadas a repositorios, publicadores de eventos y otros servicios (Capítulo 2).

**Slice test** — Una prueba que arranca solo los beans que necesita una estrecha vertical — el pipeline web sobre un controlador (`WebSliceTestCase`), o el pipeline de datos sobre un repositorio (`DataSliceTestCase`) — en lugar de toda la aplicación, de modo que una rebanada mal cableada falla rápido en lugar de pasar por accidente (Capítulo 12).

**Stereotype** — Un atributo que a la vez registra una clase como bean y señala su rol arquitectónico — `#[Service]`, `#[Repository]`, `#[Component]`, `#[Configuration]`, `#[RestController]`. Cada estereotipo es una especialización del `#[Component]` base (Capítulo 2).

**Testcontainers** — Una biblioteca que arranca contenedores Docker reales para pruebas de integración y los desmonta después. El trait `RequiresDocker` y el helper `fireflyConfigFor()` de `firefly/testing` hacen que una prueba respaldada por testcontainers sea opcional y segura para CI incluso sin Docker presente (Capítulo 12).

**Transactional (`#[Transactional]`)** — Un atributo de clase/método que declara propagación (`REQUIRED`, `REQUIRES_NEW`, `NESTED`, …), aislamiento, solo-lectura y reglas de rollback — hecho cumplir por un proxy generado en lugar de una clausura manual `DB::transaction()` (Capítulo 9).

**Value object** — Un objeto de dominio inmutable identificado por su valor en lugar de por un campo de identidad; dos objetos de valor son iguales si todos sus campos son iguales. `Money` — un importe entero en unidades menores más una `Currency` — es el objeto de valor canónico de Lumen (Capítulo 6).

**Zero-reflection boot** — El estado que una aplicación alcanza una vez que `firefly:cache` ha ejecutado: cada manifiesto se carga desde un archivo PHP precompilado bajo `bootstrap/cache/firefly/`, de modo que ningún atributo, clase o método vuelve a someterse a reflexión en tiempo de petición (Capítulo 13).
