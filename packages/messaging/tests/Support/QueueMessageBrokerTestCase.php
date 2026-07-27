<?php

declare(strict_types=1);

namespace Firefly\Messaging\Tests\Support;

use Firefly\Testing\FireflyTestCase;

/**
 * Shared testbench base for the QueueMessageBroker adapter tests. No package providers are registered — both tests
 * construct QueueMessageBroker by hand and only need a REAL, booted Illuminate\Foundation\Application, so that the
 * real 'bus'/'queue'/'config' bindings back Bus::fake() and the `sync` queue driver exactly as they do at runtime.
 *
 * Uses the harness's own app() accessor (identical guarded-null-check semantics) — no package providers, so
 * fireflyProviders() is left at the harness default (empty).
 */
abstract class QueueMessageBrokerTestCase extends FireflyTestCase {}
