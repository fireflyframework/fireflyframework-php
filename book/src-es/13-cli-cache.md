<span class="eyebrow">Parte IV — Observabilidad, Pruebas y Entrega · Capítulo 13</span>

# La CLI y la Caché Sin Reflexión {.chtitle}

Al terminar este capítulo sabrás exactamente qué hace `php artisan firefly:cache` — los once pasos de escáner-y-compilador que ejecuta, en orden, sobre las propias raíces PSR-4 de tu aplicación, y los manifiestos de `bootstrap/cache/firefly/` y las clases proxy `#[Transactional]` que escribe — cómo `FireflyCacheServiceProvider` vuelve a cargar esa caché en el arranque con cero reflexión, el inverso exacto de `firefly:clear`, los comandos de actuator-sobre-CLI (`firefly:about`/`:routes`/`:health`/`:metrics`) que reutilizan los endpoints del Capítulo 11 en-proceso sin ida y vuelta HTTP, los ocho generadores `make:firefly-*`, y cómo el `firefly new` de `firefly/installer` te lleva de la nada a una aplicación cacheada y en funcionamiento en un solo comando.

!!! note "Término nuevo: arranque sin reflexión"
    Cada capítulo anterior a este ha mencionado, de pasada, que un manifiesto compilado reemplaza a "escanea el sistema de archivos con reflexión de PHP en cada arranque". Un **arranque sin reflexión** es lo que obtienes una vez que `firefly:cache` ha ejecutado realmente: `FireflyCacheServiceProvider` carga manifiestos precomputados directamente desde archivos PHP bajo `bootstrap/cache/firefly/`, de modo que ningún atributo, ninguna clase y ningún método se somete jamás a reflexión en tiempo de petición — el escaneo ocurrió una vez, en tiempo de despliegue, no en cada petición.

---

## `firefly:cache`: la recompensa sin reflexión

`CacheCommand` es un envoltorio fino — lee `firefly.scan.paths` (el mismo mapa PSR-4 que sembró el `configOverrides()` de cada capítulo anterior) y se lo entrega a `ManifestCacheWriter`:

```php
final class CacheCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:cache';

    public function handle(): int
    {
        $psr4 = $this->laravel->make('config')->get('firefly.scan.paths', []);
        if ($psr4 === []) {
            $this->warn('firefly.scan.paths is empty — nothing to compile.');

            return self::SUCCESS;
        }

        $dir = FireflyCachePaths::dir($this->laravel);
        $report = (new ManifestCacheWriter)->write($psr4, $dir);

        $this->info(sprintf(
            'firefly:cache — wrote %d manifest(s) + %d proxy(ies) to %s',
            count($report->files),
            $report->proxyCount,
            $dir,
        ));

        return self::SUCCESS;
    }
}
```

`ManifestCacheWriter::write()` es la orquestación real, y vale la pena leerla de principio a fin: no es más que el par escáner-y-compilador *ya existente* de cada paquete de capacidad, llamado en secuencia, sobre el mismo mapa PSR-4 — sin ninguna abstracción nueva, cero ediciones a ningún paquete ya asentado:

