<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Database;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Scheduling\Lock\DistributedLock;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Supplies the database-backed DistributedLock when firefly.scheduling.lock.provider is `database` (or, from the next
 * bean on, `mysql`). #[Order(900)] is lower than SchedulingAutoConfiguration's 1000 for the reason
 * PgAdvisoryLockAutoConfiguration documents: the more specific provider must register DistributedLock first so
 * scheduling's #[ConditionalOnMissingBean] backs off deterministically, whatever order the providers registered in.
 */
#[Configuration]
#[Order(900)]
final class SchedulingDatabaseAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.scheduling.lock.provider', havingValue: 'database')]
    public function databaseLeaseLock(Config $config, ConnectionResolverInterface $connections): DistributedLock
    {
        // A key that is present but null (an unset env()) or blank means the default connection, the rule
        // DatabaseOutboxSettings follows: Config::has() alone would hand Config::string() a null and fail the boot.
        $connection = $config->get('firefly.scheduling.lock.database.connection') === null
            ? null
            : $config->string('firefly.scheduling.lock.database.connection');

        return new DatabaseLeaseLock($connections, $connection === '' ? null : $connection);
    }
}
