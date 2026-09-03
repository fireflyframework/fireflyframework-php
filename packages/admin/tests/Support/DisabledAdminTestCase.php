<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support;

/**
 * The dashboard bypasses ExposureModel, so its URL is the only boundary. Disabled must mean no route at
 * all — not a route that renders an empty page.
 */
abstract class DisabledAdminTestCase extends AdminCapstoneTestCase
{
    protected function adminEnabled(): bool
    {
        return false;
    }
}