```php
final class ManifestCacheWriter
{
    public function write(array $psr4, string $dir): CacheReport
    {
        $report = $this->writeManifests($psr4, $dir);
        $count = $this->writeProxies($psr4, $dir);

        return new CacheReport([...$report->files, $dir.'/'.FireflyCachePaths::PROXY_MAP], $count);
    }

    public function writeManifests(array $psr4, string $dir): CacheReport
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $files = [];

        // container + context + autoconfigure (folded): one call emits both component + context manifests.
        (new AutoConfigManifestCompiler)->write(
            $psr4,
            $files[] = $dir.'/'.FireflyCachePaths::COMPONENT,
            $files[] = $dir.'/'.FireflyCachePaths::CONTEXT,
        );

        // config
        (new ConfigManifestCompiler)->write(
            (new ConfigPropertiesScanner)->scan($psr4),
            $files[] = $dir.'/'.FireflyCachePaths::CONFIG_PROPERTIES,
        );

        // web
        (new RouteManifestCompiler)->write(
            (new RouteScanner)->scan($psr4),
            $files[] = $dir.'/'.FireflyCachePaths::ROUTES,
        );

        // validation — compiles from an explicit class list, not a PSR-4 scan.
        (new ConstraintManifestCompiler)->write(
            (new ClassEnumerator)->enumerate($psr4),
            $files[] = $dir.'/'.FireflyCachePaths::CONSTRAINTS,
        );

        // cqrs — 3-arg write (handlers + destinations + path).
        $handlers = (new HandlerScanner)->scan($psr4);
        (new HandlerManifestCompiler)->write(
            $handlers['handlers'],
            $handlers['destinations'],
            $files[] = $dir.'/'.FireflyCachePaths::HANDLERS,
        );

        // eda
        (new EventListenerManifestCompiler)->write(
            (new EventListenerScanner)->scan($psr4),
            $files[] = $dir.'/'.FireflyCachePaths::EVENT_LISTENERS,
        );

        // messaging
        (new MessageListenerManifestCompiler)->write(
            (new MessageListenerScanner)->scan($psr4),
            $files[] = $dir.'/'.FireflyCachePaths::MESSAGE_LISTENERS,
        );

        // scheduling
        (new ScheduledManifestCompiler)->write(
            (new ScheduledScanner)->scan($psr4),
            $files[] = $dir.'/'.FireflyCachePaths::SCHEDULED,
        );

        // security
        (new SecurityMethodManifestCompiler)->write(
            (new MethodSecurityScanner)->scan($psr4),
            $files[] = $dir.'/'.FireflyCachePaths::SECURITY_METHODS,
        );

        // data — transactional manifest data (proxy CLASS files added by writeProxies()).
        (new TransactionalManifestCompiler)->write(
            (new TransactionalScanner)->scan($psr4),
            $files[] = $dir.'/'.FireflyCachePaths::TRANSACTIONAL,
        );

        return new CacheReport($files);
    }
}
```

Eso son diez llamadas `scan()`-luego-`write()` — cada capacidad desde el escaneo de componentes del Capítulo 2 hasta el manifiesto de seguridad de método del Capítulo 10, cada una ya presentada en un capítulo anterior como un escaneo en-proceso — más un paso más, `writeProxies()`, que genera las clases proxy `#[Transactional]` que te enseñó el Capítulo 9, usando el mismísimo `ProxyClassGenerator` sin reflexión que usa el propio runtime cuando aún no existe caché:

```php
final class ManifestCacheWriter
{
    public function writeProxies(array $psr4, string $dir): int
    {
        $proxyDir = $dir.'/'.FireflyCachePaths::PROXY_DIR;
        if (! is_dir($proxyDir)) {
            mkdir($proxyDir, 0o755, true);
        }

        $generator = new ProxyClassGenerator;
        $map = [];

        foreach ((new TransactionalScanner)->scanProxyMethods($psr4) as $targetClass => $methods) {
            $source = $generator->generate($targetClass, $methods);
            $proxyClass = $targetClass.'__FireflyTransactionalProxy';
            $file = $proxyDir.'/'.str_replace('\\', '_', $targetClass).'.php';
            file_put_contents($file, $source);
            $map[$proxyClass] = $file;
        }

        file_put_contents(
            $dir.'/'.FireflyCachePaths::PROXY_MAP,
            "<?php\n\ndeclare(strict_types=1);\n\n// Generated by firefly/cli. Do not edit.\n\nreturn ".var_export($map, true).";\n",
        );

        return count($map);
    }
}
```

Once pasos en total dejan doce artefactos bajo `bootstrap/cache/firefly/`:

```
bootstrap/cache/firefly/
├── component.php          # DI component manifest (container + context + autoconfigure)
├── context.php            # application-context manifest
├── config-properties.php  # #[ConfigProperties] DTOs
├── routes.php             # compiled route table
├── constraints.php        # validation constraint manifest
├── handlers.php           # #[CommandHandler]/#[QueryHandler] manifest
├── event-listeners.php    # #[EventListener] manifest
├── message-listeners.php  # #[MessageListener] manifest
├── scheduled.php          # #[Scheduled] manifest
├── security-methods.php   # #[PreAuthorize]/#[Secured]/#[RolesAllowed] manifest
├── transactional.php      # #[Transactional] method manifest
├── proxies.php            # FQCN => file classmap for the generated proxies
└── proxies/               # one generated proxy class file per #[Transactional] target
```

---

## `FireflyCacheServiceProvider`: volver a cargar la caché

Escribir los manifiestos es solo la mitad de la historia — algo tiene que darse cuenta de que existen y enlazarlos *en lugar de* ejecutar el escaneo en-proceso. `FireflyCacheServiceProvider` (autodescubierto) es ese algo, y su `register()` se ejecuta antes de cualquier resolución de bean en absoluto:

