<?php

declare(strict_types=1);

namespace Lumen\Tests;

use Firefly\Actuator\ActuatorServiceProvider;
use Firefly\Actuator\ActuatorWiringProvider;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Cqrs\Scanner\HandlerScanner;
use Firefly\Data\DataServiceProvider;
use Firefly\Data\Proxy\ProxyClassGenerator;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Transaction\TransactionalManifestCompiler;
use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\Eda\Listener\EventListenerManifest;
use Firefly\Eda\Scanner\EventListenerScanner;
use Firefly\Security\Access\Method\SecurityMethodManifest;
use Firefly\Security\Scanner\MethodSecurityScanner;
use Firefly\Security\SecurityServiceProvider;
use Firefly\Security\SecurityWiringProvider;
use Firefly\Testing\Boot\FireflyBoot;
use Firefly\Testing\FireflyDatabaseTestCase;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Validation\Constraint\ConstraintManifestCompiler;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\Exception\ExceptionHandlerRegistry;
use Firefly\Web\Route\RouteManifest;
use Firefly\Web\Route\RouteScanner;
use Firefly\Web\WebServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Lumen\Tests\Support\LumenTransactionalConfiguration;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The Lumen sample boot harness — a NOVEL composition that boots the WHOLE Firefly stack the wallet/ledger sample
 * exercises (DI + config + web + validation + data/transactions + CQRS + events + method-security + actuator) over
 * testbench + sqlite :memory:, on the DEV/scan path (firefly.scan.paths), inline-compiling exactly the manifests
 * firefly:cache would emit. Every later S-task test extends it (via samples/lumen/tests/Pest.php's uses()).
 *
 * It is NOT a copy of any single framework capstone: it replicates each cited base's manifest-binding approach —
 *   - #[Transactional] proxies + manifest  -> DataCapstoneTestCase (+ CapstoneTransactionalConfiguration)
 *   - CQRS HandlerManifest + the bridge     -> CqrsCapstoneTestCase / CqrsWiringProvider
 *   - EDA EventListenerManifest             -> EdaCapstoneTestCase
 *   - method security                       -> SecurityAuthorizerTest (SecurityCommandAuthorizer at the bus)
 *   - web routes/constraints/advice         -> WebCapstoneTestCase
 *   - component/context manifests           -> firefly.scan.paths (the scan branch, like WebCapstoneTestCase)
 *
 * DELIBERATELY: it binds the REAL Firefly\Eda\Bus\InMemoryEventBus as the EventPublisher (EdaAutoConfiguration's
 * memory default, reached by leaving firefly.eda.provider unset) — NOT a RecordingEventPublisher double — so the
 * domain->integration bridge and S5's projector actually fire. It also does NOT use Laravel's
 * DatabaseTransactions/RefreshDatabase: an outer test-wrapping transaction would make a handler's own commit never
 * the outermost one, so DB::afterCommit() would never fire and domain events would never publish — the exact reason
 * DataCapstoneTestCase avoids those traits too.
 */
abstract class LumenTestCase extends FireflyDatabaseTestCase
{
    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return [
            ValidationServiceProvider::class,
            WebServiceProvider::class,
            DataServiceProvider::class,
            CqrsServiceProvider::class,
            CqrsWiringProvider::class,
            EdaServiceProvider::class,
            EdaWiringProvider::class,
            SecurityServiceProvider::class,
            SecurityWiringProvider::class,
            ActuatorServiceProvider::class,
            ActuatorWiringProvider::class,
        ];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            // The dev/scan branch: FireflyAutoConfigureServiceProvider scans these roots for the app's
            // #[Component]/#[Configuration] classes (component + context manifests) — no separate provider needed.
            'firefly.scan.paths' => $this->scanPaths(),
            // Security master flag: wires the real SecurityCommandAuthorizer over Cqrs's AllowAllAuthorizer + the
            // AuditorAware, enforcing #[PreAuthorize] at the bus. Each HTTP surface (jwt/http/csrf/headers/oauth2)
            // stays OFF (its own sub-flag), so plain routes still serve and no deny-by-default URL rule applies.
            'firefly.security.enabled' => true,
            // Actuator master gate (default true) — expose health,info for later S-tasks' endpoint assertions.
            'firefly.management.enabled' => true,
            'firefly.management.endpoints.web.exposure.include' => 'health,info',
            // firefly.eda.provider is left UNSET on purpose -> EdaAutoConfiguration binds the REAL InMemoryEventBus
            // as EventPublisher (the memory default). Never a RecordingEventPublisher double.
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        $appPsr4 = $this->appPsr4();

        // (1) #[Transactional] proxies (mirror DataCapstoneTestCase). Generate + LOAD each proxy class inline so
        // TransactionalBeanPostProcessor's class_exists($proxyClass) is satisfied, and compile the manifest to the
        // path LumenTransactionalConfiguration's #[Bean] loads — the ONLY override seam for TransactionalManifest.
        // TransactionTemplate (installed by the proxy) is the sole caller of dispatchAfterCommit(), so without this
        // no domain event ever publishes in S4/S5/S6.
        $scanner = new TransactionalScanner;
        $generator = new ProxyClassGenerator;
        foreach ($scanner->scanProxyMethods($appPsr4) as $target => $methods) {
            $generator->load($target, $methods);
        }
        (new TransactionalManifestCompiler)->write($scanner->scan($appPsr4), LumenTransactionalConfiguration::manifestPath());

        // (2) CQRS handlers (mirror CqrsCapstoneTestCase). Bind the compiled HandlerManifest so #[CommandHandler]/
        // #[QueryHandler] route to the real CommandBus/QueryBus. CqrsWiringProvider::passes() also contributes
        // DomainEventBridgeWiringPass — the committed-DomainEvent -> EventPublisher bridge — with no extra glue.
        $handlers = (new HandlerScanner)->scan($appPsr4);
        $app->instance(HandlerManifest::class, new HandlerManifest($handlers['handlers'], $handlers['destinations']));

        // (3) EDA listeners (mirror EdaCapstoneTestCase). Bind the compiled EventListenerManifest so #[EventListener]s
        // subscribe. The EventPublisher stays the REAL InMemoryEventBus from EdaAutoConfiguration (NOT bound here).
        $app->instance(EventListenerManifest::class, new EventListenerManifest((new EventListenerScanner)->scan($appPsr4)));

        // (4) Method security (mirror SecurityAuthorizerTest). Bind the compiled SecurityMethodManifest; with
        // firefly.security.enabled=true, SecurityAutoConfiguration (#[Order(500)]) wires SecurityCommandAuthorizer as
        // the CommandAuthorizer (over Cqrs's AllowAllAuthorizer), enforcing #[PreAuthorize] at the bus.
        $app->instance(SecurityMethodManifest::class, new SecurityMethodManifest((new MethodSecurityScanner)->scan($appPsr4)));

        // (5) Web (mirror WebCapstoneTestCase). Bind RouteManifest + ConstraintManifest + ExceptionHandlerRegistry so
        // #[RestController] routes, #[Valid] validation, and RFC-7807 problem-details rendering all work.
        $app->instance(RouteManifest::class, new RouteManifest((new RouteScanner)->scan($appPsr4)));
        $app->instance(
            ConstraintManifest::class,
            ConstraintManifest::fromArray((new ConstraintManifestCompiler)->toArray($this->enumerateClasses($appPsr4))),
        );
        $app->instance(
            ExceptionHandlerRegistry::class,
            new ExceptionHandlerRegistry((new RouteScanner)->scanExceptionHandlers($appPsr4)),
        );

        // Actuator's ScheduledTasksEndpoint resolves a ScheduledManifest eagerly during boot; bind the empty stub
        // (no SchedulingWiringProvider is registered, so nothing else binds it on the scan path).
        FireflyBoot::stubScheduledManifest($app);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrate();
    }

    /**
     * Runs the sample application's migrations against the sqlite :memory: connection. A no-op until the domain
     * (S2+) lands its migrations under samples/lumen/database/migrations.
     */
    protected function migrate(): void
    {
        $path = dirname(__DIR__).'/database/migrations';

        if (is_dir($path)) {
            Artisan::call('migrate', ['--path' => $path, '--realpath' => true]);
        }
    }

    /**
     * The app's own PSR-4 root — routes, handlers, listeners, #[Transactional] proxies and method-security rules are
     * scanned from HERE (the application only), never from the harness support namespace.
     *
     * @return array<string, string>
     */
    protected function appPsr4(): array
    {
        return ['Lumen\\' => dirname(__DIR__).'/src'];
    }

    /**
     * Component/context scan roots: the app src PLUS the harness support namespace that carries
     * LumenTransactionalConfiguration (the TransactionalManifest #[Bean] override seam).
     *
     * @return array<string, string>
     */
    protected function scanPaths(): array
    {
        return [
            'Lumen\\' => dirname(__DIR__).'/src',
            'Lumen\\Tests\\Support\\' => __DIR__.'/Support',
        ];
    }

    /**
     * Enumerates every declared class under a PSR-4 map — the constraint class-list source (validation compiles from
     * a class list, not a PSR-4 scan). Mirrors Firefly\Cli\Cache\ClassEnumerator (firefly/cli is not a sample dep).
     *
     * @param  array<string, string>  $psr4
     * @return list<class-string>
     */
    private function enumerateClasses(array $psr4): array
    {
        $classes = [];
        foreach ($psr4 as $prefix => $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            /** @var iterable<\SplFileInfo> $iterator */
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen(rtrim($dir, '/')) + 1, -4);
                $class = rtrim($prefix, '\\').'\\'.str_replace('/', '\\', $relative);
                if (class_exists($class)) {
                    $classes[] = $class;
                }
            }
        }

        return array_values(array_unique($classes));
    }
}
