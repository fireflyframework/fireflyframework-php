<?php

declare(strict_types=1);

namespace Firefly\Actuator\Health;

/**
 * A HealthIndicator that can decline to be registered at all. Spring gates its auto-configured indicators on
 * the presence of the thing they probe (a DataSource bean, a MailSender bean); Laravel has no bean to test
 * for — a database is configuration — so an indicator answers the same question itself, once, at boot.
 * HealthContributorRegistrar skips one whose available() is false: no component in /health, not DOWN, not
 * UNKNOWN. The precedent is ActuatorEndpoint::enabled(). HealthIndicator itself is unchanged, so every
 * existing indicator keeps working.
 */
interface ConditionalHealthIndicator extends HealthIndicator
{
    public function available(): bool;
}