```php
final class FireflyCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $dir = FireflyCachePaths::dir($this->app);

        if (is_file($path = $dir.'/'.FireflyCachePaths::ROUTES)) {
            $this->app->instance(RouteManifest::class, RouteManifest::load($path));
        }
        if (is_file($path = $dir.'/'.FireflyCachePaths::HANDLERS)) {
            $this->app->instance(HandlerManifest::class, HandlerManifest::load($path));
        }
        // ... one such block per manifest type ...

        // Proxies: register a classmap autoloader so TransactionalBeanPostProcessor's class_exists($proxyClass)
        // is satisfied BEFORE it throws. register() runs at provider-register, before any boot-pass bean resolution.
        $proxyMap = $dir.'/'.FireflyCachePaths::PROXY_MAP;
        if (is_file($proxyMap)) {
            $map = require $proxyMap;
            spl_autoload_register(static function (string $class) use ($map): void {
                if (isset($map[$class]) && is_file($map[$class])) {
                    require $map[$class];
                }
            });
        }
    }
}
```

Cada llamada `$app->instance(...)` es **incondicional**, y el propio valor por defecto de cada capacidad es `if (! bound(X)) singleton(X, empty)` — de modo que el manifiesto cargado siempre gana, independientemente de qué proveedor haya registrado Laravel primero. La llamada `spl_autoload_register` del final es lo que realmente hace alcanzables los proxies cacheados: instala un cargador de classmap indexado por el nombre completamente cualificado del proxy generado, de modo que la primerísima vez que `TransactionalBeanPostProcessor` comprueba `class_exists($proxyClass)`, la clase ya es cargable — sin reflexión, sin escaneo del sistema de archivos, solo una búsqueda en un mapa.

Nada de esto es obligatorio. Una aplicación sin ningún directorio de caché aún arranca — el enlace de manifiesto de cada capacidad recurre al escáner en-proceso exactamente como lo ha hecho en cada capítulo anterior — solo que sin la garantía de cero reflexión. `firefly:cache` es una mejora pura de rendimiento-y-determinismo por la que optas ejecutándola, no un requisito para que el framework funcione.

---

## `firefly:clear`

El inverso exacto: borra recursivamente `FireflyCachePaths::dir()` — el `firefly.cache.path` configurado, o `bootstrap/cache/firefly` por defecto — y nada más:

```php
final class ClearCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:clear';

    public function handle(): int
    {
        $dir = FireflyCachePaths::dir($this->laravel);
        if (! is_dir($dir)) {
            $this->info('firefly:clear — nothing to remove.');

            return self::SUCCESS;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);

        $this->info('firefly:clear — removed '.$dir);

        return self::SUCCESS;
    }
}
```

Seguro de ejecutar en cualquier momento: el siguiente arranque simplemente recurre al escáner en-proceso, igual que una aplicación que nunca ejecutó `firefly:cache` en absoluto.

---

## Actuator-sobre-CLI: `firefly:about`, `firefly:routes`, `firefly:health`, `firefly:metrics`

El Capítulo 11 construyó una superficie de gestión alcanzable sobre HTTP. Estos cuatro comandos renderizan los **mismos** endpoints en el terminal, en-proceso — no se hace ninguna petición HTTP, y ninguno de ellos reimplementa lógica alguna del actuator:

```php
final class AboutCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:about';

    public function handle(ActuatorCliRenderer $renderer): int
    {
        $this->line('LaraFly '.Version::VERSION);
        foreach (['info', 'env', 'beans', 'conditions', 'mappings', 'scheduledtasks'] as $id) {
            $this->newLine();
            $this->info('# '.$id);
            $renderer->render($this, $id);
        }

        return self::SUCCESS;
    }
}
```

`firefly:about` resuelve el `ActuatorRegistry` compilado (el mismo registro que `ActuatorRouteRegistrar` puebla en el arranque), llama al `handle()` de cada endpoint, e imprime la respuesta — toda la historia de introspección `--debug`/actuator de Spring Boot de un vistazo, en un solo comando. `firefly:routes` renderiza solo el endpoint `mappings` (tu tabla de rutas compilada), `firefly:health` renderiza `health`, y `firefly:metrics` renderiza `metrics` — el último requiere que `firefly/observability` esté instalado, e imprime una advertencia y sale con éxito en lugar de dar un error si no lo está.

---

## Andamiaje: los generadores `make:firefly-*`

Cada estereotipo de framework que este libro ha presentado tiene un generador de Artisan a juego — la familia de comandos `generate` de pyfly, portada a la propia base `GeneratorCommand` de Laravel:

