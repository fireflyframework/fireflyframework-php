<?php

declare(strict_types=1);

namespace Firefly\Actuator\Health;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Illuminate\Database\ConnectionResolverInterface;
use Throwable;

/**
 * Validates the default database connection with `SELECT 1`. Uses illuminate's ConnectionResolverInterface DIRECTLY
 * — NO firefly/data dependency, so no Data→Actuator Deptrac edge. A thrown query — a missing sqlite file, a refused
 * connection, a bad password — is caught → DOWN (fail-safe), never a 500, and /actuator/health answers 503.
 *
 * ON BY DEFAULT, like Spring Boot's DataSourceHealthIndicator auto-configuration: the condition has
 * matchIfMissing (only an explicit `firefly.management.endpoint.health.db.enabled=false` removes the bean), and
 * available() plays the part of Spring's @ConditionalOnBean(DataSource) — true only when `database.default`
 * names a connection whose entry has a driver. An application that truly has no database therefore gets no
 * `db` component at all, rather than a DOWN it cannot fix. This used to be opt-in; the CHANGELOG entry for
 * the change carries the one-line opt-out.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.management.endpoint.health.db.enabled', havingValue: 'true', matchIfMissing: true)]
final class DbHealthIndicator implements ConditionalHealthIndicator
{
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly Config $config,
    ) {}

    public function available(): bool
    {
        $default = $this->config->get('database.default');
        if (! is_string($default) || $default === '') {
            return false;
        }

        $driver = $this->config->get("database.connections.{$default}.driver");

        return is_string($driver) && $driver !== '';
    }

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
