<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\InheritedBase;

use Firefly\Resilience\Method\Retry;

/**
 * The template-method shape: a CONCRETE base carrying the guard, with the stereotype on the leaf below. The
 * base is not a bean and never will be, but the retry does run — the child exposes `charge()`, so the child
 * compiles its own row for it and the child's proxy overrides the inherited body. Refusing this would
 * hard-fail `firefly:cache` on a legitimate shape while telling its author something untrue about their own
 * wiring.
 */
class BaseGateway
{
    #[Retry('payments')]
    public function charge(string $account): string
    {
        return $account;
    }
}
