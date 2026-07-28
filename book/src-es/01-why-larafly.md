<span class="eyebrow">Parte I — Fundamentos · Capítulo 1</span>

# ¿Por qué LaraFly? {.chtitle}

Al terminar este capítulo entenderás el problema que LaraFly existe para resolver, los siete pilares sobre los que descansa su arquitectura, y cómo cada uno se construye sobre Laravel en lugar de sustituirlo — de modo que cuando el Capítulo 2 abra el contenedor de inyección de dependencias, ya sabrás *por qué* funciona como funciona.

---

## El problema de la cohesión

Imagina el primer día de un nuevo microservicio PHP. El propio Laravel responde a la mayoría de las preguntas obvias — enrutamiento, el ORM, el contenedor de servicios, la cola — y las responde bien. Pero en el momento en que el servicio necesita ser algo más que una aplicación CRUD-y-Blade, aparece una segunda capa de preguntas, y Laravel las deja deliberadamente en tus manos.

¿Cómo mantienes las reglas de negocio fuera de tus modelos Eloquent y de tus controladores? ¿Dónde vive un manejador de comando, y cómo se despacha? ¿Cómo garantizas que una escritura en base de datos y el evento de dominio que provoca ocurran ambos o ninguno? ¿Cómo publicas ese evento a otros servicios sin fabricar a mano un trabajo de cola para cada uno? ¿Cómo bloqueas una operación concreta a un rol concreto, independientemente del middleware de ruta? ¿Cómo sabes, en producción, si el servicio está realmente sano?

Cada equipo responde estas preguntas de forma distinta — una convención de nombres de carpetas por aquí, una clase base de repositorio por allá, una capa de "servicio" hecha en casa sin forma compartida de un proyecto al siguiente. Seis meses después, un segundo equipo empieza un segundo servicio y toma decisiones completamente distintas. Ahora dos bases de código comparten un lenguaje y un framework pero nada más: estrategias de pruebas distintas, convenciones de manejo de errores distintas, ningún modelo mental compartido de cómo está conectado nada.

**Laravel le da a PHP una base excelente. Por sí mismo, no te da una arquitectura compartida para la capa que va encima de esa base.**

Los desarrolladores Java resolvieron el problema equivalente hace años con Spring Boot: un único framework cohesionado y con opiniones, construido *sobre* la JVM en lugar de sustituirla, que toma las decisiones arquitectónicas difíciles una sola vez y deja que cada equipo comparta la misma respuesta. **LaraFly lleva esa misma disciplina a PHP, construida sobre Laravel 13.**

---

## Qué es LaraFly

LaraFly no es un sustituto de Laravel — es un framework construido *sobre* él, de la misma manera que Spring Boot está construido sobre la JVM y las convenciones planas de Java EE. Toda aplicación LaraFly es una aplicación Laravel real: los mismos modelos Eloquent, el mismo contenedor de servicios por debajo, la misma línea de comandos `artisan`, la misma tubería de middleware y kernel HTTP. Lo que LaraFly añade es una segunda capa, de nivel más alto, de **atributos** de PHP 8 que describen *intención* — "esta clase es un servicio", "este método debe ejecutarse dentro de una transacción", "este manejador de comando requiere el rol `ADMIN`" — y un motor en tiempo de arranque que lee esos atributos una sola vez, los compila en un manifiesto y conecta la aplicación resultante de forma determinista.

Ya viste una pequeña muestra de esto en el Inicio rápido: `#[Service]` en una clase corriente bastó para hacerla inyectable, sin ninguna conexión manual. Ese único atributo es el ejemplo más pequeño de un patrón que se repite, con mayor profundidad, a lo largo del resto de este libro: **declara qué es una clase, y deja que el framework averigüe qué hacer con ella.**

!!! laravel "Paridad con Laravel"
    Nada en LaraFly te pide que abandones lo que ya sabes. Un bean `#[Service]` sigue siendo, al final, un objeto corriente que resuelve el contenedor de Laravel; la ruta de un `#[RestController]` sigue ejecutándose a través del propio kernel HTTP y middleware de Laravel; `#[Transactional]` (Capítulo 8) sigue llamando por debajo a los propios `DB::beginTransaction()`/`commit()`/`rollBack()` de Laravel. Los atributos de LaraFly describen *qué conectar*; el trabajo real de la conexión lo sigue haciendo Laravel.

---

## Siete pilares

La arquitectura de LaraFly descansa sobre siete ideas. Cada una es un paquete real e independiente en el monorepo del framework — ninguna de ellas requiere las demás — y cada una es el tema de uno o más capítulos posteriores.

### 1. DI y auto-configuración basadas en atributos

`#[Service]`, `#[Repository]`, `#[Component]` y `#[Configuration]`/`#[Bean]` marcan una clase o un método fábrica como parte gestionada de la aplicación. Un **escaneo de componentes** — un único paso de reflexión — descubre cada uno de ellos y compila el resultado en un manifiesto PHP en caché. En tiempo de ejecución no se vuelve a escanear nada: el contenedor se construye a partir de ese manifiesto congelado, que es lo que hace que LaraFly sea seguro y rápido bajo workers de larga duración, no solo bajo el clásico PHP-FPM. Este es el tema completo del Capítulo 2.

### 2. `#[Transactional]` — demarcación declarativa de transacciones

Un método que lleva `#[Transactional]` obtiene un proxy generado, construido en tiempo de compilación, que abre una transacción de base de datos real de Laravel antes de que el método se ejecute y la confirma o la revierte después — usando `DB::beginTransaction()`/`commit()`/`rollBack()` manuales en lugar de `DB::transaction($closure)`, específicamente para que una excepción capturada pueda confirmarse-y-relanzarse cuando tus propias reglas de reversión digan que debe hacerlo, en lugar de revertirse siempre. Tú escribes el método de negocio; el proxy lleva el límite transaccional.

