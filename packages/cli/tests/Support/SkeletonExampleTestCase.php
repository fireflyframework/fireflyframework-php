<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Support;

use Firefly\Cli\Boot\FireflyCacheServiceProvider;
use Firefly\Cli\Cache\CacheReport;
use Firefly\Cli\Cache\FireflyCachePaths;
use Firefly\Cli\Cache\ManifestCacheWriter;
use Firefly\Cli\CliServiceProvider;
use Firefly\Testing\FireflyTestCase;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Boots the SHIPPED skeleton application — skeleton/app, exactly as a developer receives it — over the
 * CACHED zero-reflection path, so the monorepo's default gate proves the example actually works.
 *
 * The compile in setUp() is the real ManifestCacheWriter, i.e. literally what `php artisan firefly:cache`
 * runs, pointed at the skeleton's own PSR-4 root. That is the assertion nobody was making: the skeleton is a
 * separate composer package, its tests/ directory is not in any suite the monorepo runs, and the one test
 * that did touch it (CreateProjectOfflineTest) lives in the excluded `createproject` group and only checks
 * that a routes manifest file EXISTS. A sample controller could be renamed, a DTO could stop hydrating, a
 * constraint could stop compiling, and every gate would still be green while `composer create-project`
 * handed the next user a broken example.
 *
 * Booting on the CACHED path rather than letting the app scan is deliberate for the same reason: the
 * skeleton's own composer.json runs `firefly:cache` in post-create-project-cmd, so the cached path is the
 * one a real created application actually boots on, and it is the path where a manifest that failed to
 * compile shows up as a missing route rather than as a silent fallback to reflection.
 *
 * Named support base (NOT an anonymous-class uses()): Pest's uses() takes a class-string, and evaluating
 * `new class extends FireflyTestCase {...}::class` constructs the class immediately, which throws before
 * Pest can bind it — the same fix already applied in MakeCommandsTestCase and friends.
 */
abstract class SkeletonExampleTestCase extends FireflyTestCase
{
    /** The temp dir the compiled manifests are emitted into (shared across the class' tests). */
    public static ?string $cacheDir = null;

    /** The real compile report, so a test can assert the compile itself did something. */
    public static ?CacheReport $report = null;

    protected function setUp(): void
    {
        SkeletonApp::register();

        if (self::$cacheDir === null) {
            self::$cacheDir = sys_get_temp_dir().'/firefly-skeleton-'.bin2hex(random_bytes(6));
            // Uncaught by design: if compiling the SHIPPED example throws, that is the headline failure and
            // it must read as one, not as a cascade of "route not found" further down.
            self::$report = (new ManifestCacheWriter)->write(SkeletonApp::psr4(), self::$cacheDir);
        }

        parent::setUp();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$cacheDir !== null) {
            foreach (glob(self::$cacheDir.'/'.FireflyCachePaths::PROXY_DIR.'/*.php') ?: [] as $file) {
                unlink($file);
            }
            @rmdir(self::$cacheDir.'/'.FireflyCachePaths::PROXY_DIR);
            foreach (glob(self::$cacheDir.'/*.php') ?: [] as $file) {
                unlink($file);
            }
            @rmdir(self::$cacheDir);
            self::$cacheDir = null;
            self::$report = null;
        }

        parent::tearDownAfterClass();
    }

    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return [
            ValidationServiceProvider::class,
            WebServiceProvider::class,
            CliServiceProvider::class,
            // Last: its unconditional $app->instance() overrides beat every *WiringProvider's bound()-guarded
            // empty default regardless of ordering, and register() runs before any boot pass resolves a bean.
            FireflyCacheServiceProvider::class,
        ];
    }

    /** @return array<string,mixed> */
    protected function configOverrides(): array
    {
        $dir = self::$cacheDir ?? '';

        return [
            // The cached zero-reflection path: point at the compiled manifests and set NO firefly.scan.paths,
            // so FireflyAutoConfigureServiceProvider takes the ::load() branch and never scans.
            'firefly.cache.path' => $dir,
            'firefly.cache.component_manifest' => $dir.'/'.FireflyCachePaths::COMPONENT,
            'firefly.cache.context_manifest' => $dir.'/'.FireflyCachePaths::CONTEXT,
            // Three tests here provoke a 404 or a 422 ON PURPOSE, and Laravel's exception handler logs each
            // one with a full stack trace. FireflyTestCase's filesystem-free `errorlog` channel writes that
            // to STDERR, which buries the suite's actual output under ~60 frames per deliberate failure.
            // Raising the level keeps the channel (so a genuine emergency still surfaces) while silencing the
            // errors these tests are asserting the existence of.
            'logging.channels.errorlog' => ['driver' => 'errorlog', 'level' => 'emergency'],
        ];
    }
}
