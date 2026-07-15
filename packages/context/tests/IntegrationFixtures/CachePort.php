<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

/**
 * A hexagonal port with two candidate implementations: UserCache (a real user #[Component]) and
 * DefaultCacheAutoConfig (a stand-in auto-configuration carrying #[ConditionalOnMissingBean]).
 * Proves auto-configuration back-off end-to-end through the real compiled manifest.
 */
interface CachePort {}
