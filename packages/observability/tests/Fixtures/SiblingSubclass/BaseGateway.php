<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\SiblingSubclass;

use Firefly\Observability\Method\Timed;

/** The one annotated class in this directory, and the only one whose author could act on a refusal. */
class BaseGateway
{
    #[Timed('gateway.charge')]
    public function charge(int $cents): int
    {
        return $cents;
    }
}
