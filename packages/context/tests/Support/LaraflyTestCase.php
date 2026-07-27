<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\Support;

use Firefly\Testing\FireflyTestCase;
use Illuminate\Support\ServiceProvider;

/**
 * Shared testbench base for the (small) set of firefly/context tests that need a REAL, booted
 * Illuminate\Foundation\Application rather than a bare Illuminate\Container\Container — namely
 * the Octane tests (which fire real Laravel\Octane\Events\* at a real app). Dogfoods
 * firefly/testing's harness base: FireflyTestCase already supplies the guarded `app(): Application`
 * accessor these tests used to hand-roll as `laraflyApp()`.
 *
 * Deliberately minimal: no extra Firefly capability providers beyond the harness's own
 * always-prepended FireflyAutoConfigureServiceProvider. See
 * docs/superpowers/specs/2026-07-15-m4-context-design-decisions.md ("Octane" section) for why a
 * REAL Octane test — not one built on recollection of Octane's event shapes — is required here.
 */
abstract class LaraflyTestCase extends FireflyTestCase
{
    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return [];
    }
}
