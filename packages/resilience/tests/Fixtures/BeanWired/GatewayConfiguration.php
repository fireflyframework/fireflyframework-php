<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\BeanWired;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;

/** The #[Bean] method that wires the gateway — a CONCRETE return type, which is what the chain keys on. */
#[Configuration]
class GatewayConfiguration
{
    #[Bean]
    public function gateway(): BeanWiredGateway
    {
        return new BeanWiredGateway('https://payments.example');
    }
}
