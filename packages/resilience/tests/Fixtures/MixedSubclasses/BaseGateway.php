<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\MixedSubclasses;

use Firefly\Resilience\Method\Retry;

/**
 * A template-method base with TWO post-processed children — the hierarchy no other fixture has, and the one
 * where a covered-methods set unioned over the children told a lie. `InheritingGateway` compiles its own row
 * for `charge()` (a method attribute IS visible through an inherited method), so a union said "covered" and
 * the base's row was dropped as losing nothing; `OverridingGateway` overrides `charge()` without repeating
 * the attribute, compiles no row for it, and its bean therefore ran unguarded with no row, no refusal and no
 * warning. The drop has to hold for EVERY child, so this hierarchy is refused with the sentence that names
 * the override.
 */
class BaseGateway
{
    #[Retry('payments')]
    public function charge(string $account): string
    {
        return $account;
    }
}
