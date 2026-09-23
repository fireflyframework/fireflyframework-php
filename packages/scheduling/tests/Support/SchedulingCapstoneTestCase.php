<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Tests\Support;

use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Scheduling\Scanner\ScheduledScanner;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Firefly\Scheduling\SchedulingServiceProvider;
use Firefly\Scheduling\SchedulingWiringProvider;
use Firefly\Scheduling\Tests\CapstoneFixtures\SpyCounter;
use Firefly\Testing\FireflyTestCase;
use Illuminate\Foundation\Application;

abstract class SchedulingCapstoneTestCase extends FireflyTestCase
{
    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [
            ResilienceServiceProvider::class,
            SchedulingServiceProvider::class,
            SchedulingWiringProvider::class,
        ];
    }

    /**
     * Select the REAL cache-backed lock (over the array store); the fixture ScheduledManifest is compiled INLINE
     * via the real scanner in defineFireflyEnvironment() below (exactly what firefly:cache emits, M15).
     *
     * @return array<string, mixed>
     */
    protected function configOverrides(): array
    {
        return [
            'cache.default' => 'array',
            'firefly.scheduling.lock.provider' => 'cache',
        ];
    }

    /**
     * Compile the fixture ScheduledManifest INLINE via the real scanner (exactly what firefly:cache emits, M15). The
     * manifest override rides the SchedulingWiringProvider's own bound()-guarded default (mirrors the M6 capstone).
     * SpyCounter is a shared singleton so the autowired ReconcileJob and the assertions observe the same instance.
     * Do NOT instance() a DistributedLock double (see the CRITICAL DI note).
     *
     * NOTE (fixture isolation): the scan targets the dedicated CapstoneFixtures namespace, which lives OUTSIDE the
     * tests/Fixtures tree on purpose. The pre-existing Fixtures/ScheduledJobs fixture (owned by three other
     * scheduling tests, so unremovable) also carries a #[Scheduled] method, and ScheduledScannerTest pins a scan
     * of tests/Fixtures to exactly one descriptor — so a capstone task placed anywhere under Fixtures/ would both
     * inflate this capstone's own count and break that scanner test. A separate CapstoneFixtures/ dir keeps the
     * real scanner over a real fixture PSR-4 while guaranteeing exactly one #[Scheduled] task (ReconcileJob::run).
     */
    protected function defineFireflyEnvironment(Application $app): void
    {
        $descriptors = (new ScheduledScanner)->scan($this->scheduledFixturePsr4());

        $app->instance(ScheduledManifest::class, new ScheduledManifest($descriptors));
        $app->singleton(SpyCounter::class);
    }

    /**
     * The fixture namespace the real scanner is pointed at. It is a method rather than a literal inside
     * defineFireflyEnvironment() so a sibling capstone can aim the SAME boot at a DIFFERENT one-task
     * namespace: the count pinned above (exactly one #[Scheduled] task) is load-bearing for this capstone's
     * own assertions, so a second task must arrive in its own directory, not in this one.
     *
     * @return array<string, string>
     */
    protected function scheduledFixturePsr4(): array
    {
        return ['Firefly\\Scheduling\\Tests\\CapstoneFixtures\\' => __DIR__.'/../CapstoneFixtures'];
    }
}
