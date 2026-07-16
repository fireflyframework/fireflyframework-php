<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\LateBoundListenerScopeMatrixFixtures;

/**
 * Declared return type of a `#[Lazy]` `#[Bean]` factory — an INTERFACE, never scanned. Its listener is
 * only ever recovered by `RegisterBeanPostProcessorsPass`'s late-bound path, which runs only once
 * something resolves the bean — and `#[Lazy]` is precisely the instruction not to, at boot.
 */
interface LazyPort {}
