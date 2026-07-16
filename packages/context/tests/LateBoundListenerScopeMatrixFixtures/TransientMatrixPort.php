<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\LateBoundListenerScopeMatrixFixtures;

/**
 * Declared return type of a `Scope::Transient` `#[Bean]` factory — an INTERFACE, never scanned.
 * `EagerSingletonsPass` never eagerly resolves a `Scope::Transient` bean, so nothing recovers this
 * listener at boot either.
 */
interface TransientMatrixPort {}
