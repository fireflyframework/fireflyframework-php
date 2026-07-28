<span class="eyebrow">Parte I — Fundamentos · Capítulo 3</span>

# Configuración, Perfiles y Secretos {.chtitle}

Al terminar este capítulo sabrás cómo `Config` de LaraFly envuelve el propio repositorio de configuración de Laravel con accesores tipados y de fallo rápido, cómo `#[ConfigProperties]` vincula todo un subárbol de configuración sobre un DTO tipado e inyectable, cómo `#[Value]` resuelve un escalar individual una vez instalado `firefly/config`, cómo se resuelven los perfiles activos y cómo se puede condicionar un bean a uno de ellos y — cerrando el círculo con el Capítulo 2 — exactamente cómo viaja un DTO `#[ConfigProperties]` desde el atributo de origen hasta un manifiesto compilado, sin reflexión.

!!! note "Término nuevo: subárbol de configuración"
    Un **subárbol** es todo lo anidado bajo una clave con puntos en el array de configuración de Laravel — `mail.host`, `mail.port` y `mail.tls` forman parte del subárbol `mail`. `#[ConfigProperties]` vincula un subárbol entero sobre un DTO en un solo paso, en vez de leer cada clave a mano.

---

## La configuración sigue siendo la configuración de Laravel

LaraFly no sustituye el sistema de configuración de Laravel — se apoya en él. Cada archivo `config/*.php`, cada llamada a `env('...')`, cada variable de `.env` que ya conoces de una aplicación Laravel corriente funciona exactamente igual en una aplicación LaraFly. El trabajo de `firefly/config` es más concreto y acotado: te da un **accesor tipado y de fallo rápido** sobre ese mismo repositorio, una forma de **vincular un subárbol entero sobre un DTO**, y un **resolutor respaldado por configuración** para el atributo `#[Value]` que introdujo el Capítulo 2.

Ya viste el ejemplo más pequeño posible de esto en el Inicio rápido. `app/GreetingProperties.php`, generado por `composer create-project firefly/skeleton`, es un DTO `#[ConfigProperties]` real y distribuido:

```php
<?php

declare(strict_types=1);

namespace App;

use Firefly\Config\Attributes\ConfigProperties;

/**
 * Binds the `greeting.*` configuration subtree onto this readonly DTO and registers it as a container
 * singleton, so it can be constructor-injected wherever GreetingProperties is requested.
 */
#[ConfigProperties('greeting')]
final readonly class GreetingProperties
{
    public function __construct(public string $salutation = 'Hello') {}
}
```

Este capítulo explica, en profundidad, todo lo que ese pequeño archivo hace silenciosamente por ti.

---

## Acceso tipado con `Config`

Antes de recurrir a un DTO, conviene ver la capa que hay justo debajo. `Firefly\Config\Config` envuelve `Illuminate\Contracts\Config\Repository` — el mismo repositorio de configuración del que lee `config()` en una aplicación Laravel corriente — con cuatro getters tipados: `string()`, `int()`, `bool()` y `array()`. Cada uno es de **fallo rápido**: una clave requerida (sin valor por defecto) que falta, o un valor que no se puede convertir al tipo solicitado, lanza `ConfigurationException` de inmediato, en vez de entregarte `null` o un tipo silenciosamente incorrecto tres marcos de llamada después.

```php
$config->string('mail.host');          // required — throws ConfigurationException if absent
$config->int('mail.port', 25);         // falls back to 25 when the key is absent
$config->bool('mail.tls', false);
$config->array('mail.recipients', []);
```

`Config` está registrado como singleton del contenedor, así que se inyecta por constructor en cualquier parte:

```php
use Firefly\Config\Config;
use Firefly\Container\Attributes\Service;

#[Service]
final class MailerBootstrap
{
    public function __construct(private readonly Config $config) {}

    public function host(): string
    {
        return $this->config->string('mail.host', 'localhost');
    }
}
```

