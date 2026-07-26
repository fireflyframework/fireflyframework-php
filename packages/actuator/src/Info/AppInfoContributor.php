<?php

declare(strict_types=1);

namespace Firefly\Actuator\Info;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;

/** Surfaces firefly.management.info.app.* under the `app` key. */
#[Component]
final class AppInfoContributor implements InfoContributor
{
    public function __construct(private readonly Config $config) {}

    /**
     * @return array<string, mixed>
     */
    public function info(): array
    {
        if (! $this->config->has('firefly.management.info.app')) {
            return [];
        }

        return ['app' => $this->config->array('firefly.management.info.app')];
    }
}
