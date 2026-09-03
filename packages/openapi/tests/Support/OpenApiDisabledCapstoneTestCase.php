<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\Support;

/**
 * The master gate OFF. A separate boot, not a config()->set(), because `firefly.openapi.enabled` is read by
 * OpenApiRouteRegistrar at BOOT time — see the parent's docblock.
 */
abstract class OpenApiDisabledCapstoneTestCase extends OpenApiCapstoneTestCase
{
    protected function openApiEnabled(): bool
    {
        return false;
    }
}
