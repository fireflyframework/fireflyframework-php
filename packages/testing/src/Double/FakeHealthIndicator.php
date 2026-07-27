<?php

declare(strict_types=1);

namespace Firefly\Testing\Double;

use Firefly\Actuator\Health\Health;
use Firefly\Actuator\Health\HealthIndicator;
use Firefly\Actuator\Health\Status;

/** A programmable HealthIndicator — defaults to UP; setHealth() drives DOWN/OUT_OF_SERVICE scenarios. */
final class FakeHealthIndicator implements HealthIndicator
{
    public function __construct(private Health $health = new Health(Status::Up)) {}

    public function setHealth(Health $health): self
    {
        $this->health = $health;

        return $this;
    }

    public function health(): Health
    {
        return $this->health;
    }
}
