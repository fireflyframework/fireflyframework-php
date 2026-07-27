<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres;

use Firefly\Actuator\Health\Health;
use Firefly\Actuator\Health\HealthIndicator;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Illuminate\Database\ConnectionResolverInterface;
use Throwable;

/**
 * Confirms the outbox connection answers `select 1`; DOWN with the error detail otherwise. Registered as `postgres`.
 * OPT-IN (mirrors DbHealthIndicator's / RabbitMqHealthIndicator's precedent): only active when
 * firefly.eda.provider=postgres, so installing this package without selecting it as the active eda provider never
 * blocks /actuator/health on a database the app isn't using as its outbox broker.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'postgres')]
final class PostgresHealthIndicator implements HealthIndicator
{
    public function __construct(private readonly ConnectionResolverInterface $connections) {}

    public function health(): Health
    {
        try {
            $this->connections->connection()->select('select 1 as ok');

            return Health::up(['broker' => 'postgres']);
        } catch (Throwable $e) {
            return Health::down(['broker' => 'postgres', 'error' => $e->getMessage()]);
        }
    }
}