### 3. CQRS — comandos, consultas y un bus

Las escrituras y las lecturas viajan por dos caminos separados. Un `#[CommandHandler]` maneja una intención de escritura; un `#[QueryHandler]` responde una lectura; ambos se despachan a través de un bus en lugar de llamarse directamente. Un manejador de comando `#[Transactional]` obtiene su semántica transaccional gratis del mismo proxy que describe el pilar 2 — CQRS no añade lógica de interceptación propia.

### 4. Arquitectura orientada a eventos, con un outbox genuino

Un agregado emite eventos de dominio que describen lo que ocurrió; tras una confirmación, esos eventos pueden proyectarse en el mismo proceso o conectarse hacia afuera al `EventPublisher`, respaldado por bróker, de `firefly/eda` — en memoria para las pruebas, una cola de Laravel para entrega asíncrona, o un bróker de mensajes real (Kafka, RabbitMQ, o `LISTEN`/`NOTIFY` de Postgres) para entrega entre servicios. El adaptador de Postgres en particular escribe la fila del outbox en la *misma* transacción de base de datos que el propio agregado, de modo que un evento nunca puede registrarse sin que la escritura que describe se haya confirmado realmente.

### 5. Seguridad

Un modelo de principal con la forma de Spring Security 6 — una `Authentication` inmutable, un `SecurityContext`, `GrantedAuthority` — respalda una configuración de URL `HttpSecurity` que deniega por defecto y guardas a nivel de método como `#[PreAuthorize]`, aplicadas en el bus de CQRS y en el despacho del controlador, independientemente del middleware de ruta.

### 6. Actuator — una superficie de gestión de producción

`/actuator/health`, `/actuator/info` y similares, modelados directamente sobre Spring Boot Actuator: un SPI `HealthIndicator`, grupos de sondas de liveness/readiness, y un paso de registro de rutas que monta estos endpoints sin cambios de código en tu propia aplicación — solo la configuración decide qué se expone.

### 7. Arranque sin reflexión

Cada uno de los seis pilares anteriores se descubre por reflexión **exactamente una vez** — en el momento de `firefly:cache`, no en el momento de cada petición. Los manifiestos compilados que produce ese escaneo son de donde realmente arranca una aplicación en ejecución. Este es el hilo que une todo el framework, y es donde empieza el Capítulo 2.

::: figure art/figures/boot-pipeline.svg | Figura 1.1 — Cada paquete de Firefly aporta un paso de arranque; solo el kernel decide el orden en que se ejecutan.

---

## Por qué construir Lumen

El resto de este libro enseña estos siete pilares construyendo una única aplicación real, **Lumen**, un servicio de monedero digital y libro mayor — el mismo que entreviste al final del Inicio rápido. Un monedero puede abrirse, recibir depósitos, sufrir retiradas y transferirse a otros monederos, y protege exactamente una regla por encima de todas las demás: **el saldo nunca es negativo**. Esa única regla es lo bastante pequeña como para retenerla por completo en la cabeza, lo que deja tu atención libre para cómo se construye cada pilar, en lugar de para qué significa el dominio.

Cada listado que leerás a partir de ahora no es ilustrativo: está copiado del propio paquete `samples/lumen` que se distribuye en el monorepo del framework, y se valida con el linter, compila y supera su propia batería de pruebas exactamente como aparece impreso.

!!! note "Nota"
    Lumen ya existe, completamente construido, en `samples/lumen` — este libro no lo modifica. En cambio, cada capítulo *explica* una porción más de él, en el orden que hace más claras las ideas subyacentes, de modo que cuando hayas leído todo el libro entiendas cada línea de un servicio real con forma de producción.

---

## Lo que aprendiste {.recap}

| Pilar | Qué te da | Dónde se cubre |
|---|---|---|
| DI y auto-configuración basadas en atributos | Beans con estereotipo, inyección por constructor, un manifiesto compilado | **Capítulo 2** |
| `#[Transactional]` | Límites transaccionales declarativos mediante un proxy generado | Capítulos posteriores |
| CQRS | Un bus de comandos/consultas que separa escrituras de lecturas | Capítulos posteriores |
| EDA + outbox genuino | Eventos de dominio conectados a entrega en memoria, cola o bróker | Capítulos posteriores |
| Seguridad | Seguridad HTTP que deniega por defecto + guardas a nivel de método | Capítulos posteriores |
| Actuator | Una superficie de salud/información/gestión de producción | Capítulos posteriores |
| Arranque sin reflexión | Un paso de compilación, y después un manifiesto congelado en tiempo de ejecución | **Capítulo 2** |

---

## Ponlo en práctica {.exercises}

1. **Lee la propuesta de un tirón.** Repasa `samples/lumen/README.md` de principio a fin. Para cada punto bajo "What the sample shows", identifica a cuál de los siete pilares anteriores pertenece.
2. **Encuentra la costura.** Abre de nuevo `skeleton/config/firefly.php` y localiza `scan.paths`. Cada uno de los siete pilares depende, en última instancia, de que ese único array sea correcto — escribe, con tus propias palabras, qué pasaría si apuntara al namespace equivocado.
3. **Compara arquitecturas.** Si alguna vez has construido un servicio en Laravel puro, enumera tres decisiones arquitectónicas que tomaste por convención (dónde viven los comandos, cómo manejaste las transacciones, cómo aseguraste un endpoint sensible). Revisarás cada una de ellas, construida a la manera LaraFly, en un capítulo posterior.
