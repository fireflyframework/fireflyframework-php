<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\Support;

/**
 * The viewer OFF while the machine-readable document stays ON — the deployment that wants a spec for its CI
 * client generator but no browsable console on the public surface. Its own boot, same reason as the parent.
 */
abstract class ViewerDisabledCapstoneTestCase extends OpenApiCapstoneTestCase
{
    protected function viewerEnabled(): bool
    {
        return false;
    }
}
