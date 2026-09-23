<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\OverriddenBase;

use Firefly\Observability\Method\Timed;

/**
 * A METHOD-level attribute on a concrete base — the shape InheritedBase accepts — except that the stereotyped
 * child OVERRIDES `charge()` without repeating it. An override carries its own, empty, attribute list, so the
 * child compiles no row here either: the same silent loss as the class-level base beside it, reached the
 * other way, and refused the same way.
 */
class BaseGateway
{
    #[Timed('gateway.charge')]
    public function charge(int $cents): int
    {
        return $cents;
    }
}