!!! note "Nota"
    `Config::has()` y `Config::get()` se comportan exactamente igual que `Repository::has()`/`Repository::get()` de Laravel — `Config` añade envoltorios tipados y de fallo rápido alrededor de ellos, no sustituye ni reinterpreta la resolución por notación de puntos de Laravel.

`string()`/`int()`/`bool()`/`array()` bastan para lecturas ocasionales. No escalan más allá de un puñado de llamadas dispersas: cada una es una consulta aislada sin información de tipo compartida, un error tipográfico en una cadena de clave solo aflora cuando se ejecuta esa línea exacta, y un servicio que necesita cinco ajustes relacionados termina con cinco llamadas separadas a `$config->...()` esparcidas por el cuerpo de su constructor. `#[ConfigProperties]` resuelve justamente esto.

---

## `#[ConfigProperties]`: vincular un subárbol sobre un DTO tipado

`#[ConfigProperties(string $prefix)]` es un atributo de nivel de clase (`Firefly\Config\Attributes\ConfigProperties`). Nombra el prefijo de configuración con puntos del que se vincula la clase; el framework lee ese subárbol, empareja cada clave con un parámetro del constructor por nombre, convierte los escalares al tipo declarado, y registra la instancia resultante como singleton del contenedor — de modo que cualquier bean que pida el tipo del DTO recibe la configuración vinculada, completamente tipada, sin una sola llamada a `config('...')` en su propio cuerpo.

Aplicar el mismo patrón a un ajuste de negocio es tan corto como lo fue `GreetingProperties`. Supongamos que Lumen quiere un límite diario de transferencia configurable:

```php
<?php

declare(strict_types=1);

namespace Lumen\Domain;

use Firefly\Config\Attributes\ConfigProperties;

/**
 * Binds the `wallet.*` configuration subtree — a per-day transfer ceiling in minor units and the
 * default currency assigned to a wallet opened without one — onto this readonly DTO.
 */
#[ConfigProperties('wallet')]
final readonly class WalletProperties
{
    public function __construct(
        public int $dailyTransferLimitMinor = 1_000_000,
        public string $defaultCurrency = 'EUR',
    ) {}
}
```

y el archivo de configuración correspondiente, `config/wallet.php`:

```php
<?php

declare(strict_types=1);

return [
    'daily_transfer_limit_minor' => env('WALLET_DAILY_TRANSFER_LIMIT_MINOR', 1_000_000),
    'default_currency' => env('WALLET_DEFAULT_CURRENCY', 'EUR'),
];
```

`TransferHandler` (los Capítulos 4 y 6 construyen el resto) ya puede depender directamente de `WalletProperties`:

```php
use Firefly\Container\Attributes\Service;
use Lumen\Domain\WalletProperties;

#[Service]
final class TransferLimitGuard
{
    public function __construct(private readonly WalletProperties $properties) {}

    public function isWithinDailyLimit(int $amountMinor): bool
    {
        return $amountMinor <= $this->properties->dailyTransferLimitMinor;
    }
}
```

Nada aquí llama a `config('wallet.daily_transfer_limit_minor')`. `WalletProperties` es un objeto tipado y autodocumentado — los propios valores por defecto del constructor (`1_000_000`, `'EUR'`) hacen las veces de documentación viva de lo que significa el ajuste cuando nada lo sobrescribe, y un error tipográfico en el nombre de un parámetro del constructor es un error de PHP en el momento del vínculo, no un campo silenciosamente `null` descubierto en producción.

!!! laravel "Paridad con Laravel"
    `#[ConfigProperties]` es el equivalente en LaraFly de `@ConfigurationProperties` de Spring Boot. En una aplicación Laravel corriente, lo más parecido es escribir tu propio DTO y rellenarlo a mano desde `config('wallet')` en el método `register()` de un proveedor de servicios; `#[ConfigProperties]` es esa misma idea sin el código repetitivo — el prefijo es lo único que declaras, y el vinculador hace el resto.

