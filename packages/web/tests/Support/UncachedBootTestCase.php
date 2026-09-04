<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Support;

use Firefly\Testing\FireflyTestCase;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;

/**
 * A web testbench that binds NOTHING — the opposite of WebCapstoneTestCase, which hand-binds RouteManifest,
 * ConstraintManifest and ExceptionHandlerRegistry via $app->instance().
 *
 * That hand-binding is why the framework's worst defect survived 1318 green tests: every web test supplied
 * its own manifests, so the path a real application actually takes — WebServiceProvider resolving them
 * itself — was never exercised. An uncached app therefore booted with an empty route table and 404'd every
 * route it owned, and #[ControllerAdvice] was dead in every real boot.
 *
 * Its only configuration is firefly.scan.paths, exactly what the skeleton ships.
 */
abstract class UncachedBootTestCase extends FireflyTestCase
{
    protected function fireflyProviders(): array
    {
        return [ValidationServiceProvider::class, WebServiceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        // No firefly.cache.path and no artifact anywhere: the in-process scan fallback is the only way
        // these routes and advices can be found.
        return ['firefly.scan.paths' => ['Firefly\\Web\\Tests\\Fixtures\\' => dirname(__DIR__).'/Fixtures']];
    }
}