| Comando | Genera |
|---------|-----------|
| `make:firefly-controller` | Un `#[RestController]` con una acción `#[GetMapping]` de ejemplo, bajo `app/Http`. |
| `make:firefly-service` | Un bean `#[Service]`. |
| `make:firefly-component` | Un bean `#[Component]`. |
| `make:firefly-handler` | Un `#[CommandHandler]` por defecto, o un `#[QueryHandler]` con `--query`. |
| `make:firefly-listener` | Un método `#[EventListener]` por defecto, o un `#[MessageListener]` con `--message`. |
| `make:firefly-entity` | Una entidad DDD que extiende `Firefly\Domain\Entity` (no hay ningún atributo `#[Entity]`). |
| `make:firefly-repository` | Una interfaz de repositorio que extiende `Firefly\Data\Repository\CrudRepository`. |
| `make:firefly-config-properties` | Un DTO de configuración enlazado con `#[ConfigProperties]`. |

`make:firefly-handler` es representativo de toda la familia — un `GeneratorCommand` que elige qué stub renderizar en función de una única bandera:

```php
final class MakeHandlerCommand extends GeneratorCommand
{
    /** @var string */
    protected $name = 'make:firefly-handler';

    /** @var string */
    protected $type = 'Firefly handler';

    protected function getStub(): string
    {
        return __DIR__.'/../../../stubs/'.($this->option('query') ? 'query-handler.stub' : 'command-handler.stub');
    }

    protected function getOptions(): array
    {
        return [
            ['query', null, InputOption::VALUE_NONE, 'Generate a #[QueryHandler] instead of a #[CommandHandler].'],
        ];
    }
}
```

y el stub de command-handler que renderiza — con sus marcadores `{{ namespace }}`/`{{ class }}` sustituidos por `GeneratorCommand` de la manera en que lo haría `php artisan make:firefly-handler RegisterWidget` — es exactamente la forma que el Capítulo 7 te enseñó a escribir a mano:

```php
namespace App\Handlers;

use Firefly\Cqrs\Attributes\CommandHandler;

#[CommandHandler]
final class RegisterWidget
{
    /**
     * Replace `object` with the concrete Command message class this handler dispatches for
     * (or pass it explicitly via #[CommandHandler(SomeCommand::class)]).
     */
    public function handle(object $command): mixed
    {
        return null;
    }
}
```

```
php artisan make:firefly-handler RegisterWidget
php artisan make:firefly-handler CountWidgets --query
```

---

## Pasarelas finas: `firefly:serve`, `firefly:db`

Dos comandos existen puramente por una convención de nombres `firefly:*` consistente sobre comandos que Laravel ya trae — ninguno reimplementa comportamiento alguno de Laravel:

```
php artisan firefly:serve {--host=127.0.0.1} {--port=8000}
```

Delega a `artisan serve`, o a `octane:start` cuando `laravel/octane` está instalado — sondeado únicamente vía `class_exists()`, de modo que Octane sigue siendo una dependencia de runtime opcional que el propio `composer.json` de `firefly/cli` nunca requiere.

```
php artisan firefly:db {action=migrate}
```

Delega a los propios comandos de base de datos de Laravel: `migrate` (por defecto), `db:seed` (`firefly:db seed`), o `migrate:fresh` (`firefly:db fresh`).

---

## De cero a en funcionamiento: `firefly/installer` y `firefly/skeleton`

`firefly/installer` trae el comando global `firefly new` — un pequeño binario de Symfony Console, no un andamiador en sí. Sale directamente por shell hacia `composer create-project firefly/skeleton`:

```php
#[AsCommand(name: 'new', description: 'Create a new LaraFly application')]
final class NewCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // ... resolve $name, $directory ...

        $create = ['composer', 'create-project', 'firefly/skeleton', $directory, '--no-interaction'];
        if ($input->getOption('dev')) {
            $create[] = '--stability=dev';
        }
        if ($runner->run($create) !== 0) {
            $io->error('composer create-project failed.');

            return self::FAILURE;
        }

        if (! $input->getOption('no-git')) {
            $runner->run(['git', 'init', '-q'], $directory);
            $runner->run(['git', 'add', '.'], $directory);
            $runner->run(['git', 'commit', '-q', '-m', 'Initial commit'], $directory);
        }

        $io->success("LaraFly application ready at {$directory}");
        $io->writeln("  cd {$name}");
        $io->writeln('  php artisan firefly:serve');

        return self::SUCCESS;
    }
}
```