### Cómo funciona realmente el vínculo: `ReflectionConfigBinder`

El vinculador por defecto, `Firefly\Config\Binder\ReflectionConfigBinder`, resuelve un parámetro del constructor a la vez a partir del array del subárbol vinculado:

```php
final class ReflectionConfigBinder implements ConfigBinder
{
    private function resolveParameter(string $class, ReflectionParameter $parameter, array $config): mixed
    {
        $name = $parameter->getName();
        $value = array_key_exists($name, $config) ? $config[$name] : null;

        if ($value === null) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }
            if ($parameter->allowsNull()) {
                return null;
            }

            throw new ConfigurationException("Missing required configuration property [{$name}] for {$class}.");
        }

        $type = $parameter->getType();
        if (! $type instanceof ReflectionNamedType) {
            return $value;
        }

        return $this->coerce($type, $value);
    }
}
```

De ese cuerpo de método se desprenden tres reglas directamente. Una clave ausente con un valor por defecto en el constructor usa silenciosamente ese valor por defecto — precisamente por qué el constructor de `WalletProperties` escribe explícitamente `1_000_000` y `'EUR'` en vez de dejarlos obligatorios. Una clave ausente **sin** valor por defecto y con un tipo no anulable lanza `ConfigurationException` de inmediato, en el momento del vínculo — no la primera vez que un manejador toca el campo. Y cuando un parámetro del constructor está tipado como otra clase (no un escalar) y el subárbol contiene un array bajo esa clave, el vinculador **recurre**: se llama a sí mismo sobre la clase anidada y el array anidado, de modo que un DTO `#[ConfigProperties]` puede componer DTOs más pequeños sin cableado adicional.

La conversión de escalares es igual de mecánica — los campos `int`/`float`/`bool` se convierten a partir de lo que sea que el valor de configuración realmente sea (útil cuando un valor llegó desde una variable de entorno como cadena), y `string` acepta cualquier cosa escalar:

```php
final class ReflectionConfigBinder implements ConfigBinder
{
    private function coerce(ReflectionNamedType $type, mixed $value): mixed
    {
        if ($type->isBuiltin()) {
            return match ($type->getName()) {
                'int' => is_numeric($value) ? (int) $value : $value,
                'float' => is_numeric($value) ? (float) $value : $value,
                'bool' => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'string' => is_scalar($value) ? (string) $value : $value,
                default => $value,
            };
        }

        $nested = $type->getName();
        if (is_array($value) && class_exists($nested)) {
            return $this->bind($nested, $value);
        }

        return $value;
    }
}
```

!!! tip "El vinculador es un punto de extensión, no la única implementación"
    `ReflectionConfigBinder` implementa una interfaz de un solo método, `Firefly\Config\Binder\ConfigBinder`. Una aplicación (o un futuro paquete de Firefly) puede vincular un vinculador más rico sobre el contenedor — uno que entienda enums, u objetos de valor con su propia validación — sin tocar una sola clase `#[ConfigProperties]`. Los DTOs que escribes nunca saben, ni necesitan saber, qué vinculador los rellenó realmente.

### Del atributo al manifiesto compilado — la misma historia que el Capítulo 2

`#[ConfigProperties]` sigue exactamente la misma forma "reflexionar una vez, congelar en un manifiesto, arrancar desde la copia congelada" que el Capítulo 2 recorrió para el contenedor. `ConfigPropertiesScanner::scan()` recorre tus raíces PSR-4, reflexiona cada clase no abstracta, y registra un `ConfigPropertiesDescriptor` — solo el nombre de la clase y el prefijo — por cada una que lleve `#[ConfigProperties]`:

```php
foreach ($this->classesIn($prefix, $dir) as $class) {
    $reflection = new ReflectionClass($class);
    if ($reflection->isAbstract() || $reflection->isInterface()) {
        continue;
    }
    $attrs = $reflection->getAttributes(ConfigProperties::class);
    if ($attrs === []) {
        continue;
    }
    $descriptors[] = new ConfigPropertiesDescriptor($class, $attrs[0]->newInstance()->prefix);
}
```

