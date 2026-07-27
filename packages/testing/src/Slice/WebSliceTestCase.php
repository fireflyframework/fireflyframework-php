<?php

declare(strict_types=1);

namespace Firefly\Testing\Slice;

use Firefly\Container\Scanner\ComponentScanner;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Testing\FireflyTestCase;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Validation\Constraint\ConstraintManifestCompiler;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\Exception\ExceptionHandlerRegistry;
use Firefly\Web\Route\RouteManifest;
use Firefly\Web\Route\RouteScanner;
use Firefly\Web\WebServiceProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Foundation\Application;
use LogicException;

/**
 * A web slice: boots ONLY the passed beans (a PSR-4 scan) + port overrides over the real Web+Validation
 * pipeline (RouteWiringPass), so $this->get(...) serves a real TestResponse against the sliced controllers.
 * The Spring @WebMvcTest analog. Configure via webSlice(); per-test-class.
 */
abstract class WebSliceTestCase extends FireflyTestCase
{
    /** @var array<string,string> */
    protected array $sliceScan = [];

    /** @var array<class-string,object> */
    protected array $sliceOverrides = [];

    protected bool $sliceRequested = false;

    /**
     * @param  array<string,string>  $scan
     * @param  array<class-string,object>  $overrides
     */
    public function webSlice(array $scan = [], array $overrides = []): ApplicationContext
    {
        if ($this->sliceRequested && ($scan !== $this->sliceScan || $overrides !== $this->sliceOverrides)) {
            throw new LogicException('webSlice() is per-test-class; call it once with a single slice definition.');
        }
        $this->sliceScan = $scan;
        $this->sliceOverrides = $overrides;
        $this->sliceRequested = true;

        // See DataSliceTestCase::dataSlice() — same reboot rationale: testbench already booted this app
        // (empty-scoped) in setUp(), before this method runs. refreshApplication() re-runs
        // resolveApplicationConfiguration()/getPackageProviders()/defineFireflyEnvironment() against the
        // $sliceScan/$sliceOverrides just recorded, so the RouteManifest bound below is scanned from the
        // REAL slice roots, not testbench's empty first boot.
        $this->refreshApplication();

        $context = $this->fireflyContext();

        foreach ((new ComponentScanner)->scan($this->sliceScan) as $descriptor) {
            $context->get($descriptor->class);
        }

        return $context;
    }

    protected function fireflyProviders(): array
    {
        return [ValidationServiceProvider::class, WebServiceProvider::class];
    }

    protected function configOverrides(): array
    {
        return ['firefly.scan.paths' => $this->sliceScan];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        // A bare testbench app registers no cache store; the web pipeline resolves one lazily.
        if (! $app->bound(Cache::class)) {
            $app->instance(Cache::class, new CacheRepository(new ArrayStore));
        }

        // plan-review C1: WebServiceProvider only binds an EMPTY default RouteManifest([]) (see
        // packages/web/src/WebServiceProvider.php) — setting firefly.scan.paths (configOverrides()
        // above) only registers the slice's controllers as DI beans; the RouteScanner that
        // RouteWiringPass reads at boot is a SEPARATE scan. Mirroring
        // packages/web/tests/Support/WebCapstoneTestCase.php, the slice must scan+bind its OWN
        // RouteManifest (+ ConstraintManifest/ExceptionHandlerRegistry) over $sliceScan, or every
        // $this->get(...) 404s.
        $app->instance(RouteManifest::class, new RouteManifest((new RouteScanner)->scan($this->sliceScan)));

        // No fixture request DTOs are known to this generic base (ConstraintManifestCompiler compiles
        // from an explicit class list, not a PSR-4 scan, unlike RouteScanner) — bind the same compiled
        // shape WebCapstoneTestCase uses, seeded empty. A slice exercising bean validation overrides this
        // hook to compile its own request DTOs the same way.
        $app->instance(ConstraintManifest::class, ConstraintManifest::fromArray((new ConstraintManifestCompiler)->toArray([])));

        $app->instance(ExceptionHandlerRegistry::class, new ExceptionHandlerRegistry((new RouteScanner)->scanExceptionHandlers($this->sliceScan)));

        foreach ($this->sliceOverrides as $abstract => $instance) {
            $app->instance($abstract, $instance);
        }
    }
}
