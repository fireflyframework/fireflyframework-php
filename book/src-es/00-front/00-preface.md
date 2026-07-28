## Prefacio

El PHP empresarial ha significado durante mucho tiempo ensamblar un contenedor de servicios de un paquete, un enrutador de otro, un validador de un tercero y un despachador de eventos de un cuarto — cada uno con su propio idioma de configuración, sus propias convenciones, su propia manera de decir "inyecta esto". Laravel resolvió gran parte de esa fragmentación para las aplicaciones web, pero el PHP hexagonal y orientado a servicios — inyección de dependencias basada en atributos, transacciones declarativas, CQRS, arquitectura orientada a eventos, seguridad a nivel de método, observabilidad de producción — ha seguido siendo cuestión de gustos, convenciones de nombres de carpetas y pegamento hecho a mano.

**LaraFly** cambia eso. Lleva a PHP 8.3+ y Laravel 13 la experiencia cohesionada de convención sobre configuración que Spring Boot dio al mundo Java, construida *sobre* Laravel en lugar de en su lugar: el mismo Eloquent, el mismo contenedor de servicios, el mismo `artisan`, ahora organizados mediante atributos de PHP 8 en componentes con estereotipo, un grafo de dependencias compilado y una tubería de arranque determinista.

Este libro enseña LaraFly **con ejemplos**. Cada listado de estas páginas no es pseudocódigo ilustrativo: está tomado del propio código fuente distribuido del framework o de `samples/lumen`, una aplicación real y en funcionamiento de monedero digital y libro mayor que se distribuye en el monorepo del framework, que compila, arranca y supera su propia batería de pruebas. Lo que lees es lo que realmente funciona.

### Para quién es este libro

Este libro es para desarrolladores PHP de nivel intermedio que se sienten cómodos con los fundamentos de Laravel — modelos Eloquent, enrutamiento, el contenedor de servicios, `artisan` — y que quieren ver cómo se construye una arquitectura hexagonal de nivel empresarial *sobre* esa base, en lugar de en su lugar. No necesitas experiencia previa con Spring, CQRS ni arquitectura orientada a eventos: cada idea se presenta desde sus fundamentos antes del código que la implementa.

Los desarrolladores que llegan desde Spring Boot, Micronaut o Quarkus se sentirán especialmente como en casa. Allí donde un concepto de LaraFly refleja uno nativo de Laravel — un `#[Service]` junto a una clase corriente vinculada en un proveedor de servicios, un `#[RestController]` junto a un controlador Laravel normal — una nota de **paridad con Laravel** traza la comparación de forma explícita, para que puedas mapear lo que ya sabes sobre lo que es nuevo.

### Lo que vas a construir

Cada capítulo hace avanzar **Lumen**, un servicio de monedero digital y libro mayor: un `Wallet` puede abrirse, recibir depósitos, sufrir retiradas y transferirse a otros monederos, protegiendo un único invariante por encima de todos los demás — **el saldo nunca es negativo** — y registrando cada cambio de estado como un evento de dominio que un escuchador proyecta en un libro mayor de solo anexado. Es un sistema pequeño, pero tiene exactamente la forma de uno real: una capa de dominio sin dependencia alguna del framework, un puerto hexagonal y su adaptador Eloquent, manejadores de comando y consulta de CQRS, eventos de dominio conectados a un bus de eventos, seguridad a nivel de método en una operación sensible, y un controlador REST delgado que no contiene lógica de negocio propia.

El recorrido empieza con suavidad. El **Inicio rápido** te lleva desde un `composer create-project` vacío hasta un endpoint en ejecución y consultable con curl, previendo en miniatura los estereotipos, el contenedor y la ruta de arranque compilada antes de que ningún capítulo te pida razonar sobre ellos. El **Capítulo 1** da un paso atrás y argumenta el enfoque completo — qué problema resuelve LaraFly y sobre qué pilares se sostiene. El **Capítulo 2** abre la sala de máquinas: el contenedor de inyección de dependencias, los atributos de estereotipo y el escaneo de componentes que compila tus clases anotadas en un manifiesto de arranque en caché, sin reflexión. Las partes posteriores de este libro — que llegarán en los capítulos siguientes — construyen hacia afuera desde esa base hacia la configuración, HTTP, la persistencia, el modelado de dominio, CQRS, la arquitectura orientada a eventos, la seguridad y la observabilidad, siempre a través de la misma base de código de `Lumen`, siempre con código que puedes ejecutar.

Cuando hayas terminado de trabajar todo el libro, tendrás un modelo mental de cada capa de un servicio LaraFly de producción, y una aplicación real y probada que lo demuestre.

### Cómo usar este libro

**Lee de forma secuencial.** Cada capítulo se apoya en el vocabulario y el código del anterior.

**Escribe tú mismo los listados.** Leer y teclear el código a la vez es la manera de que los patrones se asienten. Resiste la tentación de copiar y pegar hasta que hayas escrito cada listado al menos una vez a mano.

**Ejecútalo.** Todos los listados de este libro son reales, y la mayoría vive, sin cambios, en el propio paquete `samples/lumen` del framework. Clona el repositorio del framework y podrás ejecutar la aplicación terminada — `composer test -- samples/lumen/tests` ejercita toda la batería de pruebas del ejemplo — y comparar tu propio trabajo con ella en cualquier momento.

Cada capítulo cierra con un breve **Resumen** de lo aprendido y, cuando corresponde, una sección de **Ponlo en práctica** que te lleva un paso más allá por tu cuenta.

### Convenciones en breve

Las convenciones tipográficas y estructurales —el estilo de los listados de código, los tipos de recuadro y la numeración de las figuras— se demuestran, con ejemplos en vivo, en la sección **Convenciones** que sigue a este prefacio.

### El código de acompañamiento

La aplicación `Lumen` terminada vive en el monorepo del framework, en `samples/lumen`. Es el destino al que este libro te conduce: un proyecto por capas —`Domain`, `Application`, `Infrastructure`, `Web`— que los capítulos posteriores de este libro hacen crecer, funcionalidad a funcionalidad. Úsala para comparar tu propio código, para ponerte al día si un capítulo avanza más rápido de lo esperado, o simplemente para ejecutar las partes sobre las que estás leyendo en cada momento.
