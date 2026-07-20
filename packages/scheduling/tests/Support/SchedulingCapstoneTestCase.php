<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Tests\Support;

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Scheduling\Scanner\ScheduledScanner;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Firefly\Scheduling\SchedulingServiceProvider;
use Firefly\Scheduling\SchedulingWiringProvider;
use Firefly\Scheduling\Tests\CapstoneFixtures\SpyCounter;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use LogicException;
use Orchestra\Testbench\TestCase;

abstract class SchedulingCapstoneTestCase extends TestCase
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            FireflyAutoConfigureServiceProvider::class,
            ResilienceServiceProvider::class,
            SchedulingServiceProvider::class,
            SchedulingWiringProvider::class,
        ];
    }

    /**
     * A typed, narrowed accessor over the inherited (untyped, protected) `$app` property that only holds a real
     * Application once setUp() has run. Gives Pest test closures a real, non-nullable Application without reaching
     * into a protected property from outside the class (mirrors firefly/context's LaraflyTestCase::laraflyApp()).
     */
    public function capstoneApp(): Application
    {
        if (! $this->app instanceof Application) {
            throw new LogicException('The application has not been booted yet — call this from within a test.');
        }

        return $this->app;
    }

    /**
     * Select the REAL cache-backed lock (over the array store) and compile the fixture ScheduledManifest INLINE
     * via the real scanner (exactly what firefly:cache emits, M15). The manifest override rides the
     * SchedulingWiringProvider's own bound()-guarded default (mirrors the M6 capstone). SpyCounter is a shared
     * singleton so the autowired ReconcileJob and the assertions observe the same instance. Seed config in
     * resolveApplicationConfiguration (NOT defineEnvironment) — testbench runs defineEnvironment AFTER provider
     * registration (M6 capstone lesson). Do NOT instance() a DistributedLock double (see the CRITICAL DI note).
     *
     * NOTE (fixture isolation): the scan targets the dedicated CapstoneFixtures namespace, which lives OUTSIDE the
     * tests/Fixtures tree on purpose. The pre-existing Fixtures/ScheduledJobs fixture (owned by three other
     * scheduling tests, so unremovable) also carries a #[Scheduled] method, and ScheduledScannerTest pins a scan
     * of tests/Fixtures to exactly one descriptor — so a capstone task placed anywhere under Fixtures/ would both
     * inflate this capstone's own count and break that scanner test. A separate CapstoneFixtures/ dir keeps the
     * real scanner over a real fixture PSR-4 while guaranteeing exactly one #[Scheduled] task (ReconcileJob::run).
     *
     * @param  Application  $app
     */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        /** @var Repository $config */
        $config = $app->make('config');
        $config->set('cache.default', 'array');
        $config->set('firefly.scheduling.lock.provider', 'cache');

        $psr4 = ['Firefly\\Scheduling\\Tests\\CapstoneFixtures\\' => __DIR__.'/../CapstoneFixtures'];
        $descriptors = (new ScheduledScanner)->scan($psr4);

        $app->instance(ScheduledManifest::class, new ScheduledManifest($descriptors));
        $app->singleton(SpyCounter::class);
    }
}
