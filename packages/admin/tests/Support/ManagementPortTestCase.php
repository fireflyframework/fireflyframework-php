<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support;

/**
 * The dashboard with a management port configured that the test requests do NOT arrive on.
 *
 * Testbench serves through the HTTP kernel without a real listener, so the arrival port is whatever the
 * request reports — which is not 9001. That is exactly the shape being asserted: a request that did not
 * come in on the management port must not reach the dashboard.
 */
abstract class ManagementPortTestCase extends AdminCapstoneTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'firefly.management.server.port' => 9001];
    }
}
