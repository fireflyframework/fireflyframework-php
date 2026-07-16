<?php

declare(strict_types=1);

namespace Firefly\AutoConfigure\Tests\CompileFixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;

#[Configuration]
#[Order(1000)]
final class CompileSampleConfig
{
    #[Bean]
    #[ConditionalOnMissingBean('Firefly\\AutoConfigure\\Tests\\CompileFixtures\\CompileSampleConfig')]
    public function sample(): CompileSampleConfig
    {
        return new CompileSampleConfig;
    }
}
