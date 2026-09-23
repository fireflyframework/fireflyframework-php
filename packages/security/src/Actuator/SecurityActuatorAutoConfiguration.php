<?php

declare(strict_types=1);

namespace Firefly\Security\Actuator;

use Firefly\Actuator\Health\HealthDetailsAuthorizer;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnClass;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\Access\RoleHierarchy;

/**
 * The one bean firefly/security contributes to firefly/actuator, in its own #[Configuration] so the whole
 * class — and therefore every reflection of its signatures — is skipped when firefly/actuator is not
 * installed (#[ConditionalOnClass], the OpenTelemetryAutoConfiguration idiom; firefly/actuator is a
 * `suggest` of this package, not a require, because a secured API with no diagnostics surface is an
 * ordinary deployment).
 *
 * #[Order(400)] is DELIBERATELY below ActuatorAutoConfiguration's, so this registers first and actuator's
 * own #[ConditionalOnMissingBean(HealthDetailsAuthorizer)] backs its DenyHealthDetailsAuthorizer off — the
 * same precedence MeterRegistryCqrsMetrics uses against CQRS's NoOp. When the security master flag is off,
 * this bean is conditioned away and the deny default stands, which is the behaviour every existing
 * deployment already has.
 */
#[Configuration]
#[Order(400)]
#[ConditionalOnClass(HealthDetailsAuthorizer::class)]
final class SecurityActuatorAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(HealthDetailsAuthorizer::class)]
    public function healthDetailsAuthorizer(Config $config, RoleHierarchy $roles): HealthDetailsAuthorizer
    {
        return new PrincipalHealthDetailsAuthorizer($config, $roles);
    }
}
