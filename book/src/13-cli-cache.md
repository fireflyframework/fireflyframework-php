<span class="eyebrow">Part IV — Observability, Testing & Delivery · Chapter 13</span>

# The CLI and the Zero-Reflection Cache {.chtitle}

By the end of this chapter you will know exactly what `php artisan firefly:cache` does — the eleven scanner-and-compiler steps it runs, in order, over your application's own PSR-4 roots, and the `bootstrap/cache/firefly/` manifests and `#[Transactional]` proxy classes it writes — how `FireflyCacheServiceProvider` loads that cache back in at boot with zero reflection, `firefly:clear`'s exact inverse, the actuator-over-CLI commands (`firefly:about`/`:routes`/`:health`/`:metrics`) that reuse Chapter 11's endpoints in-process with no HTTP round trip, the eight `make:firefly-*` generators, and how `firefly/installer`'s `firefly new` gets you from nothing to a cached, running application in one command.

!!! note "New term: zero-reflection boot"
    Every chapter before this one has mentioned, in passing, that a compiled manifest replaces "scan the filesystem with PHP reflection at every boot." A **zero-reflection boot** is what you get once `firefly:cache` has actually run: `FireflyCacheServiceProvider` loads pre-computed manifests straight from PHP files under `bootstrap/cache/firefly/`, so no attribute, no class, and no method is ever reflected on at request time — the scan happened once, at deploy time, not on every request.

---

## `firefly:cache`: the zero-reflection payoff

