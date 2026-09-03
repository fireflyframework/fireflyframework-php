<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\CompetingBeanFixtures;

/**
 * The event both competing CachePort products listen for. Every surviving bean must hear it: a
 * listener that silently never registers is precisely the failure mode this fixture set exists to
 * catch.
 */
final class CachePing {}
