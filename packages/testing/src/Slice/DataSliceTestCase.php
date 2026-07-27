<?php

declare(strict_types=1);

namespace Firefly\Testing\Slice;

use Firefly\Container\Scanner\ComponentScanner;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Data\DataServiceProvider;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Foundation\Application;
use LogicException;

/**
 * A data slice: boots ONLY the passed beans (a PSR-4 scan) + port overrides over the real Data pipeline,
 * then fail-fast resolves every scanned bean so a mis-wired slice fails immediately. The Spring @DataJpaTest
 * analog. Configure via dataSlice(); it is per-test-class (a second call with different args throws).
 */
abstract class DataSliceTestCase extends FireflyDatabaseTestCase
{
    /** @var array<string,string> */
    protected array $sliceScan = [];

    /** @var array<class-string,object> */
    protected array $sliceOverrides = [];

    protected bool $sliceRequested = false;

    /**
     * @param  array<string,string>  $scan  PSR-4 prefix => dir of the ONLY beans this slice discovers
     * @param  array<class-string,object>  $overrides  port fakes bound in place of real adapters
     */
    public function dataSlice(array $scan = [], array $overrides = []): ApplicationContext
    {
        if ($this->sliceRequested && ($scan !== $this->sliceScan || $overrides !== $this->sliceOverrides)) {
            throw new LogicException('dataSlice() is per-test-class; call it once with a single slice definition.');
        }
        $this->sliceScan = $scan;
        $this->sliceOverrides = $overrides;
        $this->sliceRequested = true;

        // Testbench already booted this app (empty-scoped) in setUp(), BEFORE this method ever runs, so
        // the scan/overrides just recorded above are invisible to that already-running app. Reboot for
        // real: refreshApplication() re-runs resolveApplicationConfiguration() (re-seeds
        // firefly.scan.paths from $sliceScan), re-registers providers (re-memoizing the AutoConfigure
        // scan), and re-runs defineFireflyEnvironment() (rebinding $sliceOverrides) against the values
        // set above.
        $this->refreshApplication();

        $context = $this->fireflyContext();

        foreach ((new ComponentScanner)->scan($this->sliceScan) as $descriptor) {
            $context->get($descriptor->class); // fail-fast: an unresolvable sliced bean throws here
        }

        return $context;
    }

    protected function fireflyProviders(): array
    {
        return [DataServiceProvider::class];
    }

    protected function configOverrides(): array
    {
        return ['firefly.scan.paths' => $this->sliceScan];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        foreach ($this->sliceOverrides as $abstract => $instance) {
            $app->instance($abstract, $instance);
        }
    }
}
