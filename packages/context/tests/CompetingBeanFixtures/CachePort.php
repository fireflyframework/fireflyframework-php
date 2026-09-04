<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\CompetingBeanFixtures;

/**
 * The canonical hexagonal shape (`#[Bean] fn(): CachePort`) produced by TWO competing #[Bean]
 * methods. Declared as an INTERFACE deliberately: ContextScanner never scans an interface, so
 * `ContextManifest::forClass(CachePort::class)` is structurally null and the #[AsEventListener]
 * methods on the two concrete products can only ever be recovered through
 * RegisterBeanPostProcessorsPass's composite extender — the exact path that has to attach ONCE PER
 * COMPETING BEAN rather than once per type.
 */
interface CachePort
{
    public function name(): string;
}
