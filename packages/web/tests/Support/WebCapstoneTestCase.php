<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Support;

use Firefly\Testing\FireflyTestCase;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Validation\Constraint\ConstraintManifestCompiler;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\Exception\ExceptionHandlerRegistry;
use Firefly\Web\Route\RouteManifest;
use Firefly\Web\Route\RouteScanner;
use Firefly\Web\Tests\Fixtures\CreateAccountRequest;
use Firefly\Web\WebServiceProvider;
use Illuminate\Foundation\Application;

abstract class WebCapstoneTestCase extends FireflyTestCase
{
    protected function fireflyProviders(): array
    {
        return [ValidationServiceProvider::class, WebServiceProvider::class];
    }

    protected function configOverrides(): array
    {
        return ['firefly.scan.paths' => $this->filtersPsr4()];
    }

    /**
     * Explicit binds (plan-review Minor1) — the SAME three instance() bindings the pre-refactor
     * defineEnvironment() carried. FireflyTestCase now supplies the boilerplate this base used to
     * hand-roll (provider ordering, the eager config seed, the log channel), but the route pipeline
     * bindings are this package's own and must stay explicit here, or the capstone silently regresses to
     * WebServiceProvider's empty RouteManifest([])/ConstraintManifest([])/ExceptionHandlerRegistry([])
     * defaults and every fixture route 404s — the exact failure mode plan-review C1 caught in the
     * generic WebSliceTestCase (packages/testing/src/Slice/WebSliceTestCase.php, Task 11).
     */
    protected function defineFireflyEnvironment(Application $app): void
    {
        $psr4 = ['Firefly\\Web\\Tests\\Fixtures\\' => dirname(__DIR__).'/Fixtures'];
        $advicePsr4 = ['Firefly\\Web\\Tests\\Fixtures\\Advice\\' => dirname(__DIR__).'/Fixtures/Advice'];

        // The RouteManifest scans the WHOLE fixtures tree (recursive), so the /errors routes on the
        // Advice subdir's ConflictController are registered alongside the /balances routes.
        $app->instance(RouteManifest::class, new RouteManifest((new RouteScanner)->scan($psr4)));
        $app->instance(
            ConstraintManifest::class,
            ConstraintManifest::fromArray((new ConstraintManifestCompiler)->toArray([CreateAccountRequest::class])),
        );

        // Exception handlers are scanned ONLY from the Advice subdir: the controller-LOCAL handler on
        // ConflictController (CustomBusinessException) + the GLOBAL CapstoneAdvice handler
        // (AnotherException). AccountAdvice lives in the fixtures ROOT, so it is NOT scanned —
        // ResourceNotFoundException therefore has no handler and renders as RFC-7807. This overrides
        // WebServiceProvider's empty-registry default.
        $app->instance(
            ExceptionHandlerRegistry::class,
            new ExceptionHandlerRegistry((new RouteScanner)->scanExceptionHandlers($advicePsr4)),
        );
    }

    /** @return array<string,string> */
    private function filtersPsr4(): array
    {
        return ['Firefly\\Web\\Tests\\Fixtures\\Filters\\' => dirname(__DIR__).'/Fixtures/Filters'];
    }
}