`ConfigManifestCompiler` escribe esa lista en `bootstrap/cache/firefly/config-properties.php` con la misma técnica basada en `var_export()`, sin reflexión, que usó el manifiesto de componentes. `php artisan firefly:cache` ejecuta este escaneo como uno de los doce pares escáner/compilador que orquesta — nunca invocas `ConfigPropertiesScanner` tú mismo.

En el arranque, `Firefly\Cli\Boot\FireflyCacheServiceProvider` carga ese manifiesto compilado y se lo entrega directamente a `Firefly\Config\Registrar\ConfigRegistrar::register()`, que vincula el DTO de cada descriptor como singleton del contenedor en un solo paso:

```php
foreach ($manifest->properties as $descriptor) {
    $class = $descriptor->class;
    $prefix = $descriptor->prefix;

    $this->container->singleton($class, static function () use ($binder, $class, $prefix, $config): object {
        $subtree = $config->array($prefix, []);

        return $binder->bind($class, $subtree);
    });
}
```

Esta es la razón completa por la que `GreetingProperties` y `WalletProperties` no necesitan ningún cableado de proveedor de servicios en ninguna parte de tu aplicación: el manifiesto compilado ya conoce cada clase `#[ConfigProperties]` y su prefijo, y `ConfigRegistrar` los vincula todos, incondicionalmente, en el momento en que existe la caché.

!!! warning "La caché también importa aquí"
    Exactamente como explicó el Capítulo 2 para los beans, el vínculo de `#[ConfigProperties]` se impulsa desde el manifiesto **compilado** — ejecuta `php artisan firefly:cache` después de añadir o renombrar una clase `#[ConfigProperties]`, igual que harías tras añadir un nuevo `#[Service]`.

---

## `#[Value]` desde configuración real

El Capítulo 2 introdujo `#[Value]` sobre un parámetro de constructor desnudo — `#[Value('${MAIL_HOST:localhost}')]` — y mencionó que el propio resolutor de `firefly/container` solo entiende variables de entorno y expresiones pequeñas. Instalar `firefly/config` mejora esa resolución sin tocar un solo sitio `#[Value]` en tu código:

```php
use Firefly\Container\Attributes\Value;

final class Mailer
{
    public function __construct(
        #[Value('${mail.host:localhost}')] public readonly string $host,
    ) {}
}
```

`Firefly\Config\Value\ConfigValueResolver` implementa el mismo puerto `ValueResolver` que define `firefly/container`, pero resuelve `${key:default}` contra la **configuración de la aplicación primero**, luego el entorno, luego el valor por defecto literal:

```php
if (preg_match('/^\$\{([^:}]+)(?::([^}]*))?\}$/', $expression, $m) === 1) {
    $key = $m[1];
    if ($this->config->has($key)) {
        return $this->config->get($key);
    }
    $env = getenv($key);
    if ($env !== false) {
        return $env;
    }

    return array_key_exists(2, $m) ? $m[2] : null;
}
```

`#{expr}` sigue evaluando una pequeña expresión en sandbox, ahora con una función `config('key')` registrada sobre ella, así que una expresión también puede leer configuración — `#{config('mail.port') * 2}` es válido una vez instalado `firefly/config`.

`ConfigRegistrar::register()` vincula `ConfigValueResolver` por encima del valor por defecto del contenedor:

```php
$this->container->instance(ValueResolver::class, new ConfigValueResolver($this->config));
```

El propio registrador de `firefly/container` solo vincula su `DefaultValueResolver` **si no hay ninguno ya vinculado** — así que el resolutor respaldado por configuración, una vez que `firefly/config` lo registra, siempre gana, y `${mail.host:localhost}` ahora lee `config('mail.host')` primero, el entorno en segundo lugar, y solo recurre al literal `localhost` si ninguno de los dos está definido.

