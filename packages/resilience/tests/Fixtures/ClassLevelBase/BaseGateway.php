<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\ClassLevelBase;

use Firefly\Resilience\Method\Retry;

/**
 * The template-method shape with the attribute one line higher: at CLASS level on a concrete base that
 * nothing post-processes. This is the half of that shape PHP does NOT carry down — `ReflectionClass::
 * getAttributes()` walks no parents, so the stereotyped child beside this file returns [] for #[Retry] and
 * compiles no row of its own. Dropping this class's rows "because the child already has its own" would
 * therefore compile the guard into nothing at all, so the scan refuses and says where to move it.
 */
#[Retry('payments')]
class BaseGateway
{
    public function charge(string $account): string
    {
        return $account;
    }
}
