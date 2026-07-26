<?php

declare(strict_types=1);

namespace Firefly\Actuator\Health;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;

/**
 * Reports UP while the free disk space at the configured path stays above a byte threshold (closes a pyfly gap).
 * firefly.management.endpoint.health.diskspace.path (default the CWD) + .threshold (default 10485760 = 10MB).
 */
#[Component]
final class DiskSpaceHealthIndicator implements HealthIndicator
{
    public function __construct(private readonly Config $config) {}

    public function health(): Health
    {
        $path = $this->config->string('firefly.management.endpoint.health.diskspace.path', getcwd() ?: '.');
        $threshold = $this->config->int('firefly.management.endpoint.health.diskspace.threshold', 10_485_760);

        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false) {
            return Health::down(['path' => $path, 'error' => 'unable to determine disk space']);
        }

        $details = ['total' => (int) $total, 'free' => (int) $free, 'threshold' => $threshold, 'path' => $path];

        return $free >= $threshold ? Health::up($details) : Health::down($details);
    }
}
