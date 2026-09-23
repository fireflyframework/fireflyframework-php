<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\MixedSubclasses;

use Firefly\Observability\Method\Timed;

/**
 * A template-method base with TWO post-processed children — the hierarchy no other fixture here has, and the
 * one where a covered-methods set unioned over the children told a lie. `InheritingGateway` compiles its own
 * row for `charge()` (a method attribute IS visible through an inherited method), so a union said "covered"
 * and the base's row was dropped as losing nothing; `OverridingGateway` overrides `charge()` without
 * repeating the attribute, compiles no row for it, and its bean was therefore left unmetered with no row, no
 * refusal and no warning. The drop has to hold for EVERY child, so this hierarchy is refused with the
 * sentence that names the override.
 */
class BaseGateway
{
    #[Timed('gateway.charge')]
    public function charge(int $cents): int
    {
        return $cents;
    }
}
