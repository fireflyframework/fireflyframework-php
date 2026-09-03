<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

use Firefly\Container\Attributes\Bean;

/**
 * A #[Bean] source annotated with a CUSTOM #[Configuration] subclass. Discovery
 * of the class itself always worked (the Component gate uses IS_INSTANCEOF);
 * only its bean methods were dropped by the short-name string comparison.
 */
#[ApiConfiguration]
final class ApiBeansConfig
{
    #[Bean('apiToken')]
    public function token(): ApiToken
    {
        return new ApiToken('t-42');
    }
}
