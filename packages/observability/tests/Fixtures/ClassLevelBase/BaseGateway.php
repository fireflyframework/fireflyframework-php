<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\ClassLevelBase;

use Firefly\Observability\Method\Timed;

/**
 * The template-method shape again, with the attribute one line higher: at CLASS level on a concrete base that
 * nothing post-processes. This is the half of that shape PHP does NOT carry down — `ReflectionClass::
 * getAttributes()` walks no parents, so the stereotyped child beside this file returns [] for #[Timed] and
 * compiles no row of its own. Dropping this class's rows "because the child already has its own" would
 * therefore compile the attribute into nothing at all, so the scan refuses and says where to move it.
 */
#[Timed('gateway.ops')]
class BaseGateway
{
    public function charge(int $cents): int
    {
        return $cents;
    }
}
