<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\FinalMethod;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\Retry;

/**
 * A stereotyped, non-final bean whose GUARDED method is `final`. The proxy the advice needs emits an override
 * for every planned method, so this one would compile into the plan and then fatal with "Cannot override
 * final method" at the `require` of the generated class, naming neither the attribute nor the class. The
 * scan refuses it instead.
 */
#[Service]
class FinalMethodGateway
{
    #[Retry('payments')]
    final public function charge(string $account): string
    {
        return $account;
    }
}
