<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\LateBoundListenerScopeMatrixFixtures;

/**
 * Declared return type of a `Scope::Scoped` `#[Bean]` factory — an INTERFACE, never scanned.
 * `EagerSingletonsPass` never eagerly resolves a `Scope::Scoped` bean (only eager, non-#[Lazy]
 * `Scope::Singleton`), so nothing recovers this listener at boot either.
 */
interface ScopedPort {}
