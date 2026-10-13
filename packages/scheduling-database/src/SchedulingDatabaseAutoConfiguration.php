<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Database;

use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;

/**
 * Supplies the database-backed DistributedLock when firefly.scheduling.lock.provider is `database` or `mysql`.
 * #[Order(900)] is lower than SchedulingAutoConfiguration's 1000 for the reason PgAdvisoryLockAutoConfiguration
 * documents: the more specific provider must register DistributedLock first so scheduling's
 * #[ConditionalOnMissingBean] backs off deterministically. The beans arrive with the locks they build.
 */
#[Configuration]
#[Order(900)]
final class SchedulingDatabaseAutoConfiguration {}