`CacheCommand` is a thin wrapper — it reads `firefly.scan.paths` (the same PSR-4 map every earlier chapter's `configOverrides()` seeded) and hands it to `ManifestCacheWriter`:

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

`ManifestCacheWriter::write()` is the real orchestration, and it is worth reading end to end: it is nothing more than every capability package's *existing* scanner-and-compiler pair, called in sequence, over the same PSR-4 map — no new abstraction, zero edits to any settled package:

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

That is ten `scan()`-then-`write()` calls — every capability from Chapter 2's component scan through Chapter 10's method-security manifest, each already introduced in an earlier chapter as an in-process scan — plus one more step, `writeProxies()`, that generates the `#[Transactional]` proxy classes Chapter 9 taught you about, using the exact same reflection-free `ProxyClassGenerator` the runtime itself uses when no cache exists yet:

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

Eleven steps in total land twelve artifacts under `bootstrap/cache/firefly/`:

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

## `FireflyCacheServiceProvider`: loading the cache back in

Writing the manifests is only half the story — something has to notice they exist and bind them *instead of* running the in-process scan. `FireflyCacheServiceProvider` (auto-discovered) is that something, and its `register()` runs before any bean resolution at all:

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

Each `$app->instance(...)` call is **unconditional**, and every capability's own default is `if (! bound(X)) singleton(X, empty)` — so the loaded manifest always wins, regardless of which provider Laravel happened to register first. The `spl_autoload_register` call at the end is what actually makes the cached proxies reachable: it installs a classmap loader keyed by the generated proxy's fully-qualified name, so the very first time `TransactionalBeanPostProcessor` checks `class_exists($proxyClass)`, the class is already loadable — no reflection, no filesystem scan, just a map lookup.

None of this is mandatory. An application with no cache directory at all still boots — every capability's manifest binding falls back to the in-process scanner exactly as it has in every earlier chapter — just without the zero-reflection guarantee. `firefly:cache` is a pure performance-and-determinism upgrade you opt into by running it, not a requirement for the framework to function.

---

## `firefly:clear`

The exact inverse: it recursively deletes `FireflyCachePaths::dir()` — the configured `firefly.cache.path`, or `bootstrap/cache/firefly` by default — and nothing else:

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

Safe to run at any time: the very next boot simply falls back to the in-process scanner, the same as an application that never ran `firefly:cache` at all.

---

## Actuator-over-CLI: `firefly:about`, `firefly:routes`, `firefly:health`, `firefly:metrics`

Chapter 11 built a management surface reachable over HTTP. These four commands render the **same** endpoints at the terminal, in-process — no HTTP request is made, and none of them reimplement any actuator logic:

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

`firefly:about` resolves the compiled `ActuatorRegistry` (the same registry `ActuatorRouteRegistrar` populates at boot), calls each endpoint's `handle()`, and prints the response — the full Spring Boot `--debug`/actuator introspection story at a glance, in one command. `firefly:routes` renders just the `mappings` endpoint (your compiled route table), `firefly:health` renders `health`, and `firefly:metrics` renders `metrics` — the last one requires `firefly/observability` to be installed, and prints a warning and exits successfully rather than erroring if it is not.

---

## Scaffolding: the `make:firefly-*` generators

Every framework stereotype this book has introduced has a matching Artisan generator — pyfly's `generate` command family, ported to Laravel's own `GeneratorCommand` base:

| Command | Generates |
|---------|-----------|
| `make:firefly-controller` | A `#[RestController]` with a sample `#[GetMapping]` action, under `app/Http`. |
| `make:firefly-service` | A `#[Service]` bean. |
| `make:firefly-component` | A `#[Component]` bean. |
| `make:firefly-handler` | A `#[CommandHandler]` by default, or a `#[QueryHandler]` with `--query`. |
| `make:firefly-listener` | An `#[EventListener]` method by default, or a `#[MessageListener]` with `--message`. |
| `make:firefly-entity` | A DDD entity extending `Firefly\Domain\Entity` (there is no `#[Entity]` attribute). |
| `make:firefly-repository` | A repository interface extending `Firefly\Data\Repository\CrudRepository`. |
| `make:firefly-config-properties` | A `#[ConfigProperties]`-bound configuration DTO. |

`make:firefly-handler` is representative of the whole family — a `GeneratorCommand` that picks which stub to render off a single flag:

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

and the command-handler stub it renders — with its `{{ namespace }}`/`{{ class }}` placeholders substituted by `GeneratorCommand` the way `php artisan make:firefly-handler RegisterWidget` would — is exactly the shape Chapter 7 taught you to write by hand:

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

## Thin passthroughs: `firefly:serve`, `firefly:db`

Two commands exist purely for a consistent `firefly:*` naming convention over commands Laravel already ships — neither reimplements any Laravel behavior:

```
php artisan firefly:serve {--host=127.0.0.1} {--port=8000}
```

Delegates to `artisan serve`, or to `octane:start` when `laravel/octane` is installed — probed only via `class_exists()`, so Octane stays an optional runtime dependency `firefly/cli`'s own `composer.json` never requires.

```
php artisan firefly:db {action=migrate}
```

Delegates to Laravel's own database commands: `migrate` (default), `db:seed` (`firefly:db seed`), or `migrate:fresh` (`firefly:db fresh`).

---

## From zero to running: `firefly/installer` and `firefly/skeleton`

`firefly/installer` ships the global `firefly new` command — a small Symfony Console binary, not itself a scaffolder. It shells straight out to `composer create-project firefly/skeleton`:

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

`firefly/skeleton` — a genuine, top-level Laravel 13 application template pre-wired with the Firefly family and a sample `#[RestController]`/`#[Service]` pair — is what `composer create-project` actually pulls down. Its own `composer.json` closes the loop this whole chapter has been building toward: `firefly:cache` runs **automatically**, right after install, with no manual step:

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

Every application `firefly new` scaffolds is therefore, from the very first `git commit`, already boot-cached — a genuinely zero-reflection application on disk, not merely a framework capable of becoming one. `bootstrap/cache/firefly/` itself is `.gitignore`d in the skeleton (the manifests are generated fresh per install, not committed), which is exactly what you would expect of a build artifact rather than source.

!!! laravel "Laravel parity"
    `composer create-project laravel/laravel` gives you a bare Laravel app with nothing pre-wired; `firefly new` (or `composer create-project firefly/skeleton`) gives you the same Laravel app, plus the Firefly family pre-required, plus a sample stereotype pair, plus a boot cache already warmed — the equivalent of Spring Initializr's "generate a working starter project," aimed at `artisan` rather than a web form. `make:firefly-*` mirrors Laravel's own `make:controller`/`make:model` family exactly in spirit — an Artisan `GeneratorCommand`, a stub, a namespace placeholder — just one stereotype attribute deeper.

---

## What you learned {.recap}

| Concept | What it does |
|---|---|
| `firefly:cache` | Runs every capability's own scanner→compiler pair (11 steps, 12 artifacts) over `firefly.scan.paths`; writes manifests + `#[Transactional]` proxies to `bootstrap/cache/firefly/` |
| `FireflyCacheServiceProvider` | Binds each cached manifest unconditionally (wins over every empty default) + installs the proxy classmap autoloader — before any bean resolution runs |
| `firefly:clear` | Recursively deletes only the cache dir; the next boot falls back to in-process scanning |
| `firefly:about` / `:routes` / `:health` / `:metrics` | Renders Chapter 11's actuator endpoints at the terminal, in-process, no HTTP round trip |
| `make:firefly-*` | Eight generators, one per stereotype — `GeneratorCommand` + a stub, exactly like Laravel's own `make:*` family |
| `firefly:serve` / `firefly:db` | Thin passthroughs to `artisan serve`/`octane:start` and Laravel's own database commands |
| `firefly new` (`firefly/installer`) | Shells out to `composer create-project firefly/skeleton`; the skeleton runs `firefly:cache` automatically post-install |

---

## Try it yourself {.exercises}

1. **Run `firefly:cache` against Lumen and inspect the output.** From the framework's monorepo, point `firefly.scan.paths` at `samples/lumen/src` and run the command by hand; open `bootstrap/cache/firefly/handlers.php` and confirm it lists `WithdrawHandler` and its siblings from Chapter 6 and 7, in a form you can read without any reflection at all.
2. **Break the cache on purpose.** Run `firefly:cache`, then rename a `#[CommandHandler]` class without re-running it. Confirm the cached `handlers.php` still points at the old name — and that only `firefly:cache` again (not `firefly:clear`) fixes it — proving the cache is a snapshot, not a live view.
3. **Scaffold a whole vertical with the generators.** Use `make:firefly-entity`, `make:firefly-repository`, `make:firefly-handler`, and `make:firefly-controller` in sequence to build a tiny new feature from scratch, then run `firefly:cache` once at the end and confirm every piece appears in the compiled manifests together.
