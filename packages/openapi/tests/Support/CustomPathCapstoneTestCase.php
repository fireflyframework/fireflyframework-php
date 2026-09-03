<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\Support;

/**
 * The whole reason this package mounts its routes from a BootPass instead of shipping a #[RestController]:
 * an attribute route bakes its literal path into the compiled RouteDescriptor, so no amount of configuration
 * could move it. Here both paths are relocated under /docs, and both must answer there and nowhere else.
 */
abstract class CustomPathCapstoneTestCase extends OpenApiCapstoneTestCase
{
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.openapi.path' => '/docs/api.json',
            'firefly.openapi.viewer.path' => '/docs',
        ];
    }
}
