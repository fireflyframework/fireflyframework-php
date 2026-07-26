<?php

declare(strict_types=1);

namespace Firefly\Actuator\Health;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Illuminate\Database\ConnectionResolverInterface;
use Throwable;

/**
 * Validates the default database connection with `SELECT 1`. Uses illuminate's ConnectionResolverInterface DIRECTLY
 * — NO firefly/data dependency, so no Data→Actuator Deptrac edge. OPT-IN (OFF by default, no matchIfMissing): a
 * DB-less skeleton's /health stays UP (from Ping) with no surprise 503; enable with
 * firefly.management.endpoint.health.db.enabled=true. A thrown query is caught → DOWN (fail-safe), never a 500.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.management.endpoint.health.db.enabled', havingValue: 'true')]
final class DbHealthIndicator implements HealthIndicator
{
    public function __construct(private readonly ConnectionResolverInterface $connections) {}

    public function health(): Health
    {
        try {
            $connection = $this->connections->connection();
            $connection->selectOne('select 1 as ok');

            $driverName = method_exists($connection, 'getDriverName') ? $connection->getDriverName() : null;
            $driver = is_string($driverName) ? $driverName : 'unknown';

            return Health::up(['database' => $driver]);
        } catch (Throwable $e) {
            return Health::down(['error' => $e::class.': '.$e->getMessage()]);
        }
    }
}
