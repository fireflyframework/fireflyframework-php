<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Support;

use Firefly\Actuator\ActuatorServiceProvider;
use Firefly\Actuator\ActuatorWiringProvider;
use Firefly\Cli\Boot\FireflyCacheServiceProvider;
use Firefly\Cli\Cache\CacheReport;
use Firefly\Cli\Cache\FireflyCachePaths;
use Firefly\Cli\Cache\ManifestCacheWriter;
use Firefly\Cli\CliServiceProvider;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Data\DataServiceProvider;
use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\Security\SecurityServiceProvider;
use Firefly\Security\SecurityWiringProvider;
use Firefly\Testing\FireflyDatabaseTestCase;
use Firefly\Testing\Fixture\ListenerSpy;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;

/**
 * The Foundation-finale harness: boots the WHOLE stack (DI + config + web + validation + data + transactions +
 * CQRS + events + security + actuator) over testbench on the CACHED zero-reflection path — the compiled manifests
 * are emitted ONCE by the real ManifestCacheWriter (exactly what `artisan firefly:cache` runs) and the app is booted
 * with FireflyCacheServiceProvider binding them + NO firefly.scan.paths (so FireflyAutoConfigureServiceProvider takes
 * the ::load() branch, never a scan). Extends FireflyDatabaseTestCase for the sqlite :memory: connection the
 * repository derived query + #[Transactional] commit run against. Named support base (NOT an anon-class uses()).
 */
abstract class FoundationFlowTestCase extends FireflyDatabaseTestCase
{
    /** The temp dir the compiled manifests are emitted into (shared across the class' tests). */
    public static ?string $cacheDir = null;

    /** The real compile report (proves proxyCount > 0). */
    public static ?CacheReport $report = null;

    /** @return array<string,string> */
    public static function foundationPsr4(): array
    {
        return ['Firefly\\Cli\\Tests\\Fixtures\\Foundation\\' => dirname(__DIR__).'/Fixtures/Foundation'];
    }

    protected function setUp(): void
    {
        if (self::$cacheDir === null) {
            self::$cacheDir = sys_get_temp_dir().'/firefly-foundation-'.bin2hex(random_bytes(6));
            self::$report = (new ManifestCacheWriter)->write(self::foundationPsr4(), self::$cacheDir);
        }

        // The Category-C CachedTransactionalConfiguration reads the manifest dir from this env (a fixture has no
        // fixed base_path()); set BEFORE the boot so its #[Bean] loads the compiled transactional.php.
        putenv('FIREFLY_CACHE_DIR='.self::$cacheDir);

        parent::setUp();

        // The `widgets` table backing WidgetRepository (derived query) + FoundationWriter (#[Transactional] commit).
        Schema::create('widgets', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('status');
            $table->integer('amount');
        });
    }

    /** @return list<class-string> */
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
            CliServiceProvider::class,
            // Last: unconditional $app->instance() overrides win over every *WiringProvider's bound()-guarded
            // empty default regardless of order, and its register() runs before any boot pass resolves a bean.
            FireflyCacheServiceProvider::class,
        ];
    }

    /** @return array<string,mixed> */
    protected function configOverrides(): array
    {
        $dir = self::$cacheDir ?? '';

        return [
            // Cached zero-reflection path: point at the compiled manifests + NO firefly.scan.paths.
            'firefly.cache.path' => $dir,
            'firefly.cache.component_manifest' => $dir.'/'.FireflyCachePaths::COMPONENT,
            'firefly.cache.context_manifest' => $dir.'/'.FireflyCachePaths::CONTEXT,
            // #[ConfigProperties] seed that DIFFERS from FoundationProperties' constructor default ('default-greeting')
            // — so the assertion distinguishes "populated FROM config" from a bare autowired default.
            'foundation.greeting' => 'from-config-value',
            // eda in-memory broker.
            'firefly.eda.provider' => 'memory',
            // security master flag: wires the real MethodSecurityControllerGuard. The HTTP filters stay INERT — each
            // is #[ConditionalOnProperty] on its OWN surface flag (jwt/http/csrf/headers/oauth2), all left off — so
            // plain routes still serve and no deny-by-default URL rule applies.
            'firefly.security.enabled' => true,
            // actuator: expose health,info; enable the DB indicator so /health aggregates UP over the sqlite conn.
            'firefly.management.enabled' => true,
            'firefly.management.endpoint.health.db.enabled' => true,
            'firefly.management.endpoints.web.exposure.include' => 'health,info',
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        // ListenerSpy is not a #[Component]; bind it as a singleton so WidgetEventListener + the test share one.
        $app->singleton(ListenerSpy::class);
    }
}