`firefly/skeleton` — una plantilla de aplicación Laravel 13 genuina, de nivel superior, precableada con la familia Firefly y un par `#[RestController]`/`#[Service]` de ejemplo — es lo que `composer create-project` realmente descarga. Su propio `composer.json` cierra el bucle hacia el que todo este capítulo ha estado construyendo: `firefly:cache` se ejecuta **automáticamente**, justo después de la instalación, sin ningún paso manual:

```json
"scripts": {
    "post-create-project-cmd": [
        "@php -r \"file_exists('.env') || copy('.env.example', '.env');\"",
        "@php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\"",
        "@php artisan key:generate --ansi",
        "@php artisan firefly:cache"
    ]
}
```

Cada aplicación que `firefly new` andamia es por tanto, desde el primerísimo `git commit`, ya boot-cacheada — una aplicación genuinamente sin reflexión en disco, no meramente un framework capaz de convertirse en una. El propio `bootstrap/cache/firefly/` está en `.gitignore` en el esqueleto (los manifiestos se generan frescos por instalación, no se commitean), que es exactamente lo que esperarías de un artefacto de compilación en lugar de código fuente.

!!! laravel "Paridad con Laravel"
    `composer create-project laravel/laravel` te da una app Laravel desnuda sin nada precableado; `firefly new` (o `composer create-project firefly/skeleton`) te da la misma app Laravel, más la familia Firefly ya requerida, más un par de estereotipos de ejemplo, más una caché de arranque ya calentada — el equivalente al "genera un proyecto de arranque funcional" de Spring Initializr, apuntado a `artisan` en lugar de a un formulario web. `make:firefly-*` refleja la propia familia `make:controller`/`make:model` de Laravel exactamente en espíritu — un `GeneratorCommand` de Artisan, un stub, un marcador de namespace — solo que un atributo de estereotipo más profundo.

---

## Lo que aprendiste {.recap}

| Concepto | Qué hace |
|---|---|
| `firefly:cache` | Ejecuta el par escáner→compilador propio de cada capacidad (11 pasos, 12 artefactos) sobre `firefly.scan.paths`; escribe manifiestos + proxies `#[Transactional]` a `bootstrap/cache/firefly/` |
| `FireflyCacheServiceProvider` | Enlaza cada manifiesto cacheado incondicionalmente (gana sobre todo valor por defecto vacío) + instala el autoloader de classmap de proxies — antes de que se ejecute cualquier resolución de bean |
| `firefly:clear` | Borra recursivamente solo el directorio de caché; el siguiente arranque recurre al escaneo en-proceso |
| `firefly:about` / `:routes` / `:health` / `:metrics` | Renderiza los endpoints de actuator del Capítulo 11 en el terminal, en-proceso, sin ida y vuelta HTTP |
| `make:firefly-*` | Ocho generadores, uno por estereotipo — `GeneratorCommand` + un stub, exactamente como la propia familia `make:*` de Laravel |
| `firefly:serve` / `firefly:db` | Pasarelas finas a `artisan serve`/`octane:start` y a los propios comandos de base de datos de Laravel |
| `firefly new` (`firefly/installer`) | Sale por shell hacia `composer create-project firefly/skeleton`; el esqueleto ejecuta `firefly:cache` automáticamente tras la instalación |

---

## Ponlo en práctica {.exercises}

1. **Ejecuta `firefly:cache` contra Lumen e inspecciona la salida.** Desde el monorepo del framework, apunta `firefly.scan.paths` a `samples/lumen/src` y ejecuta el comando a mano; abre `bootstrap/cache/firefly/handlers.php` y confirma que lista `WithdrawHandler` y sus hermanos de los Capítulos 6 y 7, en una forma que puedes leer sin ninguna reflexión en absoluto.
2. **Rompe la caché a propósito.** Ejecuta `firefly:cache`, luego renombra una clase `#[CommandHandler]` sin volver a ejecutarlo. Confirma que el `handlers.php` cacheado todavía apunta al nombre antiguo — y que solo `firefly:cache` de nuevo (no `firefly:clear`) lo arregla — demostrando que la caché es una instantánea, no una vista en vivo.
3. **Andamia toda una vertical con los generadores.** Usa `make:firefly-entity`, `make:firefly-repository`, `make:firefly-handler` y `make:firefly-controller` en secuencia para construir una pequeña característica nueva desde cero, luego ejecuta `firefly:cache` una vez al final y confirma que cada pieza aparece en los manifiestos compilados juntas.