!!! laravel "Paridad con Laravel"
    `#[Value('${mail.host:...}')]` leyendo primero la configuración de la aplicación es lo más parecido en LaraFly a `@Value("${...}")` de Spring leyendo `application.yaml`. En una aplicación Laravel corriente, el idioma equivalente es simplemente `config('mail.host')` llamado en el punto de uso — `#[Value]` te da el mismo valor resuelto, pero declarado una sola vez sobre el parámetro del constructor, junto al resto de las dependencias de la clase.

---

## Perfiles

Un **perfil** es un entorno con nombre — `local`, `testing`, `staging`, `prod` — y LaraFly te da un vocabulario pequeño y concreto para leer cuáles están activos en cada momento, con independencia de cualquier clave de configuración concreta.

`Firefly\Config\Profile\ProfileResolver::resolve()` devuelve un objeto de valor `Profiles`, usando una precedencia fija: la variable de entorno `FIREFLY_PROFILES_ACTIVE` (separada por comas, para más de un perfil activo) si está definida; si no, el único valor `APP_ENV` de Laravel; si no, el perfil implícito `default`.

```php
use Firefly\Config\Profile\ProfileResolver;

$profiles = (new ProfileResolver())->resolve();

$profiles->isActive('prod');   // bool
$profiles->all();              // list<string>
$profiles->isEmpty();          // bool
```

```php
final readonly class Profiles
{
    public function __construct(public array $active) {}

    public function isActive(string $profile): bool
    {
        return in_array($profile, $this->active, true);
    }
}
```

!!! laravel "Paridad con Laravel"
    Que `FIREFLY_PROFILES_ACTIVE` recurra a `APP_ENV` significa que cualquier aplicación Laravel existente ya tiene un perfil activo sin añadir una sola variable de entorno: `APP_ENV=production` en tu `.env` ya es, hoy mismo, el perfil `prod` de LaraFly. `FIREFLY_PROFILES_ACTIVE` existe para el caso que el único `APP_ENV` de Laravel no puede expresar por sí solo — activar más de un perfil a la vez.

### Condicionar un componente a un perfil

Saber qué perfiles están activos se vuelve genuinamente útil en cuanto la propia presencia de un componente en el contenedor puede depender de ello. `#[ConditionalOnProfile]` — de `firefly/context`, el paquete del motor de arranque que el Capítulo 2 mencionó de pasada — condiciona un `#[Component]` (o un `#[Configuration]`/`#[Bean]`) a uno o más perfiles:

```php
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProfile;

#[Component]
#[ConditionalOnProfile('local', 'testing')]
final class InMemoryMailer {}
```

`InMemoryMailer` solo entra en el contenedor cuando `local` **o** `testing` está entre los perfiles activos — la condición es una simple coincidencia "cualquier perfil listado está activo", evaluada por el mismo `ConditionEvaluator` que evalúa `#[ConditionalOnProperty]`, `#[ConditionalOnClass]` y `#[ConditionalOnMissingClass]`. En `prod`, la clase ni siquiera se instancia jamás: se filtra fuera del `BeanDefinitionRegistry` antes de construirse el contenedor, no simplemente se deja sin resolver.

!!! laravel "Paridad con Laravel"
    `#[ConditionalOnProfile]` es lo más cerca que LaraFly llega a `@Profile` de Spring. En Laravel puro, lo más parecido es una guarda `if (app()->environment('local', 'testing'))` dentro del método `register()` de un proveedor de servicios — `#[ConditionalOnProfile]` traslada esa misma decisión a la propia clase, de modo que el condicionamiento por entorno de un componente es visible justo al lado de su definición, en vez de en un archivo de proveedor aparte.

---

## Mantener los secretos fuera de los archivos

