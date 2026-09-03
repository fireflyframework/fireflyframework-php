<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Component;

/**
 * A PLAIN #[Component] that also declares a #[Bean] factory — Spring's "lite
 * mode" @Bean-on-@Component. The short-name gate dropped this bean too, since
 * the stereotype reads 'component', not 'configuration'.
 */
#[Component]
final class StampComponent
{
    #[Bean('inkStamp')]
    public function stamp(): Stamp
    {
        return new Stamp('blue');
    }
}
