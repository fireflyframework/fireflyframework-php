<?php

declare(strict_types=1);

namespace Firefly\Eda\Tests\Support;

use Illuminate\Foundation\Application;
use LogicException;
use Orchestra\Testbench\TestCase;

/**
 * Shared testbench base for the QueueEventBus adapter tests. No package providers are registered — both tests
 * construct QueueEventBus by hand and only need a REAL, booted Illuminate\Foundation\Application, so that the real
 * 'bus'/'queue'/'events' bindings back Bus::fake() and the `sync` queue driver exactly as they do at runtime.
 *
 * busApp() is a typed, narrowed accessor over the inherited (untyped, protected) `$app` property — mirrors
 * firefly/context's LaraflyTestCase::laraflyApp(), firefly/scheduling's SchedulingCapstoneTestCase::capstoneApp(),
 * and firefly/data's DataCapstoneTestCase::capstoneApp() — so Pest test closures never reach into a protected
 * property from outside the class (required for PHPStan level max with zero suppressions).
 */
abstract class QueueEventBusTestCase extends TestCase
{
    public function busApp(): Application
    {
        if (! $this->app instanceof Application) {
            throw new LogicException('The testbench application has not booted yet — call this from within a test.');
        }

        return $this->app;
    }
}
