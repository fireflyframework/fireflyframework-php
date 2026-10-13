<?php

declare(strict_types=1);

namespace Firefly\Eda\Database;

use Firefly\Actuator\Health\Health;
use Firefly\Actuator\Health\HealthIndicator;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Eda\Database\Outbox\Claim\OutboxClaimStrategies;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Illuminate\Database\ConnectionResolverInterface;
use Throwable;

/**
 * The outbox's health, registered as `outbox`: UP when its connection answers, the table is readable and the
 * configured claim strategy resolves against this server — with the driver, the strategy actually in use and the
 * PENDING backlog as details; DOWN with the error otherwise (an unsupported forced strategy included). OPT-IN like
 * every broker indicator: only active under firefly.eda.provider=database, so installing the package never puts a
 * database the app does not use as its outbox on /actuator/health.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'database')]
final class OutboxHealthIndicator implements HealthIndicator
{
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly Config $config,
    ) {}

    public function health(): Health
    {
        try {
            $settings = DatabaseOutboxSettings::from($this->config);
            $connection = $settings->connectionFrom($this->connections);

            return Health::up([
                'broker' => 'database',
                'driver' => $connection->getDriverName(),
                'claim-strategy' => OutboxClaimStrategies::resolve($connection, $settings->claimStrategy)->name(),
                'pending' => $connection->table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PENDING)->count(),
            ]);
        } catch (Throwable $e) {
            return Health::down(['broker' => 'database', 'error' => $e->getMessage()]);
        }
    }
}
