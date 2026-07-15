<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\Support;

use Orchestra\Testbench\TestCase;

/**
 * Shared testbench base for the (small) set of firefly/context tests that need a REAL, booted
 * Illuminate\Foundation\Application rather than a bare Illuminate\Container\Container — namely
 * the Octane tests (which fire real Laravel\Octane\Events\* at a real app) and the
 * FireflyServiceProvider tests (which need Illuminate's real booting()/booted() callback
 * ordering, not a hand-rolled stand-in for it).
 *
 * Deliberately minimal: no package discovery, no extra service providers. See
 * docs/superpowers/specs/2026-07-15-m4-context-design-decisions.md ("Octane" section) for why a
 * REAL Octane test — not one built on recollection of Octane's event shapes — is required here.
 */
abstract class LaraflyTestCase extends TestCase {}
