<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Support;

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Validation\Constraint\ConstraintManifestCompiler;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\Exception\ExceptionHandlerRegistry;
use Firefly\Web\Route\RouteManifest;
use Firefly\Web\Route\RouteScanner;
use Firefly\Web\Tests\Fixtures\CreateAccountRequest;
use Firefly\Web\WebServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;

abstract class WebCapstoneTestCase extends TestCase
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [FireflyAutoConfigureServiceProvider::class, ValidationServiceProvider::class, WebServiceProvider::class];
    }

    /** @return array<string,string> PSR-4 map of the Filters subdir the REAL component scan discovers. */
    private function filtersPsr4(): array
    {
        return ['Firefly\\Web\\Tests\\Fixtures\\Filters\\' => dirname(__DIR__).'/Fixtures/Filters'];
    }

    /**
     * Seed `firefly.scan.paths` HERE — the config-resolution seam that runs BEFORE testbench registers the
     * package providers — rather than in defineEnvironment(), which testbench (11.x) runs AFTER provider
     * registration. FireflyAutoConfigureServiceProvider memoizes its component scan at register() time, so the
     * scan roots must already be visible in config by then. In a real app this key lives in a config file that
     * LoadConfiguration reads before providers register (the exact ordering the monorepo's own
     * AutoConfigurationEndToEndTest relies on); this override reproduces that ordering for testbench. It drives
     * the REAL scan (no hand-wired filter beans): the Filters subdir holds the two #[Component] #[Order]-ed
     * RecordingFilters (FirstFilter/SecondFilter carry no stereotype, so are ignored), which FilterChainRegistrar
     * then sorts after the framework filters. If a future testbench renames this seam the override simply stops
     * running and the filter-trail assertion fails loudly — never a silent pass.
     *
     * @param  Application  $app
     */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        // Larastan types make('config') as the concrete Illuminate\Config\Repository, so no instanceof narrowing
        // is needed (nor allowed — it would be an always-true check).
        $app->make('config')->set('firefly.scan.paths', $this->filtersPsr4());
    }

    /**
     * Compile the fixture route + constraint manifests INLINE and bind them, exactly as design §5 mandates
     * until firefly:cache (M15). This is the real production RouteManifest/ConstraintManifest path — the
     * scanner runs only here, at "cache time". These instance() bindings are resolved LAZILY (RouteWiringPass
     * at boot, BeanValidator/dispatcher per request), all AFTER defineEnvironment runs, so overriding the
     * provider's empty defaults here is timely — unlike firefly.scan.paths, whose scan is eager (see above).
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $psr4 = ['Firefly\\Web\\Tests\\Fixtures\\' => dirname(__DIR__).'/Fixtures'];
        $advicePsr4 = ['Firefly\\Web\\Tests\\Fixtures\\Advice\\' => dirname(__DIR__).'/Fixtures/Advice'];

        // The RouteManifest scans the WHOLE fixtures tree (recursive), so the /errors routes on the Advice
        // subdir's ConflictController are registered alongside the /balances routes.
        $app->instance(RouteManifest::class, new RouteManifest((new RouteScanner)->scan($psr4)));
        $app->instance(
            ConstraintManifest::class,
            ConstraintManifest::fromArray((new ConstraintManifestCompiler)->toArray([CreateAccountRequest::class])),
        );

        // Exception handlers are scanned ONLY from the Advice subdir: the controller-LOCAL handler on
        // ConflictController (CustomBusinessException) + the GLOBAL CapstoneAdvice handler (AnotherException).
        // AccountAdvice lives in the fixtures ROOT, so it is NOT scanned — ResourceNotFoundException therefore
        // has no handler and renders as RFC-7807. This overrides WebServiceProvider's empty-registry default.
        $app->instance(
            ExceptionHandlerRegistry::class,
            new ExceptionHandlerRegistry((new RouteScanner)->scanExceptionHandlers($advicePsr4)),
        );
    }
}
