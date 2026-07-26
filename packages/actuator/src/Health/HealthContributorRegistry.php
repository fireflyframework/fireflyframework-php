<?php

declare(strict_types=1);

namespace Firefly\Actuator\Health;

/** name → HealthIndicator, populated at boot by HealthContributorRegistrar, read at request time by HealthEndpoint. */
final class HealthContributorRegistry
{
    /** @var array<string, HealthIndicator> */
    private array $indicators = [];

    public function register(string $name, HealthIndicator $indicator): void
    {
        $this->indicators[$name] = $indicator;
    }

    /**
     * @return array<string, HealthIndicator>
     */
    public function all(): array
    {
        return $this->indicators;
    }
}