Todo lo que ha mostrado este capítulo lee, en algún punto, a través de `env()` — `env('WALLET_DAILY_TRANSFER_LIMIT_MINOR', ...)` en `config/wallet.php`, `${mail.host:localhost}` cayendo al entorno, el propio `FIREFLY_PROFILES_ACTIVE`. Esto es deliberado, y es la misma disciplina que cualquier aplicación Laravel ya debería seguir: la configuración segura de subir al repositorio — puertos, indicadores de funcionalidad, monedas por defecto — pertenece a un archivo `config/*.php`; cualquier cosa que otorgue acceso — contraseñas de base de datos, claves de API, secretos de firma JWT — pertenece a `.env`, que nunca se sube al repositorio, y se suministra de forma distinta (archivos reales en local, secretos inyectados en CI/CD y producción) según el entorno.

!!! warning "Nunca subas secretos al repositorio"
    `#[ConfigProperties]` y `#[Value]` en última instancia leen lo que sea que devuelvan tus archivos `config/*.php` — si un archivo de configuración escribe una credencial real en vez de llamar a `env(...)`, esa credencial queda en el historial de tu repositorio. Mantén cada secreto detrás de una llamada `env()` sin valor por defecto comprometido al repositorio, e inyecta el valor real en el momento del despliegue.

---

## Lo que aprendiste {.recap}

| Concepto | Qué hace |
|---|---|
| `Config` | Un accesor tipado y de fallo rápido (`string()`/`int()`/`bool()`/`array()`) sobre el propio repositorio de configuración de Laravel |
| `#[ConfigProperties(prefix)]` | Vincula un subárbol de configuración sobre un DTO plano, registrado como singleton del contenedor |
| `ReflectionConfigBinder` | El `ConfigBinder` por defecto: empareja parámetros del constructor por nombre, convierte escalares, recurre en DTOs anidados |
| `ConfigPropertiesScanner` / `ConfigManifestCompiler` | Reflexiona las clases `#[ConfigProperties]` una sola vez, en el momento de `firefly:cache`, en un manifiesto sin reflexión |
| `ConfigRegistrar` | Vincula cada entrada del manifiesto como singleton, e instala `ConfigValueResolver` sobre el valor por defecto del contenedor |
| `#[Value('${key:default}')]` | Con `firefly/config` instalado, resuelve contra la configuración de la aplicación primero, luego el entorno, luego el valor por defecto |
| `ProfileResolver` / `Profiles` | Resuelve los perfiles activos desde `FIREFLY_PROFILES_ACTIVE`, recurriendo a `APP_ENV`, recurriendo a `default` |
| `#[ConditionalOnProfile(...)]` | Condiciona la presencia de un componente en el contenedor a uno o más perfiles activos |

---

## Ponlo en práctica {.exercises}

1. **Añade `WalletProperties` de verdad.** Crea `config/wallet.php` y `Lumen\Domain\WalletProperties` exactamente como se muestra en este capítulo (en una copia de trabajo del proyecto — no modifiques el paquete distribuido `samples/lumen`), inyéctalo por constructor en un nuevo servicio, y confirma que `php artisan firefly:cache` seguido de leer el DTO inyectado refleja un valor que fijaste vía `WALLET_DAILY_TRANSFER_LIMIT_MINOR` en `.env`.
2. **Provoca un fallo de clave ausente.** Quita el valor por defecto del constructor de uno de los parámetros de `WalletProperties`, borra la clave correspondiente de `config/wallet.php`, y observa `ConfigurationException` en el punto en que se resuelve el DTO — luego restaura el valor por defecto y confirma que la excepción desaparece.
3. **Condiciona un bean a un perfil.** Añade una clase `#[Component]` `#[ConditionalOnProfile('testing')]` a tu proyecto de pruebas, ejecuta `php artisan firefly:cache` con `FIREFLY_PROFILES_ACTIVE` sin definir, y confirma que la clase nunca aparece en el manifiesto de componentes compilado; luego vuelve a ejecutar la caché con `FIREFLY_PROFILES_ACTIVE=testing` y confirma que sí aparece.
