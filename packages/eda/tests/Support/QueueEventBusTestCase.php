<?php

declare(strict_types=1);

namespace Firefly\Eda\Tests\Support;

use Firefly\Testing\FireflyTestCase;

/**
 * Shared testbench base for the QueueEventBus adapter tests. No package providers are registered — both tests
 * construct QueueEventBus by hand and only need a REAL, booted Illuminate\Foundation\Application, so that the real
 * 'bus'/'queue'/'events' bindings back Bus::fake() and the `sync` queue driver exactly as they do at runtime.
 *
 * Uses the harness's own app() accessor (identical guarded-null-check semantics) — no package providers, so
 * fireflyProviders() is left at the harness default (empty).
 */
abstract class QueueEventBusTestCase extends FireflyTestCase {}
